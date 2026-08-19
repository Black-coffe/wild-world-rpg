<?php

namespace App\TaskHandlers;

use App\Attributes\HandlerKey;
use App\Models\CharacterModel;
use App\Models\CharacterTaskModel;
use App\Models\ExploredCellsModel;
use App\Services\Player\PlayerDetectionService;
use App\Services\Player\Progression\EarlyProgressionService;
use App\Services\PVE\TowerAlertService;
use App\Services\World\MarchPaceService;
use App\Services\World\ObjectDiscoveryService;
use App\Services\World\ObjectSignalService;
use App\Services\World\TextMapService;
use CodeIgniter\Database\BaseResult;
use Config\Database;
use App\Services\Telegram\Request;

/**
 * ADR-019 Step 3 — «Поход»: движение И есть разведка.
 *
 * Реализация = **цепочка 1-клеточных задач** (Option A, ADR-019 §C.4): каждая
 * `Marching`-задача в `character_tasks` = один шаг. `Worker::processTasks()`
 * подбирает задачу когда `end_time` прошёл → атомарно метит `completed` (это и
 * есть idempotency-guard, уже battle-tested) → вызывает `handle($task)`. Handler
 * продвигает персонажа на 1 клетку, раскрывает 3×3 (туман войны, `revealAround`),
 * затем либо спавнит следующую `Marching`-задачу (`end_time = now + minutes_per_cell`),
 * либо завершает поход (`finishMarch`), либо ставит на паузу (`pauseMarch`, новый
 * row со `status='paused'`).
 *
 * `task_settings` (JSON):
 *   heading        — 'north'|'south'|'west'|'east'|'northwest'|'northeast'|'southwest'|'southeast'
 *   steps_planned  — сколько клеток в этом заказе
 *   steps_done     — сколько уже пройдено
 *   started_cell   — cell_number старта (для лога)
 *   msg_chat_id    — telegram chat_id сообщения марша (для edit-in-place)
 *   msg_id         — message_id сообщения марша (может быть null → fallback на новое)
 *   acc            — {xp, hp, tired, stat} накопленные дельты за весь поход (финал-сводка)
 *   log            — массив строк событий в пути (находки/стычки) для финал-сводки
 *   steps_remaining / paused_reason — только в paused-row (для resume)
 *
 * Прерывания (ADR-019 §4):
 *   ЖЁСТКАЯ ОСТАНОВКА (finishMarch): край мира / вода / чужой активный лагерь на
 *     след. клетке / пол выносливости.
 *   ПАУЗА (pauseMarch): обнаружен игрок → промпт «атаковать/бежать/идти дальше».
 *   (PvE-стычки, опасные событийные зоны, квест-триггеры — extension points,
 *    гейтятся живым ключом npc.march_encounter_chance (по умолчанию 0); ADR-019 §6.)
 */
#[HandlerKey(
    key: 'marching',
    displayName: 'Поход (ADR-019)',
    description: 'Цепочка 1-клеточных задач: движение И есть разведка. Раскрывает 3×3 каждый шаг, пауза при встрече игрока.',
)]
class MarchingTaskHandler extends BaseTaskHandler
{
    use \App\Services\GameSettings\GameSettingsReaderTrait;

    private PlayerDetectionService $detector;
    private TowerAlertService $towerAlerts;
    private ObjectSignalService $objectSignal;
    private \App\Services\World\MarchMiniEventService $miniEvents;

    /** @var array<string, array{int,int}> dx,dy по направлениям (y растёт на юг). */
    private const DIRECTIONS = [
        'north'     => [0, -1],
        'south'     => [0, 1],
        'west'      => [-1, 0],
        'east'      => [1, 0],
        'northwest' => [-1, -1],
        'northeast' => [1, -1],
        'southwest' => [-1, 1],
        'southeast' => [1, 1],
    ];

    /** @var array<string, string> человекочитаемые названия направлений. */
    private const DIR_LABEL = [
        'north'     => '⬆️ север',
        'south'     => '⬇️ юг',
        'west'      => '⬅️ запад',
        'east'      => '➡️ восток',
        'northwest' => '↖️ северо-запад',
        'northeast' => '↗️ северо-восток',
        'southwest' => '↙️ юго-запад',
        'southeast' => '↘️ юго-восток',
    ];

    /** Исходы {@see advanceOneCell()}: продолжать / жёсткий стоп / пауза / план выполнен. */
    private const CELL_CONTINUE = 'continue';
    private const CELL_STOPPED  = 'stopped';
    private const CELL_PAUSED   = 'paused';
    private const CELL_DONE     = 'done';

    public function __construct(?PlayerDetectionService $detector = null, ?TowerAlertService $towerAlerts = null, ?ObjectSignalService $objectSignal = null, ?\App\Services\World\MarchMiniEventService $miniEvents = null)
    {
        $this->detector     = $detector ?? new PlayerDetectionService();
        $this->towerAlerts  = $towerAlerts ?? new TowerAlertService();
        $this->objectSignal = $objectSignal ?? new ObjectSignalService();
        $this->miniEvents   = $miniEvents ?? new \App\Services\World\MarchMiniEventService();
    }

    /**
     * @param array<int|string, mixed> $task — запись из character_tasks (уже status='completed'
     *                                    по atomic-claim в Worker).
     */
    public function handle(array $task = []): void
    {
        $taskId         = $this->asInt($task['id'] ?? 0);
        $characterId    = $this->asInt($task['character_id'] ?? 0);
        $telegramUserId = $this->asInt($task['telegram_user_id'] ?? 0);
        if ($characterId <= 0) {
            log_message('error', '[MarchingTaskHandler] task без character_id, #' . $taskId);
            return;
        }

        $decoded = json_decode($this->asStr($task['task_settings'] ?? '{}', '{}'), true);
        $s       = is_array($decoded) ? $decoded : [];
        $heading = $this->asStr($s['heading'] ?? '');
        if (!isset(self::DIRECTIONS[$heading])) {
            log_message('error', '[MarchingTaskHandler] неизвестное направление: ' . $heading);
            return;
        }
        $stepsPlanned = max(1, $this->asInt($s['steps_planned'] ?? 1));

        $character = $this->fetchCharacter($characterId);
        if ($character === null || $this->asInt($character['cell_number'] ?? 0) <= 0) {
            log_message('error', '[MarchingTaskHandler] персонаж не найден / без cell_number, task #' . $taskId);
            return;
        }

        // Батч: за один крон-тик продвигаем до cellsPerTick() клеток (крон everyMinute →
        // granularity 1 мин, поэтому «быстрее» = больше клеток за тик). Если внутри пачки
        // сработал стоп/пауза/финиш — advanceOneCell уже отправил сообщение, выходим.
        $cellsPerTick = $this->cellsPerTick();
        for ($i = 0; $i < $cellsPerTick; $i++) {
            if ($this->advanceOneCell($characterId, $telegramUserId, $heading, $stepsPlanned, $s, $character) !== self::CELL_CONTINUE) {
                return;
            }
        }

        // Пачка пройдена, поход не завершён и не на паузе → следующий батч через тик + прогресс.
        $stepsDone = $this->asInt($s['steps_done'] ?? 0);
        $this->spawnNextStep($characterId, $telegramUserId, $s);
        $this->editMarchMessage($telegramUserId, $s, $character, $stepsDone, $stepsPlanned);
    }

    /**
     * Продвинуть персонажа на ОДНУ клетку в направлении $heading (со всеми проверками,
     * побочками и точками прерывания). `$s`/`$character` мутируются по ссылке (для батча
     * и финал-сводки). Возвращает исход:
     *   CELL_CONTINUE — клетка пройдена, можно идти дальше (сообщение НЕ слалось);
     *   CELL_STOPPED  — жёсткий стоп (край/вода/чужой лагерь/привал), finishMarch отправил сводку;
     *   CELL_PAUSED   — прерывание (встреча/мини-событие/игрок), pauseMarch отправил промпт;
     *   CELL_DONE     — план выполнен, finishMarch отправил финал.
     *
     * 🔴 Батч-инвариант: обновляем ВСЮ in-memory копию персонажа (включая experience/стат),
     * чтобы следующая клетка пачки читала свежие значения — иначе 2-я клетка затёрла бы
     * прирост 1-й (DB-update считает от `$character[...]`).
     *
     * @param array<int|string, mixed> $s
     * @param array<int|string, mixed> $character
     */
    private function advanceOneCell(int $characterId, int $telegramUserId, string $heading, int $stepsPlanned, array &$s, array &$character): string
    {
        $charCellNumber = $this->asInt($character['cell_number']);
        $currentCell = $this->fetchCellByNumber($charCellNumber);
        if ($currentCell === null) {
            log_message('error', '[MarchingTaskHandler] текущая клетка не найдена: ' . $charCellNumber);
            return self::CELL_STOPPED;
        }
        $curX = $this->asInt($currentCell['coordinate_x'] ?? 0);
        $curY = $this->asInt($currentCell['coordinate_y'] ?? 0);

        [$dx, $dy] = self::DIRECTIONS[$heading];
        $newX = $curX + $dx;
        $newY = $curY + $dy;

        // — Край мира (E2: реальная сетка карты 0..999, не 1..1000) —
        if ($newX < 0 || $newX > 999 || $newY < 0 || $newY > 999) {
            $this->finishMarch($telegramUserId, $s, $character, "уперся в край мира у (X={$curX}, Y={$curY})");
            return self::CELL_STOPPED;
        }
        $targetCell = $this->fetchCellByCoords($newX, $newY);
        if ($targetCell === null) {
            $this->finishMarch($telegramUserId, $s, $character, 'дальше нет клетки');
            return self::CELL_STOPPED;
        }
        $targetCellNumber = $this->asInt($targetCell['cell_number'] ?? 0);
        $targetBiomeId    = $this->asInt($targetCell['biome_id'] ?? 0);

        // — Вода —
        $biome = $this->fetchBiome($targetBiomeId);
        if ($biome !== null && $this->isWaterBiome($biome)) {
            $this->finishMarch($telegramUserId, $s, $character, "впереди река у (X={$newX}, Y={$newY}) — обойди другим курсом");
            return self::CELL_STOPPED;
        }

        // — Чужой активный лагерь на следующей клетке —
        if ($this->hasForeignActiveClaim($targetCellNumber, $characterId)) {
            $this->finishMarch($telegramUserId, $s, $character, "дорогу преграждает чужой лагерь у (X={$newX}, Y={$newY})");
            return self::CELL_STOPPED;
        }

        // — Стоимость / пол выносливости —
        $hpCost   = $this->healthCostPerCell();
        $isDanger = $biome !== null && $this->asInt($biome['danger_level'] ?? 0) >= 8;
        if ($isDanger) {
            $hpCost += $this->dangerHealthSurcharge();
        }
        $tiredCost   = $this->tiredCostPerCell();
        $futureHp    = $this->asFloat($character['health'] ?? 0) - $hpCost;
        $futureTired = $this->asFloat($character['tired'] ?? 0) - $tiredCost;
        if ($futureHp < 0.01 || $futureTired < 0.01) {
            $this->finishMarch($telegramUserId, $s, $character, "выбился из сил — привал на (X={$curX}, Y={$curY})");
            return self::CELL_STOPPED;
        }
        // — Шаг —
        // ADR-138 (S3): ранние гейны марша (xp/stat) ×gain_multiplier для новичка (level<cap).
        // Dormant/ветеран → marchMult=1.0 = byte-identical.
        $stepsDone = max(0, $this->asInt($s['steps_done'] ?? 0));
        $marchMult = (new EarlyProgressionService())
            ->gainMultiplier($this->asFloat($character['level'] ?? 1));
        $statKeys  = ['strength', 'agility', 'intellect'];
        $statKey   = $statKeys[$stepsDone % 3];

        // Fix 2026-07-13 (класс lost-update): статы — атомарной ДЕЛЬТОЙ от СВЕЖИХ
        // значений под row-lock'ом (CharacterStatsService; floor 0.01 как у гейта
        // выше), позиция — отдельным update. Препарат/бой во время марша не
        // затирается снапшотом батча.
        $adjusted = (new \App\Services\Player\CharacterStatsService())->adjust($characterId, [
            'health'     => -$hpCost,
            'tired'      => -$tiredCost,
            'experience' => $this->xpPerCell() * $marchMult,
            $statKey     => $this->statPerCell() * $marchMult,
        ], ['health' => ['min' => 0.01], 'tired' => ['min' => 0.01]]);

        (new CharacterModel())->update($characterId, [
            'cell_number' => $targetCellNumber,
            'biome_id'    => $targetBiomeId,
        ]);
        // Освежаем ВСЮ in-memory копию фактическим after (батч-инвариант:
        // следующая клетка читает свежее).
        $character['cell_number'] = $targetCellNumber;
        $character['biome_id']    = $targetBiomeId;
        if ($adjusted !== null) {
            $character['health']     = round($adjusted['after']['health'], 2);
            $character['tired']      = round($adjusted['after']['tired'], 2);
            $character['experience'] = $adjusted['after']['experience'];
            $character[$statKey]     = $adjusted['after'][$statKey];
        }

        // — Туман войны: раскрываем 3×3 вокруг новой позиции —
        $level = isset($character['level']) ? $this->asInt($character['level']) : null;
        (new ExploredCellsModel())->revealAround($characterId, $telegramUserId, $newX, $newY, $level);

        // — Накопленные дельты —
        $acc = is_array($s['acc'] ?? null) ? $s['acc'] : [];
        $acc['xp']    = round($this->asFloat($acc['xp'] ?? 0) + $this->xpPerCell() * $marchMult, 4);
        $acc['hp']    = round($this->asFloat($acc['hp'] ?? 0) + $hpCost, 4);
        $acc['tired'] = round($this->asFloat($acc['tired'] ?? 0) + $tiredCost, 4);
        $acc['stat']  = round($this->asFloat($acc['stat'] ?? 0) + $this->statPerCell() * $marchMult, 4);
        $s['acc'] = $acc;

        $stepsDone++;
        $s['steps_done'] = $stepsDone;

        // — Находки в пути (handlers объектов шлют свои сообщения; марш не блокируется) —
        try {
            (new ObjectDiscoveryService(
                new \App\Models\BiomeWorldObjectMapModel(),
                new \App\Models\WorldObjectModel()
            ))->discoverObjectsAtPlayerPosition($character);
        } catch (\Throwable $e) {
            log_message('error', '[MarchingTaskHandler] discoverObjects: ' . $e->getMessage());
        }

        // — S26b (ADR-031): дозорные вышки чужих баз в радиусе → пинг их владельцам —
        try {
            $this->towerAlerts->notifyTowersNear($characterId, $newX, $newY);
        } catch (\Throwable $e) {
            log_message('error', '[MarchingTaskHandler] towerAlerts: ' . $e->getMessage());
        }

        // — ADR-098: радио-сигнал к ближайшему редкому (strategic) объекту в радиусе —
        try {
            $this->objectSignal->signalNearbyObjects($characterId, $newX, $newY);
        } catch (\Throwable $e) {
            log_message('error', '[MarchingTaskHandler] objectSignal: ' . $e->getMessage());
        }

        // — ADR-089 Фаза 3: случайная встреча с нейтральным NPC в пути → пауза похода —
        if ($this->tryRandomNpcEncounter($characterId, $telegramUserId, $targetCellNumber, $s, $character, $stepsPlanned - $stepsDone)) {
            return self::CELL_PAUSED;
        }

        // — E17 Ф2 (ADR-117): персональное мини-событие в пути для L25+ → пауза похода —
        if ($this->tryRandomMiniEvent($characterId, $telegramUserId, $s, $character, $stepsPlanned - $stepsDone)) {
            return self::CELL_PAUSED;
        }

        // — PvP detection: обнаружили игрока → пауза с промптом —
        if ($this->detector->detectNearbyPlayers($characterId)) {
            $this->pauseMarch($characterId, $telegramUserId, $s, $character, 'player_detected', $stepsPlanned - $stepsDone);
            return self::CELL_PAUSED;
        }

        // — План выполнен? —
        if ($stepsDone >= $stepsPlanned) {
            $this->finishMarch($telegramUserId, $s, $character, null);
            return self::CELL_DONE;
        }

        return self::CELL_CONTINUE;
    }

    // ------------------------------------------------------------------ DB reads (raw)

    /** @return array<int|string, mixed>|null */
    private function fetchCharacter(int $id): ?array
    {
        return $this->fetchRow(
            'SELECT id, telegram_user_id, cell_number, biome_id, health, tired, experience, strength, agility, intellect, level FROM characters WHERE id = ? LIMIT 1',
            [$id]
        );
    }

    /** @return array<int|string, mixed>|null */
    private function fetchCellByNumber(int $cellNumber): ?array
    {
        return $this->fetchRow(
            'SELECT cell_number, biome_id, coordinate_x, coordinate_y FROM map WHERE cell_number = ? LIMIT 1',
            [$cellNumber]
        );
    }

    /** @return array<int|string, mixed>|null */
    private function fetchCellByCoords(int $x, int $y): ?array
    {
        return $this->fetchRow(
            'SELECT cell_number, biome_id, coordinate_x, coordinate_y FROM map WHERE coordinate_x = ? AND coordinate_y = ? LIMIT 1',
            [$x, $y]
        );
    }

    /** @return array<int|string, mixed>|null */
    private function fetchBiome(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        return $this->fetchRow('SELECT id, name, danger_level FROM biomes WHERE id = ? LIMIT 1', [$id]);
    }

    private function hasForeignActiveClaim(int $cellNumber, int $characterId): bool
    {
        return $this->fetchRow(
            "SELECT id FROM claimed_cells WHERE map_cell_id = ? AND status = 'active' AND character_id <> ? LIMIT 1",
            [$cellNumber, $characterId]
        ) !== null;
    }

    private function marchingTaskId(): ?int
    {
        $row = $this->fetchRow("SELECT id FROM tasks WHERE name = 'Marching' LIMIT 1", []);
        if ($row === null) {
            return null;
        }
        $id = $this->asInt($row['id'] ?? 0);
        return $id > 0 ? $id : null;
    }

    private function resolveChatId(int $telegramUserId): ?int
    {
        $row = $this->fetchRow('SELECT telegram_id FROM telegram_users WHERE id = ? LIMIT 1', [$telegramUserId]);
        if ($row === null) {
            return null;
        }
        $chatId = $this->asInt($row['telegram_id'] ?? 0);
        return $chatId !== 0 ? $chatId : null;
    }

    /**
     * @param array<int, int|string> $bind
     * @return array<int|string, mixed>|null
     */
    private function fetchRow(string $sql, array $bind): ?array
    {
        $res = Database::connect()->query($sql, $bind);
        if (!$res instanceof BaseResult) {
            return null;
        }
        $rows = $res->getResultArray();
        $row  = $rows[0] ?? null;
        return is_array($row) ? $row : null;
    }

    // ------------------------------------------------------------------ helpers

    /** @param array<int|string, mixed> $biome */
    private function isWaterBiome(array $biome): bool
    {
        $name = mb_strtolower($this->asStr($biome['name'] ?? ''));
        // Биом «Реки» (id 4 по легенде карты). По имени надёжнее, чем по id.
        return $name === 'реки' || str_contains($name, 'river') || $this->asInt($biome['id'] ?? 0) === 4;
    }

    /**
     * Завершает поход: финальное сообщение (карта + сводка). Следующая задача НЕ
     * спавнится. Текущая задача уже 'completed' (Worker atomic-claim).
     *
     * @param array<int|string, mixed> $s
     * @param array<int|string, mixed> $character
     */
    private function finishMarch(int $telegramUserId, array $s, array $character, ?string $reason): void
    {
        $heading   = $this->asStr($s['heading'] ?? '');
        $dirLabel  = self::DIR_LABEL[$heading] ?? $heading;
        $stepsDone = $this->asInt($s['steps_done'] ?? 0);
        $acc       = is_array($s['acc'] ?? null) ? $s['acc'] : [];
        $log       = is_array($s['log'] ?? null) ? $s['log'] : [];

        $text = "🚜 *Поход окончен.* {$dirLabel}, пройдено `{$stepsDone}` " . $this->plural($stepsDone, 'клетку', 'клетки', 'клеток') . ".\n";
        if ($reason !== null && $reason !== '') {
            $text .= "_{$reason}._\n";
        }
        $text .= "\n";
        $hp    = $this->asFloat($acc['hp'] ?? 0);
        $tired = $this->asFloat($acc['tired'] ?? 0);
        $xp    = $this->asFloat($acc['xp'] ?? 0);
        $stat  = $this->asFloat($acc['stat'] ?? 0);
        if ($hp > 0 || $tired > 0) {
            $text .= "Потрачено: ❤️ -{$hp}  💤 -{$tired}\n";
        }
        if ($xp > 0 || $stat > 0) {
            $text .= "Получено: +{$xp} опыта, +{$stat} к характеристикам (по ротации).\n";
        }
        foreach ($log as $line) {
            $text .= 'В пути: ' . $this->asStr($line) . "\n";
        }
        $mapRow = $this->fetchCellByNumber($this->asInt($character['cell_number'] ?? 0));
        if ($mapRow !== null) {
            $text .= 'Сейчас: X=' . $this->asInt($mapRow['coordinate_x'] ?? 0) . ', Y=' . $this->asInt($mapRow['coordinate_y'] ?? 0) . ".\n";
        }
        $text .= "\n" . $this->renderMap($character);

        $keyboardRows = [
            [
                ['text' => '🚜 Идти дальше', 'callback_data' => 'move'],
                // ADR-168 — метка источника «финиш Похода».
                ['text' => '⛏ Добыть', 'callback_data' => \App\Services\Logging\ActionOrigin::tag('gather', \App\Services\Logging\ActionOrigin::FROM_MARCH)],
            ],
            [
                ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
            ],
        ];

        $this->deliverMarchMessage($telegramUserId, $s, $text, $keyboardRows);
    }

    /**
     * ADR-089 Фаза 3 — случайная встреча с нейтральным NPC на текущей клетке.
     * Гейт: killswitch npc.interaction_enabled + RNG(npc.march_encounter_chance) + наличие
     * живого passive-NPC. При успехе → pauseMarch(npc_encounter) + spawn_id в settings.
     * При выключенном шансе/killswitch — мгновенный false (0 регрессии похода).
     *
     * @param array<int|string, mixed> $s
     * @param array<int|string, mixed> $character
     * @return bool true → поход прерван (встреча)
     */
    private function tryRandomNpcEncounter(int $characterId, int $telegramUserId, int $targetCellNumber, array $s, array $character, int $stepsRemaining): bool
    {
        $svc = new \App\Services\NPC\NpcInteractionService();
        if (! $svc->enabled()) {
            return false;
        }
        $chance = $svc->marchEncounterChance();
        if ($chance <= 0.0) {
            return false;
        }
        if (mt_rand(0, 9999) / 10000.0 >= $chance) {
            return false;
        }
        $npc = $svc->passiveSpawnOnCell($targetCellNumber);
        if ($npc === null) {
            return false;
        }

        $spawnRaw = $npc['spawn_id'] ?? null;
        $s['npc_encounter_spawn_id'] = is_numeric($spawnRaw) ? (int) $spawnRaw : 0;
        $this->pauseMarch($characterId, $telegramUserId, $s, $character, 'npc_encounter', $stepsRemaining);

        return true;
    }

    /**
     * E17 Ф2 (ADR-117) — персональное мини-событие в пути для L25+.
     * Тройной гейт: killswitch `world.march_events.enabled` + level ≥ `min_level`(25) +
     * RNG < `chance_per_cell`(0 dormant). При chance 0 → мгновенный false (0 регрессии похода,
     * fixture-fence). При успехе → выбирается мини-событие, ключ в settings → pauseMarch(mini_event).
     *
     * @param array<int|string, mixed> $s
     * @param array<int|string, mixed> $character
     * @return bool true → поход прерван (мини-событие)
     */
    private function tryRandomMiniEvent(int $characterId, int $telegramUserId, array $s, array $character, int $stepsRemaining): bool
    {
        $level = isset($character['level']) ? $this->asInt($character['level']) : 0;
        if (! $this->miniEvents->eligible($level)) {
            return false;
        }
        if (! $this->miniEvents->roll()) {
            return false;
        }

        $s['mini_event_key'] = $this->miniEvents->pickKey();
        $this->pauseMarch($characterId, $telegramUserId, $s, $character, 'mini_event', $stepsRemaining);

        return true;
    }

    /**
     * Текст-промпт мини-события (по `mini_event_key` из settings) для pauseMarch.
     *
     * @param array<int|string, mixed> $s
     */
    private function miniEventPrompt(array $s, int $stepsRemaining): string
    {
        $key  = isset($s['mini_event_key']) && is_string($s['mini_event_key']) ? $s['mini_event_key'] : '';
        $card = $key !== '' ? $this->miniEvents->card($key) : null;
        if ($card === null) {
            return "🔍 *Что-то в пути.* Поход на паузе (осталось `{$stepsRemaining}` " . $this->plural($stepsRemaining, 'клетку', 'клетки', 'клеток') . ').';
        }
        $left = $this->plural($stepsRemaining, 'клетку', 'клетки', 'клеток');

        return "{$card['icon']} *{$card['title']}.* {$card['lore']}\n"
            . "Поход на паузе (осталось `{$stepsRemaining}` {$left}).\n"
            . "_{$card['prompt']} Осмотреть — взять, что найдётся. Или пройти мимо и продолжить путь._";
    }

    /**
     * Пауза: новый character_tasks row со status='paused' (steps_remaining +
     * paused_reason) + сообщение с промптом. Текущая задача уже 'completed'.
     *
     * @param array<int|string, mixed> $s
     * @param array<int|string, mixed> $character
     */
    private function pauseMarch(int $characterId, int $telegramUserId, array $s, array $character, string $reason, int $stepsRemaining): void
    {
        $stepsRemaining = max(0, $stepsRemaining);
        $marchingTaskId = $this->marchingTaskId();
        if ($marchingTaskId === null) {
            $this->finishMarch($telegramUserId, $s, $character, 'поход прерван');
            return;
        }

        $pausedSettings = $s;
        $pausedSettings['steps_remaining'] = $stepsRemaining;
        $pausedSettings['paused_reason']   = $reason;

        (new CharacterTaskModel())->insert([
            'character_id'     => $characterId,
            'telegram_user_id' => $telegramUserId,
            'task_id'          => $marchingTaskId,
            'start_time'       => date('Y-m-d H:i:s'),
            'end_time'         => date('Y-m-d H:i:s'),
            'status'           => 'paused',
            'task_settings'    => json_encode($pausedSettings),
        ]);

        $text = match ($reason) {
            'player_detected' => "👀 *Поблизости кто-то есть.* Поход на паузе (осталось `{$stepsRemaining}` " . $this->plural($stepsRemaining, 'клетку', 'клетки', 'клеток') . ").\n"
                . '_Промпт «атаковать / бежать» придёт отдельным сообщением. Или продолжай поход — пройдёшь мимо._',
            'npc_encounter' => "👤 *Встреча в пути.* На клетке кто-то живой — настороженно смотрит на тебя. Поход на паузе (осталось `{$stepsRemaining}` " . $this->plural($stepsRemaining, 'клетку', 'клетки', 'клеток') . ").\n"
                . '_Подойти — поговорить, торговать, напасть. Или пройти мимо и продолжить путь._',
            'mini_event' => $this->miniEventPrompt($s, $stepsRemaining),
            default => "⏸ *Поход на паузе* (осталось `{$stepsRemaining}` " . $this->plural($stepsRemaining, 'клетку', 'клетки', 'клеток') . ').',
        };
        $text .= "\n\n" . $this->renderMap($character);

        // ADR-089 Фаза 3: NPC → подойти/мимо; E17 Ф2: мини-событие → осмотреть/мимо; иначе продолжить/действия.
        $keyboardRows = match ($reason) {
            'npc_encounter' => [[
                ['text' => '👤 Подойти', 'callback_data' => 'npcEncounter'],
                ['text' => '🚶 Пройти мимо', 'callback_data' => 'march_resume'],
            ]],
            'mini_event' => [[
                ['text' => '🔍 Осмотреть', 'callback_data' => 'marchMini'],
                ['text' => '🚶 Пройти мимо', 'callback_data' => 'march_resume'],
            ]],
            default => [[
                ['text' => '🚜 Продолжить поход', 'callback_data' => 'march_resume'],
                ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
            ]],
        };

        $this->deliverMarchMessage($telegramUserId, $s, $text, $keyboardRows);
    }

    /**
     * Спавнит следующую 1-клеточную Marching-задачу (chain).
     *
     * @param array<int|string, mixed> $s
     */
    private function spawnNextStep(int $characterId, int $telegramUserId, array $s): void
    {
        $marchingTaskId = $this->marchingTaskId();
        if ($marchingTaskId === null) {
            log_message('error', '[MarchingTaskHandler] нет строки Marching в tasks — поход не может продолжиться');
            return;
        }
        $start = new \DateTime();
        $end   = (clone $start)->add($this->stepDueInterval());
        (new CharacterTaskModel())->insert([
            'character_id'     => $characterId,
            'telegram_user_id' => $telegramUserId,
            'task_id'          => $marchingTaskId,
            'start_time'       => $start->format('Y-m-d H:i:s'),
            'end_time'         => $end->format('Y-m-d H:i:s'),
            'status'           => 'in_work',
            'task_settings'    => json_encode($s),
        ]);
    }

    /**
     * Интервал «созревания» следующего шага марша.
     *
     * Worker (`Config\Tasks` → `everyMinute`) выбирает задачи `WHERE end_time < now`
     * раз в минуту (granularity 1 мин), батч фиксируется в начале прогона. Спавн шага
     * происходит ВНУТРИ крон-прогона (его время ≈ старт прогона + мелкий сдвиг, т.е.
     * phase-locked к тику). Поэтому `end_time = start + (minutes_per_cell − 1)мин`
     * делает шаг «созревшим» ровно на M-й последующий тик — детерминированно.
     *
     * Раньше было `+ minutes_per_cell`мин: end_time попадал то до, то после
     * следующего тика (зависело от сдвига спавна внутри прогона) → шаг ждал 1 ИЛИ 2
     * тика → дрожание 1–2 мин/клетку (прод-жалоба Max Syskov). При M=1 новый интервал =
     * `PT0M` → шаг созревает к СЛЕДУЮЩЕМУ тику → ровно ~1 мин/клетку, без дрожания.
     */
    private function stepDueInterval(): \DateInterval
    {
        $minutes = (new MarchPaceService())->stepDueInterval($this->minutesPerCell());

        return new \DateInterval('PT' . $minutes . 'M');
    }

    /**
     * Промежуточный экран идущего марша (edit-in-place; map = text → editMessageText).
     *
     * @param array<int|string, mixed> $s
     * @param array<int|string, mixed> $character
     */
    private function editMarchMessage(int $telegramUserId, array $s, array $character, int $stepsDone, int $stepsPlanned): void
    {
        $heading  = $this->asStr($s['heading'] ?? '');
        $dirLabel = self::DIR_LABEL[$heading] ?? $heading;
        $left     = max(0, $stepsPlanned - $stepsDone);
        $etaMin   = $this->etaMinutes($left);
        $mapRow   = $this->fetchCellByNumber($this->asInt($character['cell_number'] ?? 0));
        $coords   = $mapRow !== null ? ('X=' . $this->asInt($mapRow['coordinate_x'] ?? 0) . ', Y=' . $this->asInt($mapRow['coordinate_y'] ?? 0)) : '';
        $hpStr    = $this->asStr($character['health'] ?? '');
        $tiredStr = $this->asStr($character['tired'] ?? '');

        $text = "🚜 *Идём:* {$dirLabel}\n\n"
            . $this->renderMap($character) . "\n"
            . "Пройдено `{$stepsDone}` / `{$stepsPlanned}`  ·  осталось ~{$etaMin} мин\n"
            . "❤️ {$hpStr}   💤 {$tiredStr}"
            . ($coords !== '' ? "  ·  {$coords}" : '');

        $keyboardRows = [
            [
                ['text' => '❌ Остановиться', 'callback_data' => 'cancelMarch'],
                ['text' => '➕ Продлить +5', 'callback_data' => 'march_more_5'],
            ],
        ];

        $this->deliverMarchMessage($telegramUserId, $s, $text, $keyboardRows);
    }

    /**
     * Доставка сообщения марша: пытаемся отредактировать `msg_id` (если есть),
     * на ошибку — новое сообщение. (Step 3: msg_id не обновляем; достаточно для
     * первой итерации — следующая задача взяла $s со старым msg_id, при edit-fail
     * пошлёт новое и дальше fallback'нется.)
     *
     * @param array<int|string, mixed> $s
     * @param array<int, array<int, array<string,string>>> $keyboardRows
     */
    protected function deliverMarchMessage(int $telegramUserId, array $s, string $text, array $keyboardRows): void
    {
        $this->telegram();

        $rawChatId = $s['msg_chat_id'] ?? null;
        $chatId    = is_numeric($rawChatId) ? (int) $rawChatId : $this->resolveChatId($telegramUserId);
        if ($chatId === null) {
            log_message('error', '[MarchingTaskHandler] не удалось определить chat_id для марша');
            return;
        }

        $payload = [
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboardRows]),
        ];

        $rawMsgId = $s['msg_id'] ?? null;
        if (is_numeric($rawMsgId)) {
            try {
                $resp = Request::editMessageText($payload + ['message_id' => (int) $rawMsgId]);
                if ($resp->isOk()) {
                    return;
                }
            } catch (\Throwable $e) {
                // fallthrough → новое сообщение
            }
        }
        try {
            Request::sendMessage($payload);
        } catch (\Throwable $e) {
            log_message('error', '[MarchingTaskHandler] sendMessage: ' . $e->getMessage());
        }
    }

    /** @param array<int|string, mixed> $character */
    private function renderMap(array $character): string
    {
        try {
            return (new TextMapService())->buildMapOnly($character);
        } catch (\Throwable $e) {
            log_message('error', '[MarchingTaskHandler] renderMap: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * ETA марша в минутах: `$cells` клеток идут пачками по `cellsPerTick()` за тик
     * (admin GameSettings world.march.cells_per_tick), тик = `minutes_per_cell` мин.
     * → ceil(cells / perTick) × minutesPerTick.
     */
    private function etaMinutes(int $cells): int
    {
        return (new MarchPaceService())->etaMinutes($cells, $this->cellsPerTick(), $this->minutesPerCell());
    }

    /**
     * Клеток за крон-тик — live-tunable через admin GameSettings
     * `world.march.cells_per_tick` (ADMIN-TUNABLE BALANCE, ADR-024). Fallback 3 = код-дефолт
     * (safe baseline). Overridable seam для тестов (без обращения к game_settings). Формула —
     * `MarchPaceService::cellsPerTick()`, единая точка с `MarchAction::showRouteSetup()`.
     */
    protected function cellsPerTick(): int
    {
        $base = max(1, $this->gsInt('world.march.cells_per_tick', 3));

        return (new MarchPaceService())->cellsPerTick($base, $this->neutralProfile($base));
    }

    /**
     * Профиль-нейтраль (нет транспорта) для MarchPaceService — контракт
     * `docs/specs/transport-system/plan.md → ## Contracts`. Реальный профиль
     * транспорта появится в transport-04 (VehicleEffectsService, transport-02).
     *
     * @return array<string,mixed>
     */
    private function neutralProfile(int $cellsPerTickBase): array
    {
        return [
            'key'                 => null,
            'cells_per_tick'      => $cellsPerTickBase,
            'tired_factor'        => 1.0,
            'max_steps_per_order' => $this->gsInt('world.march.max_steps_per_order', 60),
            'cargo_share'         => 0.0,
            'wear_per_cell'       => 0,
        ];
    }

    // ── march-баланс: live-tunable через admin GameSettings `world.march.*`
    //    (ADMIN-TUNABLE BALANCE, ADR-024). Fallback-числа = safe-baseline код-дефолт;
    //    в table-less тест-БД gs()->get() отдаёт именно fallback (defensive degradation).

    /** Минут на крон-тик Похода (задержка между пачками клеток). Fallback 1. */
    protected function minutesPerCell(): int
    {
        return max(1, $this->gsInt('world.march.minutes_per_cell', 1));
    }

    /** Базовый расход ❤️ за клетку (вне danger-биома). Fallback 0.02. */
    protected function healthCostPerCell(): float
    {
        return $this->gsFloat('world.march.health_cost_per_cell', 0.02);
    }

    /** Доп. расход ❤️ за клетку в опасном биоме (danger_level ≥ 8). Fallback 1.0. */
    protected function dangerHealthSurcharge(): float
    {
        return $this->gsFloat('world.march.danger_health_surcharge', 1.0);
    }

    /** Расход 💤 за клетку. Fallback 0.5. */
    protected function tiredCostPerCell(): float
    {
        return $this->gsFloat('world.march.tired_cost_per_cell', 0.5);
    }

    /** Прирост опыта за клетку (до множителя новичка ADR-138). Fallback 0.03. */
    protected function xpPerCell(): float
    {
        return $this->gsFloat('world.march.xp_per_cell', 0.03);
    }

    /** Прирост характеристики за клетку (ротация str/agi/int). Fallback 0.02. */
    protected function statPerCell(): float
    {
        return $this->gsFloat('world.march.stat_per_cell', 0.02);
    }

    private function plural(int $n, string $one, string $few, string $many): string
    {
        $n  = abs($n) % 100;
        $n1 = $n % 10;
        if ($n > 10 && $n < 20) {
            return $many;
        }
        if ($n1 > 1 && $n1 < 5) {
            return $few;
        }
        if ($n1 === 1) {
            return $one;
        }
        return $many;
    }

    private function asInt(mixed $v, int $default = 0): int
    {
        return is_numeric($v) ? (int) $v : $default;
    }

    private function asFloat(mixed $v, float $default = 0.0): float
    {
        return is_numeric($v) ? (float) $v : $default;
    }

    private function asStr(mixed $v, string $default = ''): string
    {
        return is_scalar($v) ? (string) $v : $default;
    }
}
