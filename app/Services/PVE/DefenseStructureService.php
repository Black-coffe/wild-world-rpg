<?php

declare(strict_types=1);

namespace App\Services\PVE;

use App\Services\GameSettings\GameSettingsService;
use App\Services\Player\DroneService;
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
 *   - W5 (ADR-064): Combat drone — defensive time-window initiative_bonus (НЕ tied
 *     к базе; layer'ится через characters.combat_drone_active_until > NOW). Стек с
 *     tower-bonus → min(sum, drone.combat.max_combined_initiative_percent).
 * Combat-балАнсы — из GameSettings (live-tunable). hp decays за каждую атаку.
 */
final class DefenseStructureService
{
    private GameSettingsService $settings;
    private DroneService $droneService;

    public function __construct(?GameSettingsService $settings = null, ?DroneService $droneService = null)
    {
        $this->settings     = $settings ?? new GameSettingsService();
        $this->droneService = $droneService ?? new DroneService($this->settings);
    }

    /**
     * Профиль защиты для защитника на его текущей клетке, либо null.
     *
     * S26b (ADR-031): + `initiative_bonus` от WatchTower (presence-based — не
     * стакает по числу вышек, иначе build-N-towers эксплойт). Tower-only база
     * тоже возвращает профиль (initiative_bonus>0, damage_reduction=0).
     *
     * W5 (ADR-064): + combat-drone bonus (НЕ tied к базе; читает
     * characters.combat_drone_active_until). Суммируется с tower-bonus и clamp'ится
     * по drone.combat.max_combined_initiative_percent. Drone-only сценарий (нет
     * структур, но активен combat-дрон) тоже возвращает профиль (initiative_bonus
     * от drone, damage_reduction=0, fence_damage=0, structure_ids=[]).
     *
     * @return array{owner_id:int, damage_reduction:float, fence_damage:int, initiative_bonus:float, structure_ids:list<int>}|null
     */
    public function getDefenseProfile(int $defenderId, int $defenderCellNumber): ?array
    {
        if ($defenderId <= 0 || $defenderCellNumber <= 0) {
            return null;
        }

        $rows = $this->activeStructures($defenderId, $defenderCellNumber);
        $droneInitPercent = $this->combatDroneActiveBonusPercent($defenderId);

        // Если нет структур И combat-drone bonus = 0 → нет защитного профиля.
        if ($rows === [] && $droneInitPercent <= 0) {
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
            // ADR-102 Ф3: стак обороны по количеству на базе (amount) с diminishing
            // returns. killswitch OFF / amount≤1 → ×1.0 (byte-identical). Вышка НЕ
            // стакает (presence, анти-эксплойт «настрой N вышек»).
            $amtRaw = $r['amount'] ?? 1;
            $amount = is_numeric($amtRaw) ? max(1, (int) $amtRaw) : 1;
            $stack  = $this->defenseStackFactor($amount);
            if ($nameEn === 'WoodenWall') {
                $sumWallPercent += (int) round($wallPercent * $mult * $stack);
            } elseif ($nameEn === 'BarbedFence') {
                $sumFence += (int) round($fencePerRnd * $mult * $stack);
            } elseif ($nameEn === 'WatchTower') {
                $hasTower      = true;
                $maxTowerLevel = max($maxTowerLevel, $level);
            }
            $structureIds[] = $id;
        }

        // Без структур и без drone-bonus уже отсеяли выше; если структур нет, но drone active —
        // profile с damage_reduction=0/fence_damage=0/structure_ids=[].
        $cappedPercent = min($sumWallPercent, max(0, $capPercent));
        $towerScaled   = $hasTower ? max(0, (int) round($towerInit * $this->levelMult($maxTowerLevel))) : 0;

        // W5 (ADR-064): combined initiative bonus = min(tower + drone, max_combined). RNG-fence safe.
        $combinedInitPercent = $towerScaled + max(0, $droneInitPercent);
        $maxCombined         = $this->droneService->combatMaxCombinedInitiativePercent();
        if ($maxCombined > 0 && $combinedInitPercent > $maxCombined) {
            $combinedInitPercent = $maxCombined;
        }

        return [
            'owner_id'         => $defenderId,
            'damage_reduction' => $cappedPercent / 100.0,
            'fence_damage'     => $sumFence,
            'initiative_bonus' => $combinedInitPercent / 100.0,
            'structure_ids'    => $structureIds,
        ];
    }

    /**
     * W5 (ADR-064): возвращает текущий combat-drone initiative bonus (в процентах)
     * для defender'а если активен (NOW < combat_drone_active_until И killswitch on).
     * Иначе 0. NO mt_rand — pure DB read.
     */
    public function combatDroneActiveBonusPercent(int $defenderId): int
    {
        if ($defenderId <= 0 || ! $this->droneService->combatIsEnabled()) {
            return 0;
        }
        try {
            $db    = Database::connect();
            $query = $db->table('characters')
                ->select('combat_drone_active_until')
                ->where('id', $defenderId)
                ->where('combat_drone_active_until >', date('Y-m-d H:i:s'))
                ->get();
            if ($query === false) {
                return 0;
            }
            $row = $query->getRowArray();
            if (! is_array($row)) {
                return 0;
            }
            return $this->droneService->combatInitiativeBonusPercent();
        } catch (Throwable $e) {
            log_message('error', '[DefenseStructureService] combatDroneActiveBonusPercent failed: ' . $e->getMessage());
            return 0;
        }
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
                ->select('cb.id, cb.hp, cb.level, cb.amount, b.name_en')
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
     * ADR-102 Ф3: множитель стака обороны по количеству структур на базе (amount).
     * Diminishing returns (геометрический ряд): factor = (1 − r^amount)/(1 − r),
     * клампится defense.stack.max_factor. killswitch defense.stack.enabled=OFF или
     * amount≤1 → ×1.0 (byte-identical). r = defense.stack.diminish_ratio (0.6):
     * amount 1→1.0, 2→1.6, 3→1.96, 4→2.18, ∞→1/(1−r)=2.5. Глобальный cap снижения
     * урона (40%) применяется ПОСЛЕ — реальный потолок обороны. Вышка сюда не
     * попадает (presence). Детерминированно (RNG-fence safe).
     */
    public function defenseStackFactor(int $amount): float
    {
        if ($amount <= 1) {
            return 1.0;
        }
        if (! $this->boolSetting('defense.stack.enabled', false)) {
            return 1.0;
        }
        $rRaw = $this->settings->get('defense.stack.diminish_ratio', 0.6);
        $r    = is_numeric($rRaw) ? (float) $rRaw : 0.6;
        $r    = max(0.0, min(0.99, $r));
        $maxRaw = $this->settings->get('defense.stack.max_factor', 2.5);
        $max    = is_numeric($maxRaw) ? (float) $maxRaw : 2.5;

        $sum  = 1.0;
        $term = 1.0;
        for ($k = 1; $k < $amount; $k++) {
            $term *= $r;
            $sum  += $term;
        }
        return $sum > $max ? $max : $sum;
    }

    /**
     * ADR-102 Ф3: включён ли стак обороны (для UI-превью на экране оборонного здания).
     */
    public function isStackEnabled(): bool
    {
        return $this->boolSetting('defense.stack.enabled', false);
    }

    /**
     * Глобальный потолок снижения урона в % (для UI-превью). Default 40.
     */
    public function totalReductionCapPercent(): int
    {
        return $this->intSetting('defense.total_damage_reduction_max_percent', 40);
    }

    private function boolSetting(string $key, bool $default): bool
    {
        $v = $this->settings->get($key, $default);
        if (is_bool($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return (int) $v === 1;
        }
        return $v === 'true' || $v === '1';
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
