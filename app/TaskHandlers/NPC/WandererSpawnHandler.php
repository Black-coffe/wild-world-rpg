<?php

declare(strict_types=1);

namespace App\TaskHandlers\NPC;

use App\Models\MapModel;
use App\Models\NpcModel;
use App\Models\NpcSpawnModel;
use App\Services\NPC\NpcInteractionService;

/**
 * ADR-089 Фаза 1 — спавнер нейтральных NPC-странников (ai_behavior='passive').
 *
 * Cron (everyMinute, singleInstance). Гейт killswitch `npc.interaction_enabled` → dormant
 * = полный no-op (0 спавнов). При ON поддерживает целевую популяцию `npc.wanderer_population`
 * живых passive-спавнов на населённых ярусах (Y 401-900). Деспавн — при взаимодействии
 * (бой/грабёж убирают спавн). Транзиентны: npc_spawns = TRANSIENT в WipeManifest.
 *
 * ADR-104 Ф3a — второй проход для НОВИЧКОВОЙ зоны (Y≥`npc.newbie_zone.y_min`, default 900):
 * новичок спавнится Y≥900, а основная зона блукачей Y401-900 → не пересекаются, новичок
 * не встречает NPC. При `npc.newbie_zone.population`>0 топим до этого числа passive-нейтралов
 * в южной зоне (реюз пула, безопасно — AutoPveHandler пропускает passive). default 0 → dormant.
 */
class WandererSpawnHandler
{
    private NpcModel $npcs;
    private NpcSpawnModel $spawns;
    private MapModel $map;
    private NpcInteractionService $interaction;

    public function __construct()
    {
        $this->npcs        = new NpcModel();
        $this->spawns      = new NpcSpawnModel();
        $this->map         = new MapModel();
        $this->interaction = new NpcInteractionService();
    }

    public function run(): void
    {
        if (! $this->interactionEnabled()) {
            return; // dormant
        }

        ['neutral' => $neutralIds, 'faction' => $factionIds] = $this->loadPools();
        $poolAll = array_merge($neutralIds, $factionIds);
        if ($poolAll === []) {
            return;
        }

        $ratio = $this->factionRatio();

        // Основная зона (населённые ярусы Y 401-900) — поведение ADR-089 без изменений.
        $this->fillZone($poolAll, $neutralIds, $factionIds, $ratio, $this->targetPopulation(), 401, 900);

        // ADR-104 Ф3a — новичковая зона (Y≥y_min). dormant при population=0.
        $newbieTarget = $this->newbieZonePopulation();
        if ($newbieTarget > 0) {
            $this->fillZone($poolAll, $neutralIds, $factionIds, $ratio, $newbieTarget, $this->newbieZoneYMin(), null);
        }
    }

    /** Killswitch взаимодействия (ADR-089). Overridable seam (тесты). */
    protected function interactionEnabled(): bool
    {
        return $this->interaction->enabled();
    }

    /**
     * ID passive-шаблонов (исключая именных — ADR-089 Ф5+, они не масс-спавнятся). Один
     * запрос (сохраняет legacy-фильтр != 'named'), сплит в PHP: ADR-099 фракционные рядовые
     * (npc_type='faction') в отдельный под-пул, остальное passive-non-named = истинные нейтралы.
     * Overridable seam (тесты — без БД на CI).
     *
     * @return array{neutral: list<int>, faction: list<int>}
     */
    protected function loadPools(): array
    {
        $neutralIds = [];
        $factionIds = [];
        foreach ($this->npcs->where('ai_behavior', 'passive')->where('npc_type !=', 'named')->findAll() as $n) {
            if (! is_array($n)) {
                continue;
            }
            $idRaw = $n['id'] ?? null;
            if (! is_numeric($idRaw)) {
                continue;
            }
            $id = (int) $idRaw;
            if (($n['npc_type'] ?? '') === 'faction') {
                $factionIds[] = $id;
            } else {
                $neutralIds[] = $id;
            }
        }

        return ['neutral' => $neutralIds, 'faction' => $factionIds];
    }

    /**
     * Дополнить зону [$yMin, $yMax] passive-нейтралами до $target живых. $yMax=null → без
     * верхней границы (новичковая зона). Налив ≤10 за тик. Overridable seam (тесты).
     *
     * @param list<int> $poolAll
     * @param list<int> $neutralIds
     * @param list<int> $factionIds
     */
    protected function fillZone(array $poolAll, array $neutralIds, array $factionIds, int $ratio, int $target, int $yMin, ?int $yMax): void
    {
        if ($target <= 0 || $poolAll === []) {
            return;
        }

        // Живые passive-спавны пула в этой зоне (npc_spawns.coordinate_y, без джойна).
        $aliveQ = $this->spawns->whereIn('npc_id', $poolAll)->where('status', 'alive')->where('coordinate_y >=', $yMin);
        if ($yMax !== null) {
            $aliveQ->where('coordinate_y <=', $yMax);
        }
        $alive   = (int) $aliveQ->countAllResults();
        $deficit = $target - $alive;
        if ($deficit <= 0) {
            return;
        }
        $deficit = min($deficit, 10); // не более 10 за тик (плавный налив)

        $cellsQ = $this->map
            ->where('coordinate_y >=', $yMin)
            ->whereIn('biome_id', [1, 2, 3, 5, 6, 7, 8, 9]);
        if ($yMax !== null) {
            $cellsQ->where('coordinate_y <=', $yMax);
        }
        $cells = $cellsQ->orderBy('RAND()')->findAll($deficit * 3); // запас на занятые клетки
        if ($cells === []) {
            return;
        }

        $spawned = 0;
        foreach ($cells as $cell) {
            if ($spawned >= $deficit) {
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

            // ADR-099 — на каждый спавн выбираем под-пул по доле фракционных (ratio%).
            $useFaction = self::pickFaction((int) mt_rand(0, 99), $ratio, $factionIds !== [], $neutralIds !== []);
            $pool       = $useFaction ? $factionIds : $neutralIds;
            $npcId      = $pool[array_rand($pool)];
            $template   = $this->npcs->find($npcId);
            $healthRaw  = is_array($template) ? ($template['health'] ?? null) : null;
            $health     = is_numeric($healthRaw) ? (float) $healthRaw : 100.0;
            $xRaw       = $cell['coordinate_x'] ?? null;
            $yRaw       = $cell['coordinate_y'] ?? null;

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
    }

    /** ADR-104 Ф3a — целевая популяция блукачей в новичковой зоне. Default 0 → dormant. */
    protected function newbieZonePopulation(): int
    {
        $v = (new \App\Services\GameSettings\GameSettingsService())->get('npc.newbie_zone.population', 0);

        return is_numeric($v) ? max(0, (int) $v) : 0;
    }

    /** ADR-104 Ф3a — нижняя граница Y новичковой зоны. Default 900. */
    protected function newbieZoneYMin(): int
    {
        $v = (new \App\Services\GameSettings\GameSettingsService())->get('npc.newbie_zone.y_min', 900);

        return is_numeric($v) ? (int) $v : 900;
    }

    protected function targetPopulation(): int
    {
        $v = (new \App\Services\GameSettings\GameSettingsService())->get('npc.wanderer_population', 25);

        return is_numeric($v) ? (int) $v : 25;
    }

    /** ADR-099 — доля фракционных рядовых в спавне (%), 0-100. Default 0 → dormant. */
    protected function factionRatio(): int
    {
        $v = (new \App\Services\GameSettings\GameSettingsService())->get('npc.faction_wanderer_ratio', 0);
        $i = is_numeric($v) ? (int) $v : 0;

        return max(0, min(100, $i));
    }

    /**
     * ADR-099 — выбрать ли фракционный под-пул для очередного спавна (чистая, тестируемая).
     * $roll ∈ [0,99], $ratioPercent ∈ [0,100]. Нет фракционных → всегда нейтрал;
     * нет нейтралов → всегда фракционный; иначе фракционный при roll < ratio.
     */
    public static function pickFaction(int $roll, int $ratioPercent, bool $hasFaction, bool $hasNeutral): bool
    {
        if (! $hasFaction) {
            return false;
        }
        if (! $hasNeutral) {
            return true;
        }

        return $roll < max(0, min(100, $ratioPercent));
    }
}
