<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Craft\Repair;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterResourceModel;
use App\Models\CharacterTaskModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Models\ResourceModel;
use App\Models\TaskModel;
use App\Services\GameSettings\GameSettingsService;
use App\Services\Player\ResourcePoolService;
use Config\CraftRecipes;
use DateTime;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * S5b (v0.51.188) — 2-этапный ремонт изношенного инструмента.
 *
 * 1. `askForRepair()` (callback `repair_{log_id}`): валидация + расчёт стоимости
 *    (template required_resources × `repair.cost_fraction` из `GameSettings`) +
 *    длительность из `repair.task_duration_minutes`. Показывает Confirm.
 * 2. `confirmRepair()` (callback `confirm_repair_{log_id}`): re-validate + deduct
 *    ресурсы + INSERT `character_tasks` (handler='repair', settings={log_id, recipe}).
 *
 * Восстановление durability — в `RepairCompletionHandler` (taskhandler) при
 * срабатывании worker'а через `tasks:run`.
 */
class RepairCraftedItemAction extends BaseAction
{
    // resourceModel, characterTaskModel, taskModel — inherited из BaseAction
    private CraftedItemsLogModel    $logModel;
    private CharacterResourceModel  $characterResourceModel;
    private ResourcePoolService     $resourcePool;
    private GameSettingsService     $settings;
    private CraftRecipes            $recipes;

    public function __construct(\Longman\TelegramBot\Entities\CallbackQuery $callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->logModel               = new CraftedItemsLogModel();
        $this->characterResourceModel = new CharacterResourceModel();
        $this->resourcePool           = new ResourcePoolService();
        // characterTaskModel + taskModel + resourceModel — из BaseAction.
        if (! ($this->characterTaskModel instanceof CharacterTaskModel)) {
            $this->characterTaskModel = new CharacterTaskModel();
        }
        if (! ($this->taskModel instanceof TaskModel)) {
            $this->taskModel = new TaskModel();
        }
        if (! ($this->resourceModel instanceof ResourceModel)) {
            $this->resourceModel = new ResourceModel();
        }
        $this->settings = new GameSettingsService();
        $cfg            = config('CraftRecipes');
        $this->recipes  = $cfg instanceof CraftRecipes ? $cfg : new CraftRecipes();
    }

    public function handle(): ServerResponse
    {
        return $this->askForRepair();
    }

    public function askForRepair(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();

        if (! $user || ! $character) {
            return $this->errReply($chatId, 'Пользователь не найден.');
        }

        $logId = $this->parseLogId($this->callbackQuery->getData(), 'repair_');
        if ($logId <= 0) {
            return $this->errReply($chatId, 'Неверный идентификатор инструмента.');
        }

        $ctx = $this->buildContext($character, $logId);
        if ($ctx === null) {
            return $this->errReply($chatId, 'Инструмент не найден или не нуждается в ремонте.');
        }

        $costFraction = $this->settings->get('repair.cost_fraction', 0.50);
        $duration     = $this->settings->get('repair.task_duration_minutes', 15);

        // ceil(template_resources × cost_fraction).
        $cost     = [];
        $recipe   = $ctx['recipe'];
        $template = is_array($recipe['resources'] ?? null) ? $recipe['resources'] : [];
        $costFracFloat = is_numeric($costFraction) ? (float) $costFraction : 0.50;
        foreach ($template as $resName => $qty) {
            if (! is_string($resName) || ! is_numeric($qty)) {
                continue;
            }
            $cost[$resName] = (int) ceil((float) $qty * $costFracFloat);
        }

        // Проверка наличия ресурсов.
        $missing = $this->checkResourceAvailability($this->extractInt($character, 'id'), $cost);

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        // Машины — «заряд», не «прочность» (канон транспорта, включая дрон Инженеров).
        $icon = $ctx['is_vehicle'] ? '🔋' : '🔧';
        $unit = $ctx['is_vehicle'] ? 'Текущий заряд' : 'Текущая прочность';

        $text  = "{$icon} *Ремонт: {$ctx['name_rus']}*\n\n";
        $text .= "{$unit}: *{$ctx['cur_dur']}/{$ctx['max_dur']}*\n";
        $text .= "Длительность ремонта: *{$duration} мин.*\n\n";
        $text .= "*Необходимые ресурсы* (50% от полного крафта):\n";
        foreach ($cost as $resName => $needQty) {
            $haveQty = $missing['have'][$resName] ?? 0;
            $mark    = $haveQty >= $needQty ? '✅' : '❌';
            $text   .= "{$mark} *{$resName}*: {$needQty} (в наличии {$haveQty})\n";
        }

        if (! empty($missing['short'])) {
            $text .= "\n_Не хватает ресурсов — собери недостающее, прежде чем чинить._";

            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '🛠 NPC-мастер (gold, мгновенно)', 'callback_data' => "npc_repair_{$logId}"]],
                    [
                        ['text' => '⬅️ Назад к списку', 'callback_data' => 'repairToolsList'],
                        ['text' => '🎒 Инвентарь',       'callback_data' => 'inventory'],
                    ],
                ],
            ];
        } else {
            $text .= "\n_Подтверди, чтобы запустить ремонт._";

            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '✅ Подтвердить ремонт', 'callback_data' => "confirm_repair_{$logId}"]],
                    [['text' => '🛠 Или к NPC (gold, мгновенно)', 'callback_data' => "npc_repair_{$logId}"]],
                    [
                        ['text' => '⬅️ Назад к списку', 'callback_data' => 'repairToolsList'],
                        ['text' => '🎒 Инвентарь',       'callback_data' => 'inventory'],
                    ],
                ],
            ];
        }

        return Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    public function confirmRepair(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();

        if (! $user || ! $character) {
            return $this->errReply($chatId, 'Пользователь не найден.');
        }

        $logId = $this->parseLogId($this->callbackQuery->getData(), 'confirm_repair_');
        if ($logId <= 0) {
            return $this->errReply($chatId, 'Неверный идентификатор инструмента.');
        }

        $ctx = $this->buildContext($character, $logId);
        if ($ctx === null) {
            return $this->errReply($chatId, 'Инструмент не найден или уже отремонтирован.');
        }

        // ADR-167: ремонт — 🔒-задача, значит не начинается поверх добычи, готовки
        // или другого ремонта (и наоборот). Флаг берём живой из БД, а не константой,
        // чтобы правка в админке сразу меняла и поведение, и текст отказа.
        $repairTaskRow  = (new \App\Models\TaskModel())->where('handler_key', 'repair')->first();
        $repairParallel = is_array($repairTaskRow) ? ($repairTaskRow['parallel_execution_allowed'] ?? 0) : 0;
        $repairNameRus  = is_array($repairTaskRow) && is_string($repairTaskRow['name_rus'] ?? null) && $repairTaskRow['name_rus'] !== ''
            ? $repairTaskRow['name_rus']
            : 'Ремонт инструмента';
        $conflict = $this->exclusiveConflictText(
            $this->extractInt($character, 'id'),
            $repairParallel,
            $repairNameRus,
        );
        if ($conflict !== null) {
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => $conflict,
                'parse_mode' => 'Markdown',
            ]);
        }

        $costFraction = $this->settings->get('repair.cost_fraction', 0.50);
        $duration     = $this->settings->get('repair.task_duration_minutes', 15);
        $duration     = is_numeric($duration) ? max(1, (int) $duration) : 15;

        // Recompute cost (resource prices not cached cross-step — re-validate).
        $cost     = [];
        $recipe   = $ctx['recipe'];
        $template = is_array($recipe['resources'] ?? null) ? $recipe['resources'] : [];
        $costFracFloat = is_numeric($costFraction) ? (float) $costFraction : 0.50;
        foreach ($template as $resName => $qty) {
            if (! is_string($resName) || ! is_numeric($qty)) {
                continue;
            }
            $cost[$resName] = (int) ceil((float) $qty * $costFracFloat);
        }

        $missing = $this->checkResourceAvailability($this->extractInt($character, 'id'), $cost);
        if (! empty($missing['short'])) {
            return $this->errReply($chatId, 'Ресурсов недостаточно для ремонта — состав изменился.');
        }

        // Поиск repair task_id — до транзакции, чтобы не открывать её ради
        // config-ошибки.
        $repairTask = $this->taskModel->where('handler_key', 'repair')->first();
        if (! is_array($repairTask)) {
            return $this->errReply($chatId, 'Ошибка конфигурации: task `repair` не найден.');
        }

        $repairTaskIdRaw = $repairTask['id'] ?? 0;
        $repairTaskId    = is_numeric($repairTaskIdRaw) ? (int) $repairTaskIdRaw : 0;
        $startTime       = new DateTime('now');
        $endTime         = (clone $startTime)->modify("+{$duration} minutes");

        // Telegram user id из callback context.
        $tgUserId = $this->extractInt($user, 'id');
        $charId   = $this->extractInt($character, 'id');

        $taskSettings = json_encode([
            'tool_log_id' => $logId,
            'recipe'      => $ctx['name_eng'],
        ], JSON_UNESCAPED_UNICODE);

        // story-09 (ревью team-lead): оплата и создание задачи ремонта — теперь
        // ОДНА транзакционная граница (`payAndScheduleRepair()`), а не своя
        // транзакция на списание ресурсов + `insert()` снаружи неё. Раньше
        // провал вставки оставлял игрока без ресурсов (оплата уже закоммичена)
        // и без ремонта. `payAndScheduleRepair()` также смотрит `transStatus()`
        // после `transComplete()` — откат без исключения (сбойный запрос,
        // дедлок, либо чужая более ранняя неудача в этом же request'е под
        // transStrict) раньше не бросал ничего, и код ниже создавал задачу
        // поверх откаченной оплаты.
        try {
            $this->payAndScheduleRepair($charId, $cost, [
                'character_id'      => $charId,
                'telegram_user_id'  => $tgUserId,
                'task_id'           => $repairTaskId,
                'status'            => 'in_work',
                'start_time'        => $startTime->format('Y-m-d H:i:s'),
                'end_time'          => $endTime->format('Y-m-d H:i:s'),
                'task_settings'     => $taskSettings,
                'created_at'        => $startTime->format('Y-m-d H:i:s'),
                'updated_at'        => $startTime->format('Y-m-d H:i:s'),
            ]);
        } catch (\RuntimeException $e) {
            log_message('error', "[RepairCraftedItemAction] оплата ремонта не прошла для character {$charId}: " . $e->getMessage());
            return $this->errReply($chatId, 'Ресурсы разошлись, пока ты выбирал — проверь запас и попробуй ещё раз.');
        }

        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text'              => 'Ремонт начат!',
        ]);

        $endStr = $endTime->format('H:i');

        $scope      = new \App\Services\Tasks\ActionScopeService();
        $background  = $scope->isBackground($repairTask['parallel_execution_allowed'] ?? 0);

        $text   = "🔧 *Ремонт начат*\n\n"
            . $scope->startedBlock(\App\Services\Tasks\ActionScopeService::KIND_CRAFT, $background) . "\n\n"
            . "*{$ctx['name_rus']}* — будет готов в *{$endStr}* (через {$duration} мин.).\n\n"
            . "_Я пришлю уведомление, когда ремонт завершится._";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '⬅️ К списку',  'callback_data' => 'repairToolsList'],
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                ],
            ],
        ];

        return Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    // ── helpers ─────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed>|object $character
     * @return array{log_id:int, name_rus:string, name_eng:string, cur_dur:int, max_dur:int, recipe:array<string,mixed>, is_vehicle:bool}|null
     */
    private function buildContext(array|object $character, int $logId): ?array
    {
        $charId = $this->extractInt($character, 'id');

        $row = $this->logModel
            ->select('crafted_items_log.id AS log_id, crafted_items_log.durability_count AS cur_dur, crafted_items.name_rus, crafted_items.name_eng, crafted_items.durability_count AS max_dur, crafted_items.type')
            ->join('crafted_items', 'crafted_items.id = crafted_items_log.crafted_item_id')
            ->where('crafted_items_log.id', $logId)
            ->where('crafted_items_log.character_id', $charId)
            ->first();

        if (! is_array($row)) {
            return null;
        }
        // Ремонт ревью-находки (2026-08-20, ADR-174): 'transport' допущен наравне
        // с 'tool' — та же механика (ресурсы × repair.cost_fraction), см. докблок
        // RepairToolsListAction про синхронизацию каталога с charges_full.
        $type = $row['type'] ?? null;
        if ($type !== 'tool' && $type !== 'transport') {
            return null;
        }
        $cur = is_numeric($row['cur_dur'] ?? null) ? (int) $row['cur_dur'] : 0;
        $max = is_numeric($row['max_dur'] ?? null) ? (int) $row['max_dur'] : 0;
        if ($max <= 0 || $cur >= $max) {
            return null; // полный durability/заряд — нет смысла ремонтировать
        }

        $nameEng = is_string($row['name_eng'] ?? null) ? $row['name_eng'] : '';
        // Ключ рецепта ≠ crafted_items.name_eng у части инструментов
        // ('Sapper Shovel' → 'SapperShovel'). Резолвим по name_eng.
        $recipe  = $this->recipes->findByItemNameEng($nameEng);
        if ($recipe === null) {
            return null;
        }

        return [
            'log_id'    => $logId,
            'name_rus'  => is_string($row['name_rus'] ?? null) ? $row['name_rus'] : $nameEng,
            'name_eng'  => $nameEng,
            'cur_dur'   => $cur,
            'max_dur'   => $max,
            'recipe'    => $recipe,
            'is_vehicle' => $type === 'transport',
        ];
    }

    /**
     * Резолвит id ВСЕХ ресурсов из `$names` ОДНИМ `whereIn()`-запросом, а не
     * `where()->first()` на переиспользуемой модели внутри цикла — CI4 Model
     * builder state не гарантированно сбрасывается между вызовами в loop'е
     * (см. memory `ci4-model-builder-state-quirk`, живой инцидент S5b: второй
     * ресурс в цикле молча не находился из-за накопленного `AND` в builder'е).
     * `availableByName()`/`consumeByName()` внутри `ResourcePoolService`
     * страдали бы тем же риском при N ≥ 2 ресурсах — поэтому здесь резолвим id
     * сами и дальше зовём id-based `available()`/`consume()`.
     *
     * @param array<int,string> $names
     * @return array<string,int> name => resourceId
     */
    private function resolveResourceIds(array $names): array
    {
        if ($names === []) {
            return [];
        }

        $idByName = [];
        foreach ($this->resourceModel->whereIn('name', $names)->findAll() as $row) {
            $r    = is_object($row) && method_exists($row, 'toArray') ? $row->toArray() : $row;
            $name = is_array($r) && is_string($r['name'] ?? null) ? $r['name'] : null;
            $id   = is_array($r) && is_numeric($r['id'] ?? null) ? (int) $r['id'] : null;
            if ($name !== null && $id !== null) {
                $idByName[$name] = $id;
            }
        }

        return $idByName;
    }

    /**
     * ADR-171: достаточность считается по пулу рюкзак+склад (когда игрок на
     * базе) — иначе отказ по нехватке называл бы карманный остаток, который не
     * сходится с тем, что игрок видит на складе.
     *
     * @param array<string,int> $cost
     * @return array{have:array<string,int>, short:array<string,int>}
     */
    private function checkResourceAvailability(int $characterId, array $cost): array
    {
        $ids   = $this->resolveResourceIds(array_keys($cost));
        $have  = [];
        $short = [];
        foreach ($cost as $resName => $needQty) {
            $resourceId     = $ids[$resName] ?? null;
            $haveQty        = $resourceId !== null ? $this->resourcePool->available($characterId, $resourceId) : 0;
            $have[$resName] = $haveQty;
            if ($haveQty < $needQty) {
                $short[$resName] = $needQty - $haveQty;
            }
        }
        return ['have' => $have, 'short' => $short];
    }

    /**
     * ADR-171: списание через тот же пул, что и проверка достаточности выше —
     * рюкзак сначала, остаток со склада.
     *
     * story-09: транзакционная граница переехала в `payAndScheduleRepair()` —
     * этот метод сам транзакцию больше не открывает/не коммитит, только
     * пробрасывает `RuntimeException` при гонке (checkResourceAvailability
     * подтвердил достаточность секунду назад, но остаток успел уйти), чтобы
     * вызывающий откатил ОБЩУЮ транзакцию целиком (оплата + задача), а не
     * только списание ресурсов.
     *
     * @param array<string,int> $cost
     * @throws \RuntimeException при гонке за тот же остаток
     */
    private function deductResources(int $characterId, array $cost): void
    {
        $ids = $this->resolveResourceIds(array_keys($cost));

        foreach ($cost as $resName => $needQty) {
            if ($needQty < 1) {
                continue;
            }
            $resourceId = $ids[$resName] ?? null;
            if ($resourceId === null) {
                continue; // неизвестный ресурс — молчаливый skip, как и раньше
            }
            $this->resourcePool->consume($characterId, $resourceId, $needQty);
        }
    }

    /**
     * story-09 (ревью team-lead, дефекты 1+2): единая транзакционная граница
     * «оплата + создание задачи ремонта». Раньше `deductResources()` коммитила
     * оплату СВОЕЙ транзакцией, а `character_tasks`-insert шёл снаружи неё —
     * провал вставки оставлял игрока без ресурсов и без ремонта. Теперь оба
     * шага — под одним `transStart()`/`transComplete()`, и исход транзакции
     * проверяется явно: `transStatus()===false` (откат без исключения — сбойный
     * запрос, дедлок, либо чужая более ранняя неудача в этом же request'е под
     * `transStrict`) раньше означал «оплата откачена, а задача создаётся как ни
     * в чём не бывало» — теперь это тоже бросает, и вызывающий отвечает игроку
     * текстом отказа, а не показывает ложный успех.
     *
     * @param array<string,int> $cost
     * @param array<string,mixed> $taskRow строка для `character_tasks->insert()`
     * @throws \RuntimeException на гонку пула ИЛИ на неотслеженный откат транзакции
     */
    private function payAndScheduleRepair(int $characterId, array $cost, array $taskRow): void
    {
        $db = \Config\Database::connect();
        $db->transStart();

        try {
            $this->deductResources($characterId, $cost);
            $this->characterTaskModel->insert($taskRow);
        } catch (\RuntimeException $e) {
            $db->transRollback();
            throw $e;
        }

        $db->transComplete();
        if ($db->transStatus() === false) {
            throw new \RuntimeException('Транзакция ремонта откачена без исключения (оплата + задача).');
        }
    }

    /**
     * F1.4 Entity-or-array helper. character/user могут быть `CharacterEntity`
     * (magic getter) или array (legacy). Возвращает int значение по ключу или 0.
     */
    private function extractInt(mixed $row, string $key): int
    {
        if (is_array($row)) {
            $v = $row[$key] ?? null;
            return is_numeric($v) ? (int) $v : 0;
        }
        if (is_object($row)) {
            $v = $row->{$key} ?? null;
            return is_numeric($v) ? (int) $v : 0;
        }
        return 0;
    }

    private function parseLogId(string $callbackData, string $prefix): int
    {
        if (! str_starts_with($callbackData, $prefix)) {
            return 0;
        }
        $rest = substr($callbackData, strlen($prefix));
        return is_numeric($rest) ? (int) $rest : 0;
    }

    private function errReply(int $chatId, string $msg): ServerResponse
    {
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text'              => $msg,
            'show_alert'        => false,
        ]);
        return Request::sendMessage(['chat_id' => $chatId, 'text' => $msg]);
    }
}
