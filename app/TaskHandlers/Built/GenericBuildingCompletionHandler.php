<?php

declare(strict_types=1);

namespace App\TaskHandlers\Built;

use App\Attributes\HandlerKey;
use App\Models\ActionLogModel;
use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Models\CharacterFactionModel;
use App\Models\CharacterModel;
use App\Models\CharacterTaskModel;
use App\Models\ClaimedCellModel;
use App\Models\TelegramUserModel;
use App\TaskHandlers\BaseTaskHandler;
use Config\Buildings;

/**
 * F3.B4 — generic-handler завершения постройки любого здания.
 *
 * Заменяет 12 файлов `app/TaskHandlers/Built/BuiltCompletion*Handler.php`
 * (~2500 LOC дубля). Какое именно здание построено — берётся из
 * `task_settings.building` (string) и резолвится через `Config\Buildings`.
 *
 * Действия 1:1 с legacy completion-handlers:
 *   1. mark `character_tasks.status='completed'` (Worker уже это делает в
 *      F0.3 atomic-claim, повтор — no-op).
 *   2. find `buildings.name_en` row.
 *   3. insert/update `character_buildings` (amount++ если уже есть).
 *   4. bump character agility/intellect через `updateAgilityAndIntellect()` —
 *      ТОЛЬКО за первый экземпляр здания этого типа у персонажа (ADR
 *      building-bonus-absorption): вторая и любая следующая копия, на любой
 *      базе, бонус не даёт — он поглощается. Признак «первый» считается ДО
 *      записи шага 3, без фильтра по `map_cell_id`.
 *   5. send Telegram уведомление с photo + caption (Markdown).
 *
 * Recipe-поля из Buildings.php (добавлены в B4):
 *   - `completion_text`           — Markdown-текст уведомления
 *   - `completion_image`          — путь к картинке
 *   - `completion_bonus_agility`  — float, прибавка к ловкости
 *   - `completion_bonus_intellect`— float, прибавка к интеллекту
 *   - `completion_building_type`  — null | string. Если null — берётся
 *     из `buildings.building_type`. Иначе — override (legacy hardcoded
 *     'farming' в 9 handler'ах вопреки реальному typeу здания).
 *
 * УРОК v0.16.1: action-side (`GenericBuildingAction`) уже пишет
 * `task_settings.building`. Контракт согласован 1-в-1 — handler-side читает
 * тот же ключ. F2.2 баг (action писал `quantity`, handler ждал `recipe`)
 * не повторится.
 */
#[HandlerKey(
    key: 'generic_building',
    displayName: 'Универсальное завершение постройки',
    description: 'Завершает любой build*/startBuild* task (recipe из task_settings.building в Config\\Buildings). Покрывает 12 зданий: Workshop/Arsenal/Lab/CommTower/Robotics/Solar/Greenhouse/Gym/Warehouse/Teleport/BlastFurnace/HandPump.',
)]
class GenericBuildingCompletionHandler extends BaseTaskHandler
{
    /** W28 (ADR-083) — рутинное завершение задачи: при активном killswitch уведомление шлётся тихо (disable_notification). */
    protected function isRoutineNotification(): bool
    {
        return true;
    }

    private CharacterModel          $characterModel;
    private CharacterTaskModel      $characterTaskModel;
    private BuildingModel           $buildingModel;
    private CharacterBuildingModel  $characterBuildingModel;
    private CharacterFactionModel   $characterFactionModel;
    private TelegramUserModel       $telegramUserModel;

    public function __construct()
    {
        $this->characterModel          = new CharacterModel();
        $this->characterTaskModel      = new CharacterTaskModel();
        $this->buildingModel           = new BuildingModel();
        $this->characterBuildingModel  = new CharacterBuildingModel();
        $this->characterFactionModel   = new CharacterFactionModel();
        $this->telegramUserModel       = new TelegramUserModel();
    }

    public function handle(array $task = []): void
    {
        // 1. Достаём recipe key из task_settings
        $settings = json_decode($task['task_settings'] ?? '{}', true);
        $buildingKey = $settings['building'] ?? null;
        if ($buildingKey === null || $buildingKey === '') {
            log_message('error', '[GenericBuildingCompletion] task_settings.building не задан, task_id='
                . ($task['id'] ?? '?'));
            return;
        }

        // 2. Резолвим recipe
        /** @var Buildings $cfg */
        $cfg    = config('Buildings');
        $recipe = $cfg->get($buildingKey);
        if ($recipe === null) {
            log_message('error', "[GenericBuildingCompletion] нет рецепта '{$buildingKey}' в Config\\Buildings");
            return;
        }

        // 3. Mark completed (Worker уже это сделал атомарно через F0.3).
        if (!empty($task['id'])) {
            $this->characterTaskModel->update($task['id'], ['status' => 'completed']);
        }

        // 4. Lookup buildings.name_en
        $buildingRow = $this->buildingModel->where('name_en', $buildingKey)->first();
        if (!$buildingRow) {
            log_message('error', "[GenericBuildingCompletion] buildings.name_en='{$buildingKey}' не найдено");
            return;
        }

        // 5. character_buildings insert/update. Возвращает признак «это первый
        //    экземпляр здания этого типа у персонажа» (любая база) — посчитан
        //    ДО записи, используется на шаге 6 для поглощения бонуса дублей.
        $isFirstOfType = $this->updateCharacterBuildings($task, $buildingRow, $recipe);

        // 6. Stats бонусы (character_id из raw task — mixed; нарроуим в int через is_numeric,
        //    чтобы не было краша «string given» при завершении постройки и cast-from-mixed).
        //    Начисляем ТОЛЬКО за первый экземпляр типа — дубль (любая база) бонус поглощает.
        $cidRaw      = $task['character_id'] ?? null;
        $characterId = is_numeric($cidRaw) ? (int) $cidRaw : 0;
        $absorbedBuildingKey = null;
        if ($isFirstOfType) {
            $this->characterModel->updateAgilityAndIntellect(
                $characterId,
                (float) ($recipe['completion_bonus_agility']  ?? 0.0),
                (float) ($recipe['completion_bonus_intellect'] ?? 0.0)
            );
        } else {
            $absorbedBuildingKey = is_string($buildingKey) ? $buildingKey : 'unknown';
            log_message('info', "[GenericBuildingCompletion] бонус поглощён дублем: character_id={$characterId}, building={$absorbedBuildingKey}");
        }

        // 7. Notify. На проде INFO выше не пишется (memory
        //    reference_prod_info_not_logged_monitor_via_markers) — если бонус поглощён,
        //    notifyUser() дополнительно оставит след в action_log (единственный дешёвый
        //    способ увидеть на проде, что ветка сработала).
        $this->notifyUser(
            (int) $task['telegram_user_id'],
            (string) ($recipe['completion_text']  ?? ''),
            (string) ($recipe['completion_image'] ?? ''),
            $absorbedBuildingKey,
            $characterId
        );
    }

    /**
     * @return bool true, если у персонажа ДО этого вызова не было ни одной строки
     *              `character_buildings` с этим `building_id` (на любой базе) —
     *              т.е. это первый экземпляр здания этого типа (building-bonus-absorption).
     */
    private function updateCharacterBuildings(array $task, array $buildingRow, array $recipe): bool
    {
        // Признак «первый экземпляр этого типа у персонажа» — считаем ДО insert/update,
        // без фильтра по map_cell_id (любая база считается тем же типом здания).
        $isFirstOfType = $this->characterBuildingModel
            ->where('character_id', $task['character_id'])
            ->where('building_id', $buildingRow['id'])
            ->countAllResults() === 0;

        $charRow = $this->characterModel->find($task['character_id']);
        if (!$charRow) {
            log_message('error', "[GenericBuildingCompletion] character not found: " . $task['character_id']);
            // Запись character_buildings не состоялась — бонуса за несостоявшуюся
            // постройку быть не должно, каким бы ни был признак «первый экземпляр».
            return false;
        }

        // ADR-102: постройка привязывается к КОНКРЕТНОЙ базе (map_cell_id), а не
        // схлопывается в единственную строку на тип. base_cell зафиксирован при
        // старте (GenericBuildingAction). Fallback для legacy in-flight задач без
        // base_cell — текущая клетка персонажа.
        $fallbackCell = is_numeric($charRow['cell_number'] ?? null) ? (int) $charRow['cell_number'] : 0;
        $targetCell   = $this->resolveBuildCell($task, $fallbackCell);

        $existing = $this->characterBuildingModel
            ->where('character_id', $task['character_id'])
            ->where('building_id', $buildingRow['id'])
            ->where('map_cell_id', $targetCell)
            ->first();

        if ($existing) {
            // Та же постройка на ТОЙ ЖЕ базе — увеличиваем amount (стак на базе).
            $this->characterBuildingModel->update($existing['id'], [
                'amount' => $existing['amount'] + 1,
            ]);
            return $isFirstOfType;
        }

        // Новое здание этого типа на этой базе → отдельная строка.
        $factionRow = $this->characterFactionModel
            ->where('character_id', $task['character_id'])
            ->first();
        $factionId = $factionRow ? $factionRow['faction_id'] : null;

        // building_type: либо override из recipe (legacy hardcoded 'farming'/'engineering'),
        // либо значение из buildings table.
        $buildingType = $recipe['completion_building_type'] ?? $buildingRow['building_type'];

        $this->characterBuildingModel->insert([
            'character_id'                       => $task['character_id'],
            'building_id'                        => $buildingRow['id'],
            'faction_id'                         => $factionId,
            'map_cell_id'                        => $targetCell,
            'amount'                             => 1,
            'character_level_during_construction'=> $charRow['level'],
            'hp'                                 => $buildingRow['hp'],
            'level'                              => 1,
            'built_at'                           => date('Y-m-d H:i:s'),
            'building_type'                      => $buildingType,
            'tax'                                => $buildingRow['tax'],
            'usage'                              => $buildingRow['usage'],
        ]);

        return $isFirstOfType;
    }

    /**
     * ADR-102: клетка базы, к которой привязывается завершённая постройка.
     * Берём из task_settings.base_cell (зафиксировано при старте). Fallback —
     * текущая клетка персонажа (legacy in-flight задачи без base_cell).
     *
     * 🛡 Анти-орфан (релокейшн-гонка, аудит 2026-06-14): `base_cell` фиксируется
     * при СТАРТЕ стройки и НЕ обновляется, если база переехала/снесена, пока стройка
     * шла (релокейшн-хендлер не трогает in-flight build-задачи). Без защиты постройка
     * лендила бы на брошенную клетку = потерянная постройка + orphan-ряд в
     * `character_buildings` (клетка без active claim). Поэтому: если зафиксированной
     * базы больше нет — перенаправляем постройку на актуальную активную базу игрока,
     * чтобы она «догнала» переехавшую базу. Нормальный поток (база на месте) —
     * byte-identical (редирект не срабатывает).
     *
     * @param array<string,mixed> $task
     */
    protected function resolveBuildCell(array $task, int $fallbackCell): int
    {
        $cell = $fallbackCell;
        $raw  = $task['task_settings'] ?? null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && isset($decoded['base_cell']) && is_numeric($decoded['base_cell'])) {
                $cell = (int) $decoded['base_cell'];
            }
        }

        $charId = is_numeric($task['character_id'] ?? null) ? (int) $task['character_id'] : 0;
        if ($charId > 0 && ! $this->cellHasActiveBase($charId, $cell)) {
            $redirect = $this->resolveActiveBaseCell($charId, $fallbackCell);
            if ($redirect > 0 && $redirect !== $cell) {
                log_message('warning', "[GenericBuildingCompletion] base_cell {$cell} больше не активна у char {$charId} — постройка перенаправлена на активную базу {$redirect} (анти-орфан релокейшн-гонки).");
                return $redirect;
            }
        }

        return $cell;
    }

    /**
     * Есть ли у персонажа активная база (claimed_cell) на этой клетке.
     * Свежий ClaimedCellModel на вызов — анти builder-state quirk (memory
     * feedback_ci4_model_builder_state_quirk).
     */
    protected function cellHasActiveBase(int $charId, int $cell): bool
    {
        if ($charId <= 0 || $cell <= 0) {
            return false;
        }

        return (new ClaimedCellModel())
            ->where('character_id', $charId)
            ->where('map_cell_id', $cell)
            ->where('status', 'active')
            ->countAllResults() > 0;
    }

    /**
     * Куда перенаправить постройку, если зафиксированной базы больше нет:
     *  - игрок стоит на своей активной базе (cell_number) → сюда;
     *  - иначе активная база игрока (ровно одна → она; несколько → первая, детерминированно по id);
     *  - нет активных баз → 0 (перенаправлять некуда — оставляем как есть).
     */
    protected function resolveActiveBaseCell(int $charId, int $fallbackCell): int
    {
        if ($this->cellHasActiveBase($charId, $fallbackCell)) {
            return $fallbackCell;
        }

        $bases = (new ClaimedCellModel())
            ->where('character_id', $charId)
            ->where('status', 'active')
            ->orderBy('id', 'ASC')
            ->findColumn('map_cell_id');

        if (is_array($bases) && isset($bases[0]) && is_numeric($bases[0])) {
            return (int) $bases[0];
        }

        return 0;
    }

    /**
     * building-bonus-absorption — след поглощения бонуса в `action_log` (мониторинг на
     * проде идёт по этой таблице, не по INFO-логам, см. `notifyUser()`). Паттерн insert —
     * как `TaxCollectionHandler::logTaxEvent()`: try/catch, сбой форензики не блокирует
     * основной поток.
     *
     * `action_name` — СОБСТВЕННОЕ имя события `BONUS_ABSORBED_<Key>`, а не `BUILD_<Key>`
     * (ревью §3, круг 3): `BUILD_<Key>` уже пишется на КАЖДОЙ обычной постройке
     * (`GenericBuildingAction::logRejected()`), и если использовать то же имя тут — счёт
     * срабатываний ветки поглощения по `action_log` стал бы неотличим от обычных построек
     * (а `description` для подсчёта не годится — свободный varchar). Самый длинный ключ
     * здания (`TeleportationCenter`, 19 символов) + префикс — 34 символа, `action_name`
     * `VARCHAR(255)` (см. `2024-03-18-134951_CreateActionLogTable.php`) — с запасом.
     *
     * `action_status='Completed'` — сверено с ENUM колонки по миграциям (не по коду):
     * `2024-03-18-134951_CreateActionLogTable.php` даёt `('Pending','Completed','Skipped')`,
     * `2026-07-07-100000_ExtendActionLogStatusEnum.php` добавляет `'REJECTED'`. Итоговый
     * набор — `Pending|Completed|Skipped|REJECTED`; событие успешное (постройка состоялась,
     * бонус лишь не начислен) — `Completed` подходит, `REJECTED` здесь не про то (постройка
     * не отклонена).
     *
     * `$chatId` берётся уже разрешённым из `notifyUser()` (та же переменная, что уходит в
     * `safeSendPhoto`) — здесь НЕ повторяем доступ к `$tgUser['telegram_id']` (баланс с
     * baseline-счётчиком phpstan для этого файла).
     *
     * @param int|string $chatId такой же тип, каким его принимает `safeSendPhoto()`.
     */
    private function logBonusAbsorbed(int $characterId, $chatId, string $buildingKey): void
    {
        try {
            (new ActionLogModel())->save([
                'character_id'  => $characterId,
                'chat_id'       => $chatId,
                'action_name'   => "BONUS_ABSORBED_{$buildingKey}",
                'action_status' => 'Completed',
                'description'   => 'Бонус поглощён — у персонажа уже есть здание этого типа (дубль, любая база)',
            ]);
        } catch (\Throwable $e) {
            log_message('error', '[GenericBuildingCompletion::logBonusAbsorbed] insert failed: ' . $e->getMessage());
        }
    }

    /**
     * @param string|null $absorbedBuildingKey building-bonus-absorption — если бонус за
     *                                          эту постройку поглощён дублем, сюда передан
     *                                          её ключ, и notifyUser() рядом с обычной
     *                                          отправкой оставляет след в `action_log`
     *                                          (единственный дешёвый способ увидеть
     *                                          срабатывание ветки на проде).
     */
    private function notifyUser(
        int $telegramUserId,
        string $caption,
        string $imageRelPath,
        ?string $absorbedBuildingKey = null,
        int $characterId = 0
    ): void {
        $tgUser = $this->telegramUserModel->find($telegramUserId);
        if (!$tgUser) {
            log_message('error', "[GenericBuildingCompletion] telegram_user not found: {$telegramUserId}");
            return;
        }

        $chatId = $tgUser['telegram_id'];

        if ($absorbedBuildingKey !== null) {
            $this->logBonusAbsorbed($characterId, $chatId, $absorbedBuildingKey);
        }

        $imagePath = base_url($imageRelPath);
        $this->safeSendPhoto(
            $chatId,
            $imagePath,
            $caption,
            ['parse_mode' => 'Markdown']
        );
    }
}
