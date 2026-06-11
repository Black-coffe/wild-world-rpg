<?php

declare(strict_types=1);

namespace App\TaskHandlers\NPC;

use App\Attributes\HandlerKey;
use App\Models\MapModel;
use App\Models\NpcModel;
use App\Models\NpcSpawnModel;
use App\Services\GameSettings\GameSettingsReaderTrait;
use App\TaskHandlers\BaseTaskHandler;

/**
 * E15 (ADR-115) — спавн элитных NPC в средней полосе Y300-600.
 *
 * Зеркало боссовой части {@see RaiderGradientHandler} (резолв npc_id по npc_name_en, поддержка
 * population_each живых на тир, спавн в случайных пригодных клетках пояса). Элиты (L30/40/50) —
 * вызов между рейдерами (L15-25) и боссами (L30-35), дропают «Древние реликвии» (компонент E14).
 *
 * Анти-фарм: фикс-популяция + убитая элита (AutoPveHandler удаляет spawn) → респавн в НОВОЙ
 * случайной клетке. «Север ценнее»: тир L50 спавнится в северной трети пояса, L40 — в двух третях,
 * L30 — во всём поясе (тир-смещение y_max).
 *
 * Killswitch `world.elites.enabled` (default OFF → no-op). Класс НЕ final, seam'ы overridable.
 */
#[HandlerKey(
    key: 'elite_spawn',
    displayName: 'Элитные NPC: спавн в средней полосе (E15)',
    description: 'everyMinute + killswitch world.elites.enabled: поддерживает population_each элиток L30/40/50 в поясе Y300-600 (север ценнее), респавн в новой клетке = анти-фарм. ADR-115.',
)]
class EliteSpawnHandler extends BaseTaskHandler
{
    use GameSettingsReaderTrait;

    private const SPAWN_BIOMES = [1, 2, 3, 5, 6, 7, 8, 9];

    /**
     * Элиты по npc_name_en + север-смещение (доля пояса с юга, что ОТСЕКАЕТСЯ → тир уходит севернее).
     * L30 — весь пояс (0.0), L40 — без южной трети (0.33), L50 — северная треть (0.66).
     */
    private const ELITES = [
        ['key' => 'elite_armored_marauder', 'north_cut' => 0.0],
        ['key' => 'elite_void_raider',      'north_cut' => 0.34],
        ['key' => 'elite_steel_reaper',     'north_cut' => 0.67],
    ];

    protected NpcModel $npcs;
    protected NpcSpawnModel $spawns;
    protected MapModel $map;

    public function __construct()
    {
        $this->npcs   = new NpcModel();
        $this->spawns = new NpcSpawnModel();
        $this->map    = new MapModel();
    }

    public function handle(array $task = []): void
    {
        $this->run();
    }

    public function run(): void
    {
        if (! $this->gsBool('world.elites.enabled', false)) {
            return;
        }

        $popEach = max(0, $this->gsInt('world.elites.population_each', 3));
        $yMin    = $this->gsInt('world.elites.y_min', 300);
        $yMax    = $this->gsInt('world.elites.y_max', 600);
        $budget  = max(1, $this->gsInt('world.elites.max_adjust_per_tick', 30));
        if ($popEach === 0 || $yMax <= $yMin) {
            return;
        }

        $ops    = 0;
        $idByKey = $this->eliteIdsByKey();
        $span    = $yMax - $yMin;

        foreach (self::ELITES as $elite) {
            if ($ops >= $budget) {
                break;
            }
            $npcId = $idByKey[$elite['key']] ?? 0;
            if ($npcId <= 0) {
                continue;
            }
            // Тир-смещение к северу: отсекаем южную долю пояса.
            $tierYMax = $yMax - (int) round($span * $elite['north_cut']);
            if ($tierYMax <= $yMin) {
                $tierYMax = $yMin + 1;
            }

            $alive   = $this->spawns->where('npc_id', $npcId)->where('status', 'alive')->countAllResults();
            $deficit = $popEach - (int) $alive;
            if ($deficit <= 0) {
                continue;
            }
            $ops += $this->fillBelt($npcId, $yMin, $tierYMax, min($deficit, $budget - $ops));
        }
    }

    /**
     * Налить $count элит тира в пояс [$yFrom, $yTo) на случайные пригодные незанятые клетки.
     * Зеркало RaiderGradientHandler::fillBand. Вернуть фактически налито.
     */
    protected function fillBelt(int $npcId, int $yFrom, int $yTo, int $count): int
    {
        if ($count <= 0) {
            return 0;
        }
        $template  = $this->npcs->find($npcId);
        $healthRaw = is_array($template) ? ($template['health'] ?? null) : null;
        $health    = is_numeric($healthRaw) ? (float) $healthRaw : 100.0;

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
            $cellNumber = is_numeric($cell['cell_number'] ?? null) ? (int) $cell['cell_number'] : 0;
            if ($cellNumber <= 0) {
                continue;
            }
            if ($this->spawns->where('cell_number', $cellNumber)->where('status', 'alive')->countAllResults() > 0) {
                continue;
            }
            $this->spawns->insert([
                'npc_id'         => $npcId,
                'cell_number'    => $cellNumber,
                'coordinate_x'   => is_numeric($cell['coordinate_x'] ?? null) ? (int) $cell['coordinate_x'] : 0,
                'coordinate_y'   => is_numeric($cell['coordinate_y'] ?? null) ? (int) $cell['coordinate_y'] : 0,
                'current_health' => $health,
                'spawned_at'     => date('Y-m-d H:i:s'),
                'status'         => 'alive',
            ]);
            $spawned++;
        }

        return $spawned;
    }

    /**
     * npc_id элиток по npc_name_en (id auto_increment, в коде не фиксируемы). Overridable (тесты).
     *
     * @return array<string,int>
     */
    protected function eliteIdsByKey(): array
    {
        $keys = array_column(self::ELITES, 'key');
        $out  = [];
        foreach ($this->npcs->whereIn('npc_name_en', $keys)->findAll() as $n) {
            if (is_array($n) && is_string($n['npc_name_en'] ?? null) && is_numeric($n['id'] ?? null)) {
                $out[$n['npc_name_en']] = (int) $n['id'];
            }
        }
        return $out;
    }
}
