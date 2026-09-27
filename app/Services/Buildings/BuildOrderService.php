<?php

declare(strict_types=1);

namespace App\Services\Buildings;

use App\Entities\CharacterEntity;
use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Models\CharacterModel;
use App\Models\CharacterResourceModel;
use App\Models\CharacterTaskModel;
use App\Models\ClaimedCellModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Models\ResourceModel;
use App\Models\TaskModel;
use App\Services\Bases\BaseLimitService;
use App\Services\Bases\BaseScopeResolver;
use App\Services\Db\ConditionalWriteService;
use App\Services\Db\WriteOutcome;
use App\Services\Onboarding\BuildLockService;
use App\Services\Onboarding\FirstShelterService;
use App\Services\Tasks\ActionScopeService;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\Buildings;
use Config\Database;
use DateInterval;
use DateTime;

/**
 * w2-n4-base-02 (ADR-190) — ядро стройки без `chat_id`: каталог «🏗 Строить», карточка постройки и старт.
 * Из него рисуют бот (`Camp\BuildListAction`, `Camp\GenericBuildingInfoAction`, `Camp\GenericBuildingAction`)
 * и веб (`/play?view=base`). Гейты и их порядок — прежние, у бота; коды отказа в `action_log` — те же
 * (`BUILD_<Key>`: `leanto_gated`, `not_on_base`, `cell_full`, `low_level`, `missing_deps`, `missing_materials`).
 * Сам лог пишет вызывающий (боту нужен чат) — ядро отдаёт `log`.
 *
 * Строить можно только стоя на своей базе. Явный `baseId` (суффикс `_b<id>` у бота, `b` у веба) —
 * подсказка: {@see BaseScopeResolver::resolveForBase()} обязан подтвердить, что игрок на ней.
 *
 * Атомарность старта (ADR-181): материалы проверяются заранее по снимку, но списываются условной
 * записью в транзакции — ресурсы рюкзака `decrementIfAtLeast`, предметы `decrementIfAtLeast(deleteWhenEmpty)`.
 * Лимит построек базы (возведено + в работе) и «уже строится» (та же постройка на той же базе в работе —
 * `already_building`) перепроверяются под `SELECT … FOR UPDATE` строки персонажа в той же транзакции. Любой отказ посреди — откат целиком: ни задачи, ни частичного списания.
 *
 * Материалы стройки — рюкзак (`character_resources`), как было у бота; склад базы стройка не берёт.
 *
 * @phpstan-type Log array{action: string, reason: string, extra: array<string, mixed>}
 * @phpstan-type Material array{key: string, name: string, id: int, need: int, have: int, in_db: bool}
 * @phpstan-type CatalogItem array{key: string, name: string, tax: int, locked: bool, required_level: int, lock_label: string, built_count: int}
 * @phpstan-type RecipeHead array{name: string, emoji: string, info_text: string, level_required: int, image: string}
 * @phpstan-type Duplicate array{owned: bool, stacks_defense: bool}
 * @phpstan-type Preview array{ok: bool, code: string, key: string, recipe: RecipeHead|null, reason: string, resources: list<Material>, items: list<Material>, missing_deps: list<string>, missing_resources: list<Material>, missing_items: list<Material>, minutes: int|null, max_minutes: int, background: bool, out_of_reach: list<array{name: string, level: int, kind: string}>, duplicate: Duplicate, can_start: bool}
 * @phpstan-type Start array{ok: bool, code: string, message: string, reason: string, log: Log|null, char_task_id: int, ends_at: string|null, minutes: int, background: bool, recipe: RecipeHead|null, duplicate: Duplicate, base_cell: int}
 */
final class BuildOrderService
{
    public const STARTED           = 'started';
    public const PREVIEW           = 'preview';
    public const NO_KEY            = 'no_key';
    public const UNKNOWN           = 'unknown_building';
    public const NO_CHARACTER      = 'no_character';
    public const LEANTO_GATED      = 'leanto_gated';
    public const NO_CAMP           = 'no_camp';
    public const NOT_ON_BASE       = 'not_on_base';
    public const CELL_FULL         = 'cell_full';
    public const ALREADY_BUILDING  = 'already_building';
    public const LOW_LEVEL         = 'low_level';
    public const MISSING_DEPS      = 'missing_deps';
    public const NO_TASK           = 'no_task';
    public const MISSING_MATERIALS = 'missing_materials';
    public const RACE              = 'race';
    public const TX_FAILED         = 'tx_failed';

    public const TEXT_NOT_ON_BASE = 'Ты не на своей базе. Постройки возводятся только когда стоишь на базе — телепортируйся или дойди до неё.';
    public const TEXT_RACE        = 'Материалы разошлись, пока ты нажимал — проверь запас и попробуй ещё раз.';
    public const TEXT_TX_FAILED   = 'Ошибка при создании задачи строительства. Попробуйте ещё раз.';

    /**
     * Каталог «🏗 Строить» — порядок и налог строки те же, что были в `BuildListAction` (подпись с эмодзи).
     *
     * @var list<array{key: string, name: string, tax: int}>
     */
    public const CATALOG = [
        ['key' => 'HandPump', 'name' => '🚰 Ручная скважина', 'tax' => 300],
        ['key' => 'BlastFurnace', 'name' => '🔥 Доменная печь', 'tax' => 450],
        ['key' => 'Warehouse', 'name' => '🏚️ Склад', 'tax' => 900],
        ['key' => 'Workshop', 'name' => '🔧 Мастерская', 'tax' => 500],
        ['key' => 'Greenhouse', 'name' => '🌱 Теплица', 'tax' => 840],
        ['key' => 'SolarStation', 'name' => '☀️ Солнечная станция', 'tax' => 760],
        ['key' => 'Gym', 'name' => '🥊 Спортзал', 'tax' => 900],
        ['key' => 'Laboratory', 'name' => '🥼 Лаборатория', 'tax' => 860],
        ['key' => 'RoboticsWorkshop', 'name' => '🤖 Мастерская робототехники', 'tax' => 1400],
        ['key' => 'TeleportationCenter', 'name' => '🌀 Центр телепортации', 'tax' => 820],
        ['key' => 'Arsenal', 'name' => '⚔️ Арсенал', 'tax' => 2000],
        ['key' => 'CommunicationTower', 'name' => '📢 Вышка связи', 'tax' => 1300],
        // S26 (ADR-030) — оборона базы; S26b (ADR-031) — Дозорная вышка.
        ['key' => 'WoodenWall', 'name' => '🪵 Деревянная стена', 'tax' => 200],
        ['key' => 'BarbedFence', 'name' => '🌵 Колючая ограда', 'tax' => 350],
        ['key' => 'WatchTower', 'name' => '🗼 Дозорная вышка', 'tax' => 700],
    ];

    private const INFO_PREFIX = 'genericBuildInfo_';

    private Buildings $config;

    public function __construct(?Buildings $config = null)
    {
        $this->config = $config ?? config(Buildings::class);
    }

    /**
     * Каталог «🏗 Строить»: Навес новичку первым (S5, ADR-142), уровневые постройки — замком (S4, ADR-139).
     * `built_count` — сколько построек этого типа уже стоит на базе `$baseId` (0 без базы).
     *
     * @return array{items: list<CatalogItem>}
     */
    public function catalog(int $characterId, ?int $baseId = null): array
    {
        $character = $this->character($characterId);
        $level     = self::int($character['level'] ?? 0);
        $entries   = self::CATALOG;

        $shelter = new FirstShelterService();
        if ($shelter->shouldOffer($characterId, $level)) {
            $entry = $shelter->buttonEntry();
            array_unshift($entries, [
                'key'  => substr($entry['callback_data'], strlen(self::INFO_PREFIX)),
                'name' => $entry['name'],
                'tax'  => $entry['tax'],
            ]);
        }

        $built = $baseId !== null ? $this->builtCountsAtBase($characterId, $baseId) : [];
        $locks = new BuildLockService();
        $items = [];
        foreach ($entries as $entry) {
            $locked  = $locks->isLocked($entry['key'], $level);
            $items[] = [
                'key'            => $entry['key'],
                'name'           => $entry['name'],
                'tax'            => $entry['tax'],
                'locked'         => $locked,
                'required_level' => $locked ? $locks->requiredLevel($entry['key']) : 0,
                'lock_label'     => $locked ? $locks->lockLabel($entry['key'], $entry['name']) : '',
                'built_count'    => $built[$entry['key']] ?? 0,
            ];
        }

        return ['items' => $items];
    }

    /**
     * Карточка постройки: что нужно, что есть, время, описание, чего не хватает. Код `preview` —
     * карточка показывается (хватает или нет — `can_start`); иначе — отказ экрана (`leanto_gated`,
     * `no_camp`, `not_on_base`, `low_level`, `unknown_building`, `no_character`).
     *
     * @return Preview
     */
    public function preview(int $characterId, ?int $baseId, string $key): array
    {
        $empty = [
            'ok' => false, 'code' => '', 'key' => $key, 'recipe' => null, 'reason' => '', 'resources' => [], 'items' => [],
            'missing_deps' => [], 'missing_resources' => [], 'missing_items' => [], 'minutes' => null, 'max_minutes' => 0,
            'background' => true, 'out_of_reach' => [], 'duplicate' => ['owned' => false, 'stacks_defense' => false], 'can_start' => false,
        ];
        $recipe = $key !== '' ? $this->config->get($key) : null;
        if ($recipe === null) {
            return ['code' => $key === '' ? self::NO_KEY : self::UNKNOWN] + $empty;
        }
        $character = $this->character($characterId);
        if ($character === []) {
            return ['code' => self::NO_CHARACTER] + $empty;
        }
        $empty['recipe'] = self::recipeHead($recipe);
        $level           = self::int($character['level'] ?? 0);

        $gateWhy = (new FirstShelterService())->leanToGateReason($key, $characterId, $level);
        if ($gateWhy !== null) {
            return ['code' => self::LEANTO_GATED, 'reason' => $gateWhy] + $empty;
        }
        if ((new ClaimedCellModel())->where('character_id', $characterId)->findAll() === []) {
            return ['code' => self::NO_CAMP] + $empty;
        }
        if ($this->baseUnderFeet($characterId, self::int($character['cell_number'] ?? 0), $baseId) === null) {
            return ['code' => self::NOT_ON_BASE] + $empty;
        }
        if ($level < $recipe['level_required']) {
            return ['code' => self::LOW_LEVEL] + $empty;
        }

        $resources   = $this->resourceRows($characterId, $recipe['resources']);
        $items       = $this->itemRows($characterId, $recipe['crafted_items']);
        $missingDeps = $this->missingDependencies($characterId, $recipe['dependencies']);
        $missRes     = array_values(array_filter($resources, static fn (array $r): bool => $r['have'] < $r['need']));
        $missItems   = array_values(array_filter($items, static fn (array $r): bool => $r['have'] < $r['need']));
        $shortage    = $missingDeps !== [] || $missRes !== [] || $missItems !== [];

        $taskRow  = $this->taskRow($recipe['task_name']);
        $minutes  = (new BuildDurationService())->minutes($character, $taskRow);
        $maxRaw   = $taskRow['max_duration'] ?? null;

        return [
            'ok'                => true,
            'code'              => self::PREVIEW,
            'key'               => $key,
            'recipe'            => $empty['recipe'],
            'reason'            => '',
            'resources'         => $resources,
            'items'             => $items,
            'missing_deps'      => $missingDeps,
            'missing_resources' => $missRes,
            'missing_items'     => $missItems,
            'minutes'           => $minutes,
            'max_minutes'       => is_numeric($maxRaw) ? (int) $maxRaw : 0,
            'background'        => (new ActionScopeService())->isBackground($taskRow['parallel_execution_allowed'] ?? 1),
            'out_of_reach'      => $shortage ? (new BuildingGateService())->outOfReachMaterials($key, $level > 0 ? $level : 1) : [],
            'duplicate'         => $this->duplicate($characterId, $key),
            'can_start'         => ! $shortage,
        ];
    }

    /**
     * Старт стройки. Успех — `code` = `started`, `char_task_id`, `ends_at`, `minutes`, `background`,
     * `duplicate` (у персонажа уже есть такое здание — оговорка на экране старта).
     *
     * @return Start
     */
    public function start(int $characterId, ?int $baseId, string $key): array
    {

        if ($key === '') {
            return self::fail(self::NO_KEY, 'Не указан тип здания.');
        }
        $recipe = $this->config->get($key);
        if ($recipe === null) {
            return self::fail(self::UNKNOWN, "Неизвестное здание: {$key}");
        }
        $character = $this->character($characterId);
        if ($character === []) {
            return self::fail(self::NO_CHARACTER, 'Персонаж не найден. Попробуйте /start.');
        }
        $action = "BUILD_{$key}";
        $level  = self::int($character['level'] ?? 0);

        $gateWhy = (new FirstShelterService())->leanToGateReason($key, $characterId, $level);
        if ($gateWhy !== null) {
            return self::fail(self::LEANTO_GATED, BuildingCopyNotice::leanToGateExplanation($gateWhy), self::log($action, 'leanto_gated', ['reason' => $gateWhy]), $gateWhy);
        }

        $cell = $this->baseUnderFeet($characterId, self::int($character['cell_number'] ?? 0), $baseId);
        if ($cell === null) {
            return self::fail(self::NOT_ON_BASE, self::TEXT_NOT_ON_BASE, self::log($action, 'not_on_base'));
        }

        $maxPerCell = (new BaseLimitService())->maxBuildingsPerCell();
        $load       = $this->cellLoad($characterId, $cell);
        if ($load['built'] + $load['inflight'] >= $maxPerCell) {
            return self::fail(self::CELL_FULL, self::cellFullText($maxPerCell, $load), self::log($action, 'cell_full', $load + ['max' => $maxPerCell]));
        }

        if ($level < $recipe['level_required']) {
            return self::fail(
                self::LOW_LEVEL,
                "Нужен уровень не ниже *{$recipe['level_required']}* для постройки {$recipe['name_rus']}.",
                self::log($action, 'low_level', ['required' => $recipe['level_required'], 'have' => $character['level'] ?? null])
            );
        }

        $missingDeps = $this->missingDependencies($characterId, $recipe['dependencies']);
        if ($missingDeps !== []) {
            return self::fail(self::MISSING_DEPS, 'Сначала постройте: ' . implode(', ', $missingDeps), self::log($action, 'missing_deps', ['missing' => $missingDeps]));
        }

        $taskRow = $this->taskRow($recipe['task_name']);
        if ($taskRow === null) {
            return self::fail(self::NO_TASK, "Задача '{$recipe['task_name']}' не найдена в таблице tasks.");
        }

        $resources = $this->resourceRows($characterId, $recipe['resources']);
        $items     = $this->itemRows($characterId, $recipe['crafted_items']);
        $missRes   = self::missingMap(array_filter($resources, static fn (array $r): bool => $r['have'] < $r['need']));
        $missItems = self::missingMap(array_filter($items, static fn (array $r): bool => $r['have'] < $r['need']));
        if ($missRes !== [] || $missItems !== []) {
            return self::fail(
                self::MISSING_MATERIALS,
                "Не хватает материалов для {$recipe['name_rus']}:\n\n" . self::formatMissing($missRes, $missItems),
                self::log($action, 'missing_materials', ['missing_resources' => $missRes, 'missing_items' => $missItems])
            );
        }

        $minutes   = (new BuildDurationService())->minutes($character, $taskRow) ?? 0;
        $startTime = new DateTime();
        $endTime   = (clone $startTime)->add(new DateInterval("PT{$minutes}M"));
        // ADR-102: база-цель — та, на которой стоим; completion-handler привяжет здание к ней.
        $settings = array_merge($recipe['task_settings'], ['base_cell' => $cell]);

        $db = Database::connect();
        $db->transBegin();
        try {
            // Лимит базы — под блокировкой строки персонажа: два параллельных старта проходят проверку
            // выше каждый по своему снимку, но вставляют строго по очереди и видят задачу соседа.
            $db->query('SELECT id FROM characters WHERE id = ? FOR UPDATE', [$characterId]);
            $load = $this->cellLoad($characterId, $cell);
            if ($load['built'] + $load['inflight'] >= $maxPerCell) {
                $db->transRollback();

                return self::fail(self::CELL_FULL, self::cellFullText($maxPerCell, $load), self::log($action, 'cell_full', $load + ['max' => $maxPerCell]));
            }
            // «Уже строится» (ask 4) — та же постройка на той же базе ещё в работе: второе нажатие бота или второй
            // клиент при запасе на две стройки не ставят вторую задачу. Под той же блокировкой — сосед уже вставил.
            if ($this->alreadyBuilding($characterId, self::int($taskRow['id'] ?? 0), $cell)) {
                $db->transRollback();

                return self::fail(
                    self::ALREADY_BUILDING,
                    "*{$recipe['name_rus']}* уже строится на этой базе — дождись окончания стройки.",
                    self::log($action, 'already_building', ['base_cell' => $cell])
                );
            }

            $this->consume(new ConditionalWriteService($db), $characterId, $resources, $items);

            $tasks = new CharacterTaskModel();
            $tasks->insert([
                'character_id'     => $characterId,
                'telegram_user_id' => self::int($character['telegram_user_id'] ?? 0),
                'task_id'          => self::int($taskRow['id'] ?? 0),
                'start_time'       => $startTime->format('Y-m-d H:i:s'),
                'end_time'         => $endTime->format('Y-m-d H:i:s'),
                'status'           => 'in_work',
                'task_settings'    => json_encode($settings),
            ]);
            $taskId = (int) $tasks->getInsertID();

            if ($db->transStatus() === false) {
                $db->transRollback();
                log_message('error', "[BuildOrderService:{$key}] транзакция упала для character {$characterId}");

                return self::fail(self::TX_FAILED, self::TEXT_TX_FAILED);
            }
            $db->transCommit();
        } catch (DatabaseException $e) {
            $db->transRollback();
            log_message('error', "[BuildOrderService:{$key}] транзакция упала для character {$characterId}: " . $e->getMessage());

            return self::fail(self::TX_FAILED, self::TEXT_TX_FAILED);
        } catch (\RuntimeException $e) {
            // Параллельный запрос успел забрать тот же остаток — откат целиком, задачи нет.
            $db->transRollback();
            log_message('error', "[BuildOrderService:{$key}] гонка при списании для character {$characterId}: " . $e->getMessage());

            return self::fail(self::RACE, self::TEXT_RACE, self::log($action, 'race'));
        }

        return [
            'ok'           => true,
            'code'         => self::STARTED,
            'message'      => '',
            'reason'       => '',
            'log'          => null,
            'char_task_id' => $taskId,
            'ends_at'      => $endTime->format('Y-m-d H:i:s'),
            'minutes'      => $minutes,
            'background'   => (new ActionScopeService())->isBackground($taskRow['parallel_execution_allowed'] ?? 1),
            'recipe'       => self::recipeHead($recipe),
            'duplicate'    => $this->duplicate($characterId, $key),
            'base_cell'    => $cell,
        ];
    }

    // ── внутреннее ───────────────────────────────────────────────────────────

    /**
     * Клетка базы, на которой игрок стоит, или null. С явным `$baseId` — только если это она
     * и {@see BaseScopeResolver::resolveForBase()} подтверждает «на базе».
     */
    private function baseUnderFeet(int $characterId, int $currentCell, ?int $baseId): ?int
    {
        if ($baseId !== null) {
            $scope = (new BaseScopeResolver())->resolveForBase($characterId, $currentCell, $baseId);

            return $scope['reason'] === BaseScopeResolver::REASON_ON_BASE ? $scope['cell'] : null;
        }

        return (new ClaimedCellModel())->findActiveCell($characterId, $currentCell) !== null ? $currentCell : null;
    }

    /** @return array{built: int, inflight: int} */
    private function cellLoad(int $characterId, int $cell): array
    {
        $built    = (new CharacterBuildingModel())->where('character_id', $characterId)->where('map_cell_id', $cell)->countAllResults();
        $inflight = Database::connect()->table('character_tasks ct')
            ->join('tasks t', 't.id = ct.task_id')
            ->where('ct.character_id', $characterId)
            ->whereIn('ct.status', ['in_work', 'queued'])
            ->where('t.handler_key', 'generic_building')
            ->countAllResults();

        return ['built' => is_numeric($built) ? (int) $built : 0, 'inflight' => is_numeric($inflight) ? (int) $inflight : 0];
    }

    /**
     * Идёт ли уже стройка этой постройки (та же задача) на этой базе: `in_work`/`queued` с `task_settings.base_cell`
     * этой клетки. JSON разбирается здесь, а не в SQL — у старых строк бывает не-JSON.
     */
    private function alreadyBuilding(int $characterId, int $taskId, int $cell): bool
    {
        $rows = Database::connect()->table('character_tasks')
            ->select('task_settings')
            ->where('character_id', $characterId)
            ->where('task_id', $taskId)
            ->whereIn('status', ['in_work', 'queued'])
            ->get();
        foreach ($rows !== false ? $rows->getResultArray() : [] as $row) {
            $settings = is_string($row['task_settings'] ?? null) ? json_decode($row['task_settings'], true) : null;
            if (is_array($settings) && self::int($settings['base_cell'] ?? 0) === $cell) {
                return true;
            }
        }

        return false;
    }

    /** @param array{built: int, inflight: int} $load */
    private static function cellFullText(int $max, array $load): string
    {
        return "На этой базе уже максимум построек (*{$max}*): возведено {$load['built']}, в работе {$load['inflight']}. Снеси что-нибудь или развивай другую базу.";
    }

    /**
     * Условное списание (ADR-181): строка рюкзака/предмета должна нести не меньше нужного в момент
     * записи, иначе `RuntimeException` — вызывающий откатывает транзакцию.
     *
     * @param list<Material> $resources
     * @param list<Material> $items
     * @throws \RuntimeException
     */
    private function consume(ConditionalWriteService $writer, int $characterId, array $resources, array $items): void
    {
        foreach ($resources as $r) {
            if ($r['need'] < 1 || ! $r['in_db']) {
                continue;
            }
            $row = (new CharacterResourceModel())->where('id_characters', $characterId)->where('id_resources', $r['id'])->first();
            $rowId = self::int(self::arr($row)['id'] ?? 0);
            if ($rowId < 1 || $writer->decrementIfAtLeast('character_resources', $rowId, 'quantity', $r['need']) !== WriteOutcome::Applied) {
                throw new \RuntimeException("ресурс {$r['key']}: не хватило {$r['need']} при списании");
            }
        }
        foreach ($items as $i) {
            if ($i['need'] < 1 || ! $i['in_db']) {
                continue;
            }
            $log = (new CraftedItemsLogModel())->where('character_id', $characterId)->where('crafted_item_id', $i['id'])->first();
            $logId = self::int(self::arr($log)['id'] ?? 0);
            if ($logId < 1 || $writer->decrementIfAtLeast('crafted_items_log', $logId, 'quantity', $i['need'], true) !== WriteOutcome::Applied) {
                throw new \RuntimeException("предмет {$i['key']}: не хватило {$i['need']} при списании");
            }
        }
    }

    /**
     * @param array<string, int> $reqs name_en → нужно
     * @return list<Material>
     */
    private function resourceRows(int $characterId, array $reqs): array
    {
        $out = [];
        foreach ($reqs as $key => $need) {
            $row = self::arr((new ResourceModel())->getResourceByNameEn($key));
            $id  = self::int($row['id'] ?? 0);
            if ($id < 1) {
                $out[] = ['key' => $key, 'name' => $key, 'id' => 0, 'need' => (int) $need, 'have' => 0, 'in_db' => false];
                continue;
            }
            $have  = self::arr((new CharacterResourceModel())->where('id_characters', $characterId)->where('id_resources', $id)->first());
            $out[] = [
                'key'   => $key,
                'name'  => is_string($row['name'] ?? null) ? $row['name'] : $key,
                'id'    => $id,
                'need'  => (int) $need,
                'have'  => self::int($have['quantity'] ?? 0),
                'in_db' => true,
            ];
        }

        return $out;
    }

    /**
     * @param array<string, int> $reqs name_eng → нужно
     * @return list<Material>
     */
    private function itemRows(int $characterId, array $reqs): array
    {
        $out = [];
        foreach ($reqs as $key => $need) {
            $item = self::arr((new CraftedItemsModel())->getRowByName($key));
            $id   = self::int($item['id'] ?? 0);
            if ($id < 1) {
                $out[] = ['key' => $key, 'name' => $key, 'id' => 0, 'need' => (int) $need, 'have' => 0, 'in_db' => false];
                continue;
            }
            $log   = self::arr((new CraftedItemsLogModel())->where('character_id', $characterId)->where('crafted_item_id', $id)->first());
            $out[] = [
                'key'   => $key,
                'name'  => is_string($item['name_rus'] ?? null) ? $item['name_rus'] : $key,
                'id'    => $id,
                'need'  => (int) $need,
                'have'  => self::int($log['quantity'] ?? 0),
                'in_db' => true,
            ];
        }

        return $out;
    }

    /**
     * @param list<string> $deps name_en зданий
     * @return list<string> русские имена непостроенных (неизвестное в справочнике — ключом)
     */
    private function missingDependencies(int $characterId, array $deps): array
    {
        $missing = [];
        foreach ($deps as $dep) {
            $bld = self::arr((new BuildingModel())->where('name_en', $dep)->first());
            $id  = self::int($bld['id'] ?? 0);
            if ($id < 1) {
                $missing[] = $dep;
                continue;
            }
            $owns = (new CharacterBuildingModel())->where('character_id', $characterId)->where('building_id', $id)->countAllResults();
            if ($owns === 0) {
                $missing[] = BuildingModel::rusName($bld, $dep);
            }
        }

        return $missing;
    }

    /**
     * Уже есть здание этого типа (на любой своей базе) и стакается ли его оборона (стена/ограда).
     *
     * @return array{owned: bool, stacks_defense: bool}
     */
    private function duplicate(int $characterId, string $key): array
    {
        $bld = self::arr((new BuildingModel())->where('name_en', $key)->first());
        $id  = self::int($bld['id'] ?? 0);
        if ($id < 1) {
            return ['owned' => false, 'stacks_defense' => false];
        }
        $owned = (new CharacterBuildingModel())->where('character_id', $characterId)->where('building_id', $id)->countAllResults() > 0;

        return ['owned' => $owned, 'stacks_defense' => ($bld['building_type'] ?? '') === 'defensive' && $key !== 'WatchTower'];
    }

    /** @return array<string, int> name_en → сколько штук уже стоит на базе */
    private function builtCountsAtBase(int $characterId, int $baseId): array
    {
        $base = self::arr((new ClaimedCellModel())->find($baseId));
        if (self::int($base['character_id'] ?? 0) !== $characterId) {
            return [];
        }
        $rows = Database::connect()->table('character_buildings cb')
            ->select('b.name_en, SUM(COALESCE(cb.amount, 1)) AS n')
            ->join('buildings b', 'b.id = cb.building_id')
            ->where('cb.character_id', $characterId)
            ->where('cb.map_cell_id', self::int($base['map_cell_id'] ?? 0))
            ->groupBy('b.name_en')
            ->get();
        $out = [];
        foreach ($rows !== false ? $rows->getResultArray() : [] as $row) {
            if (is_string($row['name_en'] ?? null)) {
                $out[$row['name_en']] = self::int($row['n'] ?? 0);
            }
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    private function taskRow(string $taskName): ?array
    {
        $row = (new TaskModel())->where('name', $taskName)->first();

        return $row === null ? null : self::arr($row);
    }

    /** @return array<string, mixed> */
    private function character(int $characterId): array
    {
        return self::arr((new CharacterModel())->find($characterId));
    }

    /**
     * @param array{name_rus: string, emoji: string, info_text: string, level_required: int, image_in_progress: string} $recipe
     * @return RecipeHead
     */
    private static function recipeHead(array $recipe): array
    {
        return [
            'name'           => $recipe['name_rus'],
            'emoji'          => $recipe['emoji'],
            'info_text'      => $recipe['info_text'],
            'level_required' => $recipe['level_required'],
            'image'          => $recipe['image_in_progress'],
        ];
    }

    /**
     * @param array<array-key, Material> $rows
     * @return array<string, array{need: int, have: int, name: string}>
     */
    private static function missingMap(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[$r['key']] = ['need' => $r['need'], 'have' => $r['have'], 'name' => $r['in_db'] ? $r['name'] : $r['key'] . ' (нет в DB)'];
        }

        return $out;
    }

    /**
     * @param array<string, array{need: int, have: int, name: string}> $missRes
     * @param array<string, array{need: int, have: int, name: string}> $missItems
     */
    private static function formatMissing(array $missRes, array $missItems): string
    {
        $lines = [];
        foreach ([...array_values($missRes), ...array_values($missItems)] as $info) {
            $lines[] = "• {$info['name']}: нужно {$info['need']}, есть {$info['have']}";
        }

        return implode("\n", $lines);
    }

    /**
     * @param Log|null $log
     * @return Start
     */
    private static function fail(string $code, string $message, ?array $log = null, string $reason = ''): array
    {
        return [
            'ok' => false, 'code' => $code, 'message' => $message, 'reason' => $reason, 'log' => $log, 'char_task_id' => 0,
            'ends_at' => null, 'minutes' => 0, 'background' => true, 'recipe' => null,
            'duplicate' => ['owned' => false, 'stacks_defense' => false], 'base_cell' => 0,
        ];
    }

    /**
     * @param array<string, mixed> $extra
     * @return Log
     */
    private static function log(string $action, string $reason, array $extra = []): array
    {
        return ['action' => $action, 'reason' => $reason, 'extra' => $extra];
    }

    /** @return array<string, mixed> */
    private static function arr(mixed $row): array
    {
        if ($row instanceof CharacterEntity || (is_object($row) && method_exists($row, 'toArray'))) {
            $row = $row->toArray();
        }
        if (! is_array($row)) {
            return [];
        }
        $out = [];
        foreach ($row as $k => $v) {
            $out[(string) $k] = $v;
        }

        return $out;
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
