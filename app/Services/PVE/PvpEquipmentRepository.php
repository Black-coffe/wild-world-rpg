<?php

declare(strict_types=1);

namespace App\Services\PVE;

use App\Models\CharactersOutfitsModel;
use App\Models\CharactersWeaponsModel;
use App\Models\CharacterFactionModel;
use App\Models\FactionModel;
use App\Models\MapModel;
use App\Models\OutfitModel;
use App\Models\WeaponModel;
use App\Services\Craft\ItemModifierService;

/**
 * F2.3b Step 1 — DB reads для PvP боя.
 *
 * Извлечено из AttackPlayerAction v0.10.0 (1087 LOC god-class).
 * Изолирует все read-only обращения к БД, которые делает PvP flow:
 *   - getEquippedWeapon  — characters_weapons + weapons (1+1 SQL)
 *   - getEquippedOutfitsWithDetails — N+1 fix (1 SQL вместо 1+N)
 *   - getMapCell         — map по cell_number
 *   - getCharacterFaction — character_factions + factions
 *
 * После Step 1 формулы (computeArmorResistance, computeDistance,
 * computeEquipmentDamage, computeDamage) пока остаются в
 * AttackPlayerAction — они переедут в PvpDamageCalculator на Step 2.
 *
 * N+1 FIX (outfits): legacy делал 1 SQL на characters_outfits + N SQL
 * на outfits.find() per slot. Полная экипировка = 4-5 outfits = до 6 SQL
 * за расчёт защиты на каждый удар. После: 2 SQL независимо от количества
 * слотов.
 *
 * Контракт сохранён 1:1 с legacy:
 *   getEquippedWeapon — same shape (damage_value, range_value, damage_type,
 *     rarity, crit_chance) с тем же fallback на 'crit_chance' через
 *     special_effect.
 *   getEquippedOutfitsWithDetails — массив outfit-строк (без обёртки eq).
 */
final class PvpEquipmentRepository
{
    private CharactersWeaponsModel $charactersWeaponsModel;
    private WeaponModel $weaponsModel;
    private CharactersOutfitsModel $charactersOutfitsModel;
    private OutfitModel $outfitsModel;
    private MapModel $mapModel;
    private CharacterFactionModel $characterFactionModel;
    private FactionModel $factionModel;
    private ItemModifierService $itemModifiers;

    public function __construct(
        ?CharactersWeaponsModel $charactersWeaponsModel = null,
        ?WeaponModel $weaponsModel = null,
        ?CharactersOutfitsModel $charactersOutfitsModel = null,
        ?OutfitModel $outfitsModel = null,
        ?MapModel $mapModel = null,
        ?CharacterFactionModel $characterFactionModel = null,
        ?FactionModel $factionModel = null,
        ?ItemModifierService $itemModifiers = null
    ) {
        $this->charactersWeaponsModel = $charactersWeaponsModel ?? new CharactersWeaponsModel();
        $this->weaponsModel           = $weaponsModel           ?? new WeaponModel();
        $this->charactersOutfitsModel = $charactersOutfitsModel ?? new CharactersOutfitsModel();
        $this->outfitsModel           = $outfitsModel           ?? new OutfitModel();
        $this->mapModel               = $mapModel               ?? new MapModel();
        $this->characterFactionModel  = $characterFactionModel  ?? new CharacterFactionModel();
        $this->factionModel           = $factionModel           ?? new FactionModel();
        $this->itemModifiers          = $itemModifiers          ?? new ItemModifierService();
    }

    /**
     * Возвращает эфективные характеристики экипированного оружия.
     * Контракт идентичен AttackPlayerAction::getEquippedWeapon v0.10.0:
     *   - null если нет equipped=1 в characters_weapons или нет weapon row
     *   - иначе: ['damage_value', 'range_value', 'damage_type', 'rarity', 'crit_chance']
     *   - crit_chance = 10.0 если у weapon есть special_effect, иначе 0.0
     */
    public function getEquippedWeapon(int $characterId): ?array
    {
        $row = $this->charactersWeaponsModel
            ->where('character_id', $characterId)
            ->where('equipped', 1)
            ->first();
        if (!$row) {
            return null;
        }

        $weapon = $this->weaponsModel->find($row['weapon_id']);
        if (!$weapon) {
            return null;
        }

        // W19 (ADR-074): per-instance модификатор урона. Детерминированный множитель;
        // при dormant killswitch = 1.0 БЕЗ запроса item_modifiers → fence byte-equivalent.
        $cwId = 0;
        if (is_array($row)) {
            $rawCwId = $row['id'] ?? null;
            $cwId    = is_numeric($rawCwId) ? (int) $rawCwId : 0;
        }
        $damageMult = $this->itemModifiers->weaponDamageMultiplier($cwId);

        return [
            'damage_value' => (float) $weapon['damage_value'] * $damageMult,
            'range_value'  => (float) $weapon['range_value'],
            'damage_type'  => $weapon['damage_type'],
            'rarity'       => $weapon['rarity'],
            'crit_chance'  => isset($weapon['special_effect']) ? 10.0 : 0.0,
        ];
    }

    /**
     * F2.3b N+1 FIX: один whereIn вместо foreach->find().
     *
     * Возвращает массив outfit-строк (с полями physical/fire/poison_resistance),
     * соответствующих equipped=1 слотам персонажа. Если нет — пустой массив.
     */
    public function getEquippedOutfitsWithDetails(int $characterId): array
    {
        $equipped = $this->charactersOutfitsModel
            ->where('character_id', $characterId)
            ->where('equipped', 1)
            ->findAll();
        if (empty($equipped)) {
            return [];
        }

        $outfitIds = array_column($equipped, 'outfit_id');
        return $this->outfitsModel
            ->whereIn('id', $outfitIds)
            ->findAll();
    }

    /**
     * Map row по cell_number (для distance + close-cell check).
     */
    public function getMapCell(int $cellNumber): ?array
    {
        $row = $this->mapModel->where('cell_number', $cellNumber)->first();
        return $row ?: null;
    }

    /**
     * Фракция персонажа (опционально — может быть null).
     */
    public function getCharacterFaction(int $characterId): ?array
    {
        $cf = $this->characterFactionModel->where('character_id', $characterId)->first();
        if (!$cf) {
            return null;
        }
        $faction = $this->factionModel->find($cf['faction_id']);
        return $faction ?: null;
    }
}
