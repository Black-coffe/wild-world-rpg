<?php

declare(strict_types=1);

namespace App\Services\Onboarding;

use App\Models\ActionLogModel;
use App\Models\MapModel;
use App\Models\NpcModel;
use App\Models\NpcSpawnModel;

/**
 * S2 (ROADMAP-RETENTION-10, ADR-144) — «скриптовая первая встреча»: дружелюбный нейтрал у
 * спавна новичка.
 *
 * Genuine gap (audit S2): новичковая зона (Y≥`npc.newbie_zone.y_min`, default 900) —
 * ghost-town (на проде 2 NPC на ~100k клеток против 8874 на всей карте). Ambient-популяция
 * (`npc.newbie_zone.population`) топит редких блукачей, но 8-15 на 100k клеток статистически
 * НЕвидимы за ~29 ходов сессии-1 → первой встречи с NPC почти не случается. Этот сервис
 * детерминированно ставит ОДНОГО passive-нейтрала (предпочтительно квестгивера, например
 * «Мусорщик») в клетку рядом со спавном → новичок видит 🧑 на карте сразу и натыкается на
 * кнопку «👤 Незнакомец» в первые ходы (encounter-UI уже жив:
 * {@see \App\Controllers\Telegram\Commands\Actions\MoveCharacterToDirectionAction}). Это и есть
 * «скриптовая встреча на пути новичка» из роадмапа (ambient-фон её НЕ обеспечивает).
 *
 * Выбор NPC: passive + npc_type='neutral' + is_boss=0, приоритет квестгивера
 * (offers_quest_title_en != NULL), затем минимальный level. id шаблонов не фиксируем (как
 * NodeRespawnHandler ADR-106 — auto_increment), резолвим запросом. Боссы исключены тем же
 * инвариантом is_boss=0, что и в WandererSpawnHandler (странник ≠ босс).
 *
 * Размещение: зеркало {@see ColdOpenSignalService::placeBaitForNewChar} — reactive по
 * фактической клетке спавна (спавн = случайная клетка Y≥900), кандидаты-направления
 * северо-смещены (юг = край мира), на расстоянии `npc.newbie_zone.greeter_distance` (default 1
 * = смежная клетка → 🧑 виден сразу, encounter в 1 ход). Клетка — суша допустимого биома, не
 * занятая другим спавном.
 *
 * Гейты: killswitch `npc.newbie_zone.greeter_enabled` (default OFF → no-op = byte-identical) +
 * one-shot маркер `NEWBIE_GREETER_PLACED` в action_log (повторный /start не дублирует;
 * action_status строго из enum — урок E3/m8k2b). ОТДЕЛЬНЫЙ от ambient-`population` флаг: своя
 * Tier-3 и активация.
 *
 * MEDIA-OFF (ADR-020): нарратив Роби текстовый, весь смысл в тексте.
 *
 * Overridable seam'ы (тесты без БД на CI): enabled / distance / alreadyPlaced / pickGreeterNpcId
 * / npcHealth / findLandCell / cellOccupied / insertSpawn / writeMarker.
 *
 * @see [[ADR-144-Newbie-zone-population-and-greeter]]
 */
class NewbieGreeterService
{
    use \App\Services\GameSettings\GameSettingsReaderTrait;

    /** action_log.action_name маркера «встречающий размещён» (idempotency). */
    public const PLACED_FLAG = 'NEWBIE_GREETER_PLACED';

    /**
     * Кандидаты направлений (dx, dy, ru-label, эмодзи-стрелка). Юг исключён — спавн у южной
     * кромки (Y≥900). Тот же набор, что у приманки cold-open (консистентность UX).
     *
     * @var list<array{0:int,1:int,2:string,3:string}>
     */
    private const DIRECTION_CANDIDATES = [
        [0, -1, 'к северу', '⬆️'],
        [1, -1, 'к северо-востоку', '↗️'],
        [-1, -1, 'к северо-западу', '↖️'],
        [1, 0, 'к востоку', '➡️'],
        [-1, 0, 'к западу', '⬅️'],
    ];

    /** Допустимые биомы для клетки встречающего (та же суша, что спавн; 4 = вода исключена). */
    private const ALLOWED_BIOMES = [1, 2, 3, 5, 6, 7, 8, 9];

    public function __construct(
        private readonly ?NpcModel $npcs = null,
        private readonly ?NpcSpawnModel $spawns = null,
        private readonly ?MapModel $map = null,
        private readonly ?ActionLogModel $log = null,
    ) {
    }

    /**
     * Разместить дружелюбного нейтрала рядом со спавном нового чара и вернуть текст
     * нарратива Роби (или null: выключено / уже размещён / нет подходящего NPC / не нашлось
     * валидной свободной клетки).
     *
     * @param array<int|string, mixed> $spawnCell map-row спавна (coordinate_x/y)
     */
    public function placeGreeterForNewChar(int $charId, array $spawnCell, int $chatId, bool $withNavHint = true): ?string
    {
        if ($charId <= 0 || ! $this->enabled() || $this->alreadyPlaced($charId)) {
            return null;
        }

        $npcId = $this->pickGreeterNpcId();
        if ($npcId <= 0) {
            return null;
        }

        $spawnX = self::intVal($spawnCell, 'coordinate_x');
        $spawnY = self::intVal($spawnCell, 'coordinate_y');
        $dist   = $this->distance();

        foreach (self::DIRECTION_CANDIDATES as [$dx, $dy, $label, $arrow]) {
            $tx = $spawnX + $dx * $dist;
            $ty = $spawnY + $dy * $dist;
            if ($tx < 0 || $tx > 999 || $ty < 0 || $ty > 999) {
                continue;
            }
            $landCell = $this->findLandCell($tx, $ty);
            if ($landCell === null) {
                continue;
            }
            $cellNumber = self::intVal($landCell, 'cell_number');
            if ($cellNumber <= 0 || $this->cellOccupied($cellNumber)) {
                continue;
            }

            $this->insertSpawn($npcId, $landCell);
            $this->writeMarker($charId, $chatId);

            return $this->buildGreeterNarrative($label, $arrow, $withNavHint);
        }

        return null;
    }

    // ── Overridable seam'ы (настройки) ───────────────────────────────────────

    protected function enabled(): bool
    {
        return $this->gsBool('npc.newbie_zone.greeter_enabled', false);
    }

    /** Расстояние (в клетках) от спавна до встречающего. Default 1 = смежная клетка. */
    protected function distance(): int
    {
        return max(1, $this->gsInt('npc.newbie_zone.greeter_distance', 1));
    }

    // ── Overridable seam'ы (БД) ──────────────────────────────────────────────

    protected function alreadyPlaced(int $charId): bool
    {
        return $this->logModel()
            ->where('character_id', $charId)
            ->where('action_name', self::PLACED_FLAG)
            ->countAllResults() > 0;
    }

    /**
     * Выбрать id шаблона дружелюбного встречающего: passive + npc_type='neutral' + is_boss=0,
     * приоритет квестгивера (offers_quest_title_en != NULL), затем минимальный level. 0, если
     * пул пуст. Сортировка в PHP (пул мал, ~8 строк) — без хрупких orderBy-выражений.
     */
    protected function pickGreeterNpcId(): int
    {
        $rows = $this->npcModel()
            ->where('ai_behavior', 'passive')
            ->where('npc_type', 'neutral')
            ->where('is_boss', 0)
            ->findAll();

        return self::selectBestGreeter($rows);
    }

    /**
     * Чистый выбор лучшего встречающего из набора нейтралов (тестируемо без БД): приоритет
     * квестгивера (offers_quest_title_en != NULL), среди равных — минимальный level. 0, если
     * подходящих нет. Сортировка ключом (hasQuest?0:1M)+level → меньше = лучше.
     *
     * @param list<array<int|string, mixed>>|array<int, mixed> $rows
     */
    public static function selectBestGreeter(array $rows): int
    {
        $bestId    = 0;
        $bestScore = PHP_INT_MAX;
        foreach ($rows as $r) {
            if (! is_array($r)) {
                continue;
            }
            $id = is_numeric($r['id'] ?? null) ? (int) $r['id'] : 0;
            if ($id <= 0) {
                continue;
            }
            $hasQuest = ($r['offers_quest_title_en'] ?? null) !== null;
            $level    = is_numeric($r['level'] ?? null) ? (int) $r['level'] : 999;
            // Меньше = лучше: квестгивер (ранг 0) важнее уровня; среди равных — ниже level.
            $score = ($hasQuest ? 0 : 1000000) + $level;
            if ($score < $bestScore) {
                $bestScore = $score;
                $bestId    = $id;
            }
        }

        return $bestId;
    }

    /**
     * Найти map-row суши по координатам (allowed biome), или null.
     *
     * @return array<int|string, mixed>|null
     */
    protected function findLandCell(int $x, int $y): ?array
    {
        $row = $this->mapModel()
            ->where('coordinate_x', $x)
            ->where('coordinate_y', $y)
            ->whereIn('biome_id', self::ALLOWED_BIOMES)
            ->first();

        return is_array($row) ? $row : null;
    }

    /** Есть ли живой спавн на клетке (cell_number) — не ставим второго NPC. */
    protected function cellOccupied(int $cellNumber): bool
    {
        return $this->spawnModel()
            ->where('cell_number', $cellNumber)
            ->where('status', 'alive')
            ->countAllResults() > 0;
    }

    /**
     * Вставить спавн встречающего на клетку (зеркало WandererSpawnHandler::fillZone insert).
     *
     * @param array<int|string, mixed> $cell map-row клетки-цели (cell_number, coordinate_x/y)
     */
    protected function insertSpawn(int $npcId, array $cell): void
    {
        $template  = $this->npcModel()->find($npcId);
        $healthRaw = is_array($template) ? ($template['health'] ?? null) : null;
        $health    = is_numeric($healthRaw) ? (float) $healthRaw : 100.0;

        $this->spawnModel()->insert([
            'npc_id'         => $npcId,
            'cell_number'    => self::intVal($cell, 'cell_number'),
            'coordinate_x'   => self::intVal($cell, 'coordinate_x'),
            'coordinate_y'   => self::intVal($cell, 'coordinate_y'),
            'current_health' => $health,
            'spawned_at'     => date('Y-m-d H:i:s'),
            'status'         => 'alive',
        ]);
    }

    protected function writeMarker(int $charId, int $chatId): void
    {
        // action_status строго из enum('Pending','Completed','Skipped','REJECTED').
        $this->logModel()->insert([
            'character_id'  => $charId,
            'chat_id'       => $chatId,
            'action_name'   => self::PLACED_FLAG,
            'action_status' => 'Completed',
            'description'   => 'ADR-144 S2: размещён встречающий-нейтрал у спавна новичка',
        ]);
    }

    // ── Текст (media-off, markdown-safe) ─────────────────────────────────────

    /**
     * @param bool $withNavHint добавить навигационный хвост. false — когда текст едет
     *                          секцией единого первого экрана (кнопка входа в мир рядом).
     */
    public function buildGreeterNarrative(string $directionLabel, string $arrow, bool $withNavHint = true): string
    {
        $text = "🧑 *Ты здесь не один.*\n\n"
            . "Роби: «Замечаю движение {$directionLabel} {$arrow} отсюда — совсем рядом кто-то есть. "
            . "Похоже, выживший, не враг. Подойди и заговори: расспроси о землях, обменяйся — "
            . "может, и делом поможет. На карте он отмечен значком 🧑.»";

        if (! $withNavHint) {
            return $text . "\n\n_Дойди до выжившего и нажми «👤 Незнакомец»._";
        }

        // Метка кнопки — из BotMenuService, НЕ хардкодом (см. ColdOpenSignalService).
        $world = \App\Services\Telegram\BotMenuService::worldButtonLabel();

        return $text . "\n\n_Жми «{$world}» в нижнем меню, дойди до выжившего и нажми «👤 Незнакомец»._";
    }

    // ── Резолверы зависимостей / хелперы ─────────────────────────────────────

    private function npcModel(): NpcModel
    {
        return $this->npcs ?? new NpcModel();
    }

    private function spawnModel(): NpcSpawnModel
    {
        return $this->spawns ?? new NpcSpawnModel();
    }

    private function mapModel(): MapModel
    {
        return $this->map ?? new MapModel();
    }

    private function logModel(): ActionLogModel
    {
        return $this->log ?? new ActionLogModel();
    }

    /**
     * @param array<int|string, mixed> $row
     */
    private static function intVal(array $row, string $key): int
    {
        $raw = $row[$key] ?? 0;

        return is_numeric($raw) ? (int) $raw : 0;
    }
}
