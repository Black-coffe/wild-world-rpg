<?php

declare(strict_types=1);

namespace App\Services\PVE;

use App\Services\GameSettings\GameSettingsService;
use Config\Database;
use Throwable;

/**
 * S26 (v0.51.207, ADR-030) — defensive structures в PvP.
 *
 * Защита применяется ТОЛЬКО когда защитник (атакуемый игрок) стоит на своей
 * клетке, где у него построены active (hp>0) defensive-структуры
 * (WoodenWall / BarbedFence). character_buildings.map_cell_id = cell_number,
 * выставляется при постройке = «у своей базы». claimed_cells join не нужен:
 * наличие своих структур на текущей клетке уже означает «дома».
 *
 * Эффекты ДЕТЕРМИНИРОВАННЫЕ (round loop под RNG-fence — никакого mt_rand):
 *   - WoodenWall: −damage_reduction% урона по защитнику (сумма, клампится cap).
 *   - BarbedFence: flat урон атакующему за каждый раунд боя у базы.
 *   - WatchTower (S26b, ADR-031): +initiative_bonus защитнику-владельцу у базы
 *     (presence-based). Alert-range detection вышки — отдельно (TowerAlertService).
 * Combat-балАнсы — из GameSettings (live-tunable). hp decays за каждую атаку.
 */
final class DefenseStructureService
{
    private GameSettingsService $settings;

    public function __construct(?GameSettingsService $settings = null)
    {
        $this->settings = $settings ?? new GameSettingsService();
    }

    /**
     * Профиль защиты для защитника на его текущей клетке, либо null.
     *
     * S26b (ADR-031): + `initiative_bonus` от WatchTower (presence-based — не
     * стакает по числу вышек, иначе build-N-towers эксплойт). Tower-only база
     * тоже возвращает профиль (initiative_bonus>0, damage_reduction=0).
     *
     * @return array{owner_id:int, damage_reduction:float, fence_damage:int, initiative_bonus:float, structure_ids:list<int>}|null
     */
    public function getDefenseProfile(int $defenderId, int $defenderCellNumber): ?array
    {
        if ($defenderId <= 0 || $defenderCellNumber <= 0) {
            return null;
        }

        $rows = $this->activeStructures($defenderId, $defenderCellNumber);
        if ($rows === []) {
            return null;
        }

        $wallPercent = $this->intSetting('defense.wall.damage_reduction_percent', 15);
        $fencePerRnd = $this->intSetting('defense.fence.attacker_damage_per_round', 3);
        $capPercent  = $this->intSetting('defense.total_damage_reduction_max_percent', 40);
        $towerInit   = $this->intSetting('defense.tower.defender_initiative_bonus_percent', 8);

        $sumWallPercent = 0;
        $sumFence       = 0;
        $hasTower       = false;
        $maxTowerLevel  = 1;
        $structureIds   = [];
        foreach ($rows as $r) {
            $nameEn = is_string($r['name_en'] ?? null) ? $r['name_en'] : '';
            $idRaw  = $r['id'] ?? 0;
            $id     = is_numeric($idRaw) ? (int) $idRaw : 0;
            if ($id <= 0) {
                continue;
            }
            // ADR-041: per-level scaling. levelMult=1.0 при L1 → no-op (фикстуры целы).
            $lvlRaw = $r['level'] ?? 1;
            $level  = is_numeric($lvlRaw) ? max(1, (int) $lvlRaw) : 1;
            $mult   = $this->levelMult($level);
            if ($nameEn === 'WoodenWall') {
                $sumWallPercent += (int) round($wallPercent * $mult);
            } elseif ($nameEn === 'BarbedFence') {
                $sumFence += (int) round($fencePerRnd * $mult);
            } elseif ($nameEn === 'WatchTower') {
                $hasTower      = true;
                $maxTowerLevel = max($maxTowerLevel, $level);
            }
            $structureIds[] = $id;
        }

        if ($structureIds === []) {
            return null;
        }

        $cappedPercent = min($sumWallPercent, max(0, $capPercent));
        $towerScaled   = $hasTower ? max(0, (int) round($towerInit * $this->levelMult($maxTowerLevel))) : 0;

        return [
            'owner_id'         => $defenderId,
            'damage_reduction' => $cappedPercent / 100.0,
            'fence_damage'     => $sumFence,
            'initiative_bonus' => $towerScaled / 100.0,
            'structure_ids'    => $structureIds,
        ];
    }

    /**
     * Decay: −defense.decay_hp_per_attack HP каждой структуре после боя у базы.
     * hp клампится в 0 (сломанная структура hp=0 → не учитывается в getDefenseProfile).
     *
     * @param list<int> $structureIds
     */
    public function applyDecay(array $structureIds): void
    {
        if ($structureIds === []) {
            return;
        }
        $decay = $this->intSetting('defense.decay_hp_per_attack', 10);
        if ($decay <= 0) {
            return;
        }
        try {
            $db = Database::connect();
            $db->table('character_buildings')
                ->whereIn('id', $structureIds)
                ->set('hp', 'GREATEST(0, hp - ' . $decay . ')', false)
                ->update();
        } catch (Throwable $e) {
            log_message('error', '[DefenseStructureService] applyDecay failed: ' . $e->getMessage());
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function activeStructures(int $defenderId, int $cellNumber): array
    {
        try {
            $db    = Database::connect();
            $query = $db->table('character_buildings cb')
                ->select('cb.id, cb.hp, cb.level, b.name_en')
                ->join('buildings b', 'b.id = cb.building_id')
                ->where('cb.character_id', $defenderId)
                ->where('cb.map_cell_id', $cellNumber)
                ->where('cb.building_type', 'defensive')
                ->where('cb.hp >', 0)
                ->get();
            if ($query === false) {
                return [];
            }
            /** @var list<array<string,mixed>> $rows */
            $rows = $query->getResultArray();
            return $rows;
        } catch (Throwable $e) {
            log_message('error', '[DefenseStructureService] activeStructures failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * ADR-041: множитель эффекта/maxHp за уровень структуры.
     * levelMult = 1 + per_level_percent%×(level−1). ОБЯЗАТЕЛЬНО ×1.0 при level=1
     * (no-op для существующих PvP-фикстур под RNG-fence).
     */
    private function levelMult(int $level): float
    {
        $level    = max(1, $level);
        $perLevel = $this->intSetting('defense.scaling.per_level_percent', 20);
        return 1.0 + ($perLevel / 100.0) * ($level - 1);
    }

    /**
     * ADR-041: максимальный hp оборонной структуры с учётом уровня.
     * $templateHp = buildings.hp (стартовый L1). При level=1 → templateHp (no-op).
     */
    public function maxHpFor(int $templateHp, int $level): int
    {
        return (int) round(max(0, $templateHp) * $this->levelMult($level));
    }

    /**
     * ADR-041: int-значение combat-ключа, отмасштабированное по уровню структуры
     * (для превью эффекта на экране здания). При level=1 → базовое значение.
     */
    public function scaledInt(string $key, int $default, int $level): int
    {
        return (int) round($this->intSetting($key, $default) * $this->levelMult($level));
    }

    private function intSetting(string $key, int $default): int
    {
        $v = $this->settings->get($key, $default);
        return is_numeric($v) ? (int) $v : $default;
    }
}
