<?php

declare(strict_types=1);

namespace App\TaskHandlers\NPC;

use App\Models\MapModel;
use App\Models\NpcModel;
use App\Models\NpcSpawnModel;

/**
 * ADR-105 — градиент опасности рейдеров «север = опаснее».
 *
 * Контекст: 8774 «Рейдеров Песчаных Волков» (npc_id=1, L15) — единый посев
 * 2025-02-18 с плотностью, РАСТУЩЕЙ к югу (Y800-пояс ≈ 1994 vs Y100 ≈ 346) —
 * обратно канон-принципу «чем севернее, тем опаснее/ценнее» (ADR-101); живого
 * крона-поддержки у hostile-популяции не было (только распад). L20 «Главарь»
 * и L25 «Гладиатор пустоши» существовали как контент, но имели 0 спавнов.
 *
 * Крон (everyMinute, singleInstance, killswitch world.raiders.gradient_enabled):
 *  1) Purge: рейдеры тиров на Y ≥ world.raiders.y_cutoff (850) удаляются ВСЕГДА —
 *     перманентная гарантия чистоты новичкового пояса (замена разовой чистки 2026-06-10).
 *  2) По каждому тиру (L15 везде / L20 только Y < l20_y_max / L25 только Y < l25_y_max)
 *     поддерживает целевую популяцию по Y-бэндам (по 100 строк) с линейным весом
 *     к северу: бэнд Y0-99 самый плотный, южные — самые редкие.
 *  3) Скорость перестройки ограничена world.raiders.max_adjust_per_tick (insert+delete
 *     суммарно за тик) — редистрибуция размазывается на ~час, без шока мира.
 *
 * Тиры захардкожены по npc_id (1/2/3) намеренно: стражи руин (24-26) тоже
 * aggressive/hostile, но размещаются SettlementPlacementHandler на фикс-клетках —
 * динамический пул по ai_behavior зацепил бы их. npc_spawns = TRANSIENT (WipeManifest).
 *
 * ADR-106 — уникальные L30+ боссы глубокого севера: тот же механизм поддержки
 * популяции, но ОТДЕЛЬНЫЙ killswitch `world.bosses.enabled` (dormant-first) и
 * резолв npc_id по npc_name_en (BOSS_KEYS — id боссов auto_increment, в коде
 * их фиксировать нельзя). По 1 живому экземпляру (population_each) в Y <
 * world.bosses.y_max (150): убитый возрождается следующим тиком в НОВОЙ случайной
 * клетке зоны — искать заново (анти-фарм).
 *
 * Анти-grind прикрытие: AutoPveHandler (v0.51.396) гейтит кулдауном пары и HP-полом,
 * так что уплотнение севера не возвращает minute-молот.
 */
class RaiderGradientHandler
{
    /** Высота Y-бэнда (строк карты). */
    public const BAND_SIZE = 100;

    /** Биомы, пригодные для спавна (как WandererSpawnHandler; без воды). */
    private const SPAWN_BIOMES = [1, 2, 3, 5, 6, 7, 8, 9];

    /**
     * Тиры градиента: npc_id => [ключ населения, ключ южной границы (null = до cutoff)].
     * L15 Рейдер — весь диапазон; L20 Главарь — север+середина; L25 Гладиатор — глубокий север.
     */
    private const TIERS = [
        1 => ['population_key' => 'world.raiders.population_l15', 'population_default' => 7000, 'ymax_key' => null, 'ymax_default' => null],
        2 => ['population_key' => 'world.raiders.population_l20', 'population_default' => 1200, 'ymax_key' => 'world.raiders.l20_y_max', 'ymax_default' => 600],
        3 => ['population_key' => 'world.raiders.population_l25', 'population_default' => 600, 'ymax_key' => 'world.raiders.l25_y_max', 'ymax_default' => 300],
    ];

    /** ADR-106 — npc_name_en уникальных боссов (id auto_increment → резолв в рантайме). */
    private const BOSS_KEYS = ['boss_scar_butcher', 'boss_cerberus_prototype', 'boss_pack_patriarch'];

    private NpcModel $npcs;
    private NpcSpawnModel $spawns;
    private MapModel $map;

    /**
     * Memo на один run() — резолв id боссов одним запросом.
     *
     * @var list<int>|null
     */
    protected ?array $bossIds = null;

    public function __construct()
    {
        $this->npcs   = new NpcModel();
        $this->spawns = new NpcSpawnModel();
        $this->map    = new MapModel();
    }

    public function run(): void
    {
        if (! $this->enabled()) {
            return; // dormant
        }

        $cutoff = $this->yCutoff();
        $budget = $this->maxAdjustPerTick();
        if ($budget <= 0) {
            return;
        }

        $bossIds = $this->bossesEnabled() ? $this->bossIds() : [];

        // 1) Перманентная новичковая гарантия: рейдеры (и боссы) на Y >= cutoff удаляются всегда.
        $budget -= $this->purgeBeyondCutoff(array_merge(array_keys(self::TIERS), $bossIds), $cutoff, $budget);

        // 2) Поддержка градиента по тирам.
        foreach (self::TIERS as $npcId => $tier) {
            if ($budget <= 0) {
                return;
            }
            $population = $this->tierPopulation($tier['population_key'], $tier['population_default']);
            $yMaxRaw    = $tier['ymax_key'] === null ? $cutoff : $this->tierYMax($tier['ymax_key'], (int) $tier['ymax_default']);
            $yMax       = min($yMaxRaw, $cutoff);

            $budget = $this->maintainPopulation($npcId, $population, $yMax, $cutoff, $budget);
        }

        // 3) ADR-106 — уникальные боссы глубокого севера (отдельный killswitch).
        if ($bossIds !== []) {
            $each = $this->bossPopulationEach();
            $yMax = min($this->bossYMax(), $cutoff);
            foreach ($bossIds as $bossId) {
                if ($budget <= 0) {
                    return;
                }
                $budget = $this->maintainPopulation($bossId, $each, $yMax, $cutoff, $budget);
            }
        }
    }

    /**
     * Поддержать популяцию одного npc в зоне [0, yMax): zone-purge залётных южнее
     * зоны (self-heal при сужении y_max) → план fill/trim по бэндам. Вернуть остаток бюджета.
     */
    protected function maintainPopulation(int $npcId, int $population, int $yMax, int $cutoff, int $budget): int
    {
        // Залётные между yMax и cutoff (cutoff-purge уже покрыл Y >= cutoff).
        if ($yMax < $cutoff) {
            $budget -= $this->purgeZone($npcId, $yMax, $cutoff, $budget);
            if ($budget <= 0) {
                return 0;
            }
        }

        $targets = self::bandTargets($population, $yMax);
        $counts  = $this->aliveCountsPerBand($npcId, $yMax);
        $plan    = self::planAdjustments($counts, $targets, $budget);

        foreach ($plan as $step) {
            $done = $step['action'] === 'fill'
                ? $this->fillBand($npcId, $step['band'], min($step['band'] + self::BAND_SIZE, $yMax), $step['count'])
                : $this->trimBand($npcId, $step['band'], min($step['band'] + self::BAND_SIZE, $yMax), $step['count']);
            $budget -= $done;
            if ($budget <= 0) {
                return 0;
            }
        }

        return $budget;
    }

    // ===================== Чистые планировщики (unit-тесты) =====================

    /**
     * Целевая популяция по Y-бэндам с линейным весом к северу.
     *
     * Бэнды по BAND_SIZE строк от Y=0 до $yMax (последний может быть неполным —
     * вес масштабируется долей строк, чтобы плотность на строку не подскакивала).
     * Вес бэнда i (0 = самый северный) = (n - i) × rows/BAND_SIZE. Остаток от
     * округления раздаётся с севера (север — приоритет градиента).
     *
     * @return array<int, int> bandStartY => target
     */
    public static function bandTargets(int $total, int $yMax): array
    {
        if ($total <= 0 || $yMax <= 0) {
            return [];
        }

        $bandCount = (int) ceil($yMax / self::BAND_SIZE);
        $weights   = [];
        $weightSum = 0.0;
        for ($i = 0; $i < $bandCount; $i++) {
            $rows        = min(self::BAND_SIZE, $yMax - $i * self::BAND_SIZE);
            $weights[$i] = ($bandCount - $i) * ($rows / self::BAND_SIZE);
            $weightSum  += $weights[$i];
        }

        $targets   = [];
        $allocated = 0;
        for ($i = 0; $i < $bandCount; $i++) {
            $t                            = (int) floor($total * $weights[$i] / $weightSum);
            $targets[$i * self::BAND_SIZE] = $t;
            $allocated                   += $t;
        }

        // Остаток — северным бэндам по +1.
        $remainder = $total - $allocated;
        for ($i = 0; $remainder > 0; $i = ($i + 1) % $bandCount) {
            $targets[$i * self::BAND_SIZE]++;
            $remainder--;
        }

        return $targets;
    }

    /**
     * План выравнивания: сначала наливы (север → юг), затем срезы (юг → север),
     * суммарно не более $budget операций. Чистая функция.
     *
     * @param array<int, int> $counts  bandStartY => живых сейчас
     * @param array<int, int> $targets bandStartY => цель
     * @return list<array{band: int, action: 'fill'|'trim', count: int}>
     */
    public static function planAdjustments(array $counts, array $targets, int $budget): array
    {
        $plan = [];

        // Наливы: север → юг (приоритет — уплотнить север).
        $bands = array_keys($targets);
        sort($bands);
        foreach ($bands as $band) {
            if ($budget <= 0) {
                break;
            }
            $deficit = $targets[$band] - ($counts[$band] ?? 0);
            if ($deficit > 0) {
                $n      = min($deficit, $budget);
                $plan[] = ['band' => $band, 'action' => 'fill', 'count' => $n];
                $budget -= $n;
            }
        }

        // Срезы: юг → север (лишнее снимаем сначала с юга).
        foreach (array_reverse($bands) as $band) {
            if ($budget <= 0) {
                break;
            }
            $excess = ($counts[$band] ?? 0) - $targets[$band];
            if ($excess > 0) {
                $n      = min($excess, $budget);
                $plan[] = ['band' => $band, 'action' => 'trim', 'count' => $n];
                $budget -= $n;
            }
        }

        return $plan;
    }

    // ===================== I/O (DB) =====================

    /**
     * Удалить живых рейдеров тиров на Y >= cutoff (новичковый пояс). Вернуть число операций.
     *
     * @param list<int> $npcIds
     */
    protected function purgeBeyondCutoff(array $npcIds, int $cutoff, int $budget): int
    {
        if ($budget <= 0) {
            return 0;
        }
        $rows = $this->spawns
            ->select('id')
            ->whereIn('npc_id', $npcIds)
            ->where('status', 'alive')
            ->where('coordinate_y >=', $cutoff)
            ->findAll($budget);

        $ids = [];
        foreach ($rows as $row) {
            $idRaw = is_array($row) ? ($row['id'] ?? null) : null;
            if (is_numeric($idRaw)) {
                $ids[] = (int) $idRaw;
            }
        }
        if ($ids === []) {
            return 0;
        }
        \Config\Database::connect()->table('npc_spawns')->whereIn('id', $ids)->delete();

        return count($ids);
    }

    /**
     * Живые спавны тира по бэндам в [0, yMax).
     *
     * @return array<int, int>
     */
    protected function aliveCountsPerBand(int $npcId, int $yMax): array
    {
        $counts = [];
        $result = \Config\Database::connect()->table('npc_spawns')
            ->select('FLOOR(coordinate_y / ' . self::BAND_SIZE . ') * ' . self::BAND_SIZE . ' AS band, COUNT(*) AS cnt', false)
            ->where('npc_id', $npcId)
            ->where('status', 'alive')
            ->where('coordinate_y <', $yMax)
            ->where('coordinate_y >=', 0)
            ->groupBy('band')
            ->get();
        $rows = $result instanceof \CodeIgniter\Database\ResultInterface ? $result->getResultArray() : [];

        foreach ($rows as $row) {
            $bandRaw = $row['band'] ?? null;
            $cntRaw  = $row['cnt'] ?? null;
            if (is_numeric($bandRaw) && is_numeric($cntRaw)) {
                $counts[(int) $bandRaw] = (int) $cntRaw;
            }
        }

        return $counts;
    }

    /** Налить $count спавнов тира в бэнд [$yFrom, $yTo). Вернуть фактически налито. */
    protected function fillBand(int $npcId, int $yFrom, int $yTo, int $count): int
    {
        if ($count <= 0) {
            return 0;
        }

        $template  = $this->npcs->find($npcId);
        $healthRaw = is_array($template) ? ($template['health'] ?? null) : null;
        $health    = is_numeric($healthRaw) ? (float) $healthRaw : 100.0;

        // Случайные пригодные клетки бэнда; запас ×3 на занятые (паттерн WandererSpawnHandler).
        $cells = $this->map
            ->where('coordinate_y >=', $yFrom)
            ->where('coordinate_y <', $yTo)
            ->whereIn('biome_id', self::SPAWN_BIOMES)
            ->orderBy('RAND()')
            ->findAll($count * 3);

        $spawned = 0;
        foreach ($cells as $cell) {
            if ($spawned >= $count) {
                break;
            }
            if (! is_array($cell)) {
                continue;
            }
            $cellRaw    = $cell['cell_number'] ?? null;
            $cellNumber = is_numeric($cellRaw) ? (int) $cellRaw : 0;
            if ($cellNumber <= 0) {
                continue;
            }
            // Не ставим второго NPC на занятую клетку.
            if ($this->spawns->where('cell_number', $cellNumber)->where('status', 'alive')->countAllResults() > 0) {
                continue;
            }
            $xRaw = $cell['coordinate_x'] ?? null;
            $yRaw = $cell['coordinate_y'] ?? null;

            $this->spawns->insert([
                'npc_id'         => $npcId,
                'cell_number'    => $cellNumber,
                'coordinate_x'   => is_numeric($xRaw) ? (int) $xRaw : 0,
                'coordinate_y'   => is_numeric($yRaw) ? (int) $yRaw : 0,
                'current_health' => $health,
                'spawned_at'     => date('Y-m-d H:i:s'),
                'status'         => 'alive',
            ]);
            $spawned++;
        }

        return $spawned;
    }

    /** Срезать $count случайных живых спавнов тира в бэнде [$yFrom, $yTo). Вернуть фактически срезано. */
    protected function trimBand(int $npcId, int $yFrom, int $yTo, int $count): int
    {
        if ($count <= 0) {
            return 0;
        }
        $rows = $this->spawns
            ->select('id')
            ->where('npc_id', $npcId)
            ->where('status', 'alive')
            ->where('coordinate_y >=', $yFrom)
            ->where('coordinate_y <', $yTo)
            ->orderBy('RAND()')
            ->findAll($count);

        $ids = [];
        foreach ($rows as $row) {
            $idRaw = is_array($row) ? ($row['id'] ?? null) : null;
            if (is_numeric($idRaw)) {
                $ids[] = (int) $idRaw;
            }
        }
        if ($ids === []) {
            return 0;
        }
        \Config\Database::connect()->table('npc_spawns')->whereIn('id', $ids)->delete();

        return count($ids);
    }

    /** Удалить живых спавнов npc в [$yFrom, $yTo) — залётные южнее зоны тира. Вернуть число операций. */
    protected function purgeZone(int $npcId, int $yFrom, int $yTo, int $budget): int
    {
        return $this->trimBand($npcId, $yFrom, $yTo, $budget);
    }

    /**
     * ADR-106 — npc_id боссов по npc_name_en (id auto_increment, в коде не фиксируемы).
     * Overridable seam (тесты). Memo на один run().
     *
     * @return list<int>
     */
    protected function bossIds(): array
    {
        if ($this->bossIds !== null) {
            return $this->bossIds;
        }
        $ids = [];
        foreach ($this->npcs->whereIn('npc_name_en', self::BOSS_KEYS)->findAll() as $n) {
            $idRaw = is_array($n) ? ($n['id'] ?? null) : null;
            if (is_numeric($idRaw)) {
                $ids[] = (int) $idRaw;
            }
        }

        return $this->bossIds = $ids;
    }

    // ===================== GameSettings (overridable seams) =====================

    /**
     * ADR-106 killswitch world.bosses.enabled (default false → боссы dormant).
     *
     * WB4 (ADR-137 «Узлы»): когда включён point-режим (`world.nodes.point_mode_enabled`),
     * старый КОЧУЮЩИЙ спавнер боссов УСТУПАЕТ NodeRespawnHandler'у — иначе двойной спавн
     * (трио в случайных Y<150 + точки-узлы). Одна правда. Откат = point_mode OFF → старое
     * поведение возвращается.
     */
    protected function bossesEnabled(): bool
    {
        $gs = new \App\Services\GameSettings\GameSettingsService();
        if ((bool) $gs->get('world.nodes.point_mode_enabled', false)) {
            return false; // point-режим узлов ведёт боссов сам (WB5)
        }

        return (bool) $gs->get('world.bosses.enabled', false);
    }

    /** world.bosses.population_each — живых экземпляров КАЖДОГО босса (default 1 = уникальность). */
    protected function bossPopulationEach(): int
    {
        $v = (new \App\Services\GameSettings\GameSettingsService())->get('world.bosses.population_each', 1);

        return is_numeric($v) ? max(0, (int) $v) : 1;
    }

    /** world.bosses.y_max — южная граница зоны боссов (default 150, кромка мира у руин). */
    protected function bossYMax(): int
    {
        $v = (new \App\Services\GameSettings\GameSettingsService())->get('world.bosses.y_max', 150);

        return is_numeric($v) ? max(1, (int) $v) : 150;
    }

    /** Killswitch world.raiders.gradient_enabled (default false → dormant). */
    protected function enabled(): bool
    {
        $v = (new \App\Services\GameSettings\GameSettingsService())->get('world.raiders.gradient_enabled', false);

        return (bool) $v;
    }

    /** world.raiders.y_cutoff — с этого Y и южнее рейдеров нет (default 850). */
    protected function yCutoff(): int
    {
        $v = (new \App\Services\GameSettings\GameSettingsService())->get('world.raiders.y_cutoff', 850);

        return is_numeric($v) ? max(1, (int) $v) : 850;
    }

    /** world.raiders.max_adjust_per_tick — лимит insert+delete за тик (default 200). */
    protected function maxAdjustPerTick(): int
    {
        $v = (new \App\Services\GameSettings\GameSettingsService())->get('world.raiders.max_adjust_per_tick', 200);

        return is_numeric($v) ? max(0, (int) $v) : 200;
    }

    /** Целевая популяция тира. */
    protected function tierPopulation(string $key, int $default): int
    {
        $v = (new \App\Services\GameSettings\GameSettingsService())->get($key, $default);

        return is_numeric($v) ? max(0, (int) $v) : $default;
    }

    /** Южная граница тира (Y, эксклюзивно). */
    protected function tierYMax(string $key, int $default): int
    {
        $v = (new \App\Services\GameSettings\GameSettingsService())->get($key, $default);

        return is_numeric($v) ? max(1, (int) $v) : $default;
    }
}
