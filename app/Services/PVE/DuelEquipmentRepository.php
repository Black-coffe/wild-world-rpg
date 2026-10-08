<?php

declare(strict_types=1);

namespace App\Services\PVE;

/**
 * duel-baseline-weapon (поправка ADR-071) — снаряжение бойца в дуэли.
 *
 * Дуэль подаёт неизменному `PvpRoundOrchestrator::simulateFight` (ADR-070, RNG-fence) то же, что
 * {@see PvpEquipmentRepository}, кроме оружия: без оружия или со слабым боец бьёт базовым оружием
 * (`pvp.duel.baseline_weapon_damage`), своё сильнее бьёт `база + вес × (своё − база)`
 * (`pvp.duel.weapon_advantage_weight`). «Сильнее» — по урону с редкостью (`damage_value × K_rar`), то
 * есть по тому, что умножает формула. Броня, клетки, фракция — без изменений (декоратор над настоящим
 * репозиторием). Новых `mt_rand` нет: крит своего оружия сохраняется, бросок крита движок делает всегда.
 *
 * Наследование — только ради типа параметра `PvpDamageCalculator`; родительский конструктор не
 * вызывается, поэтому переопределён каждый публичный метод родителя (держит тест).
 */
final class DuelEquipmentRepository extends PvpEquipmentRepository
{
    public function __construct(
        private readonly PvpEquipmentRepository $inner,
        private readonly float $baseDamage,
        private readonly float $weight,
        private readonly PvpFormulaService $formulas = new PvpFormulaService()
    ) {
    }

    /**
     * @return array{damage_value: float, range_value: float, damage_type: string, rarity: string, crit_chance: float}
     */
    public function getEquippedWeapon(int $characterId): array
    {
        return self::duelWeapon($this->inner->getEquippedWeapon($characterId), $this->baseDamage, $this->weight, $this->formulas);
    }

    /**
     * @return array<mixed>
     */
    public function getEquippedOutfitsWithDetails(int $characterId): array
    {
        return $this->inner->getEquippedOutfitsWithDetails($characterId);
    }

    /**
     * @return array<mixed>|null
     */
    public function getMapCell(int $cellNumber): ?array
    {
        return $this->inner->getMapCell($cellNumber);
    }

    /**
     * @return array<mixed>|null
     */
    public function getCharacterFaction(int $characterId): ?array
    {
        return $this->inner->getCharacterFaction($characterId);
    }

    /**
     * Оружие бойца в дуэли. Результат уже несёт редкость в уроне, поэтому `rarity` = Common (K = 1).
     *
     * @param array<string, mixed>|null $own оружие из {@see PvpEquipmentRepository::getEquippedWeapon()}
     *
     * @return array{damage_value: float, range_value: float, damage_type: string, rarity: string, crit_chance: float}
     */
    public static function duelWeapon(?array $own, float $baseDamage, float $weight, PvpFormulaService $formulas): array
    {
        $base = [
            'damage_value' => $baseDamage,
            'range_value'  => 1.0,
            'damage_type'  => 'Physical',
            'rarity'       => 'Common',
            'crit_chance'  => 0.0,
        ];
        if ($own === null) {
            return $base;
        }

        $rawDamage = $own['damage_value'] ?? null;
        $rawRarity = $own['rarity'] ?? null;
        $ownDamage = (is_numeric($rawDamage) ? (float) $rawDamage : 0.0)
            * $formulas->getRarityCoefficient(is_string($rawRarity) ? $rawRarity : 'Common');
        if ($ownDamage <= $baseDamage) {
            return $base;
        }

        $rawRange = $own['range_value'] ?? null;
        $rawType  = $own['damage_type'] ?? null;
        $rawCrit  = $own['crit_chance'] ?? null;

        return [
            'damage_value' => $baseDamage + max(0.0, min(1.0, $weight)) * ($ownDamage - $baseDamage),
            'range_value'  => is_numeric($rawRange) ? (float) $rawRange : 1.0,
            'damage_type'  => is_string($rawType) && $rawType !== '' ? $rawType : 'Physical',
            'rarity'       => 'Common',
            'crit_chance'  => is_numeric($rawCrit) ? (float) $rawCrit : 0.0,
        ];
    }
}
