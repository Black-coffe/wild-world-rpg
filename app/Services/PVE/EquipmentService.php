<?php

declare(strict_types=1);

namespace App\Services\PVE;

use App\Models\CharactersOutfitsModel;
use App\Models\OutfitModel;
use App\Models\CharactersWeaponsModel;
use App\Models\WeaponModel;
use App\Services\Craft\ItemModifierService;
use Psr\Log\LoggerInterface;
use App\Entities\BattleCharacter;

class EquipmentService
{
    private LoggerInterface $logger;
    private ItemModifierService $itemModifiers;

    public function __construct(LoggerInterface $logger, ?ItemModifierService $itemModifiers = null)
    {
        $this->logger        = $logger;
        $this->itemModifiers = $itemModifiers ?? new ItemModifierService();
    }

    /**
     * Получает бонусы от экипировки (броня, оружие) для персонажа.
     *
     * @param int $characterId
     * @return array Массив бонусов:
     *         - armor_bonus: суммарный бонус брони (float)
     *         - weapon_damage: базовый урон оружия (float)
     *         - attack_speed_bonus: бонус к скорости атаки (float)
     */
    public function getEquipmentBonuses(int $characterId): array
    {
        $bonus = [
            'armor_bonus'        => 0.0,
            'weapon_damage'      => 0.0,
            'attack_speed_bonus' => 0.0,
        ];

        // Получаем экипированные предметы брони
        $charactersOutfitsModel = new CharactersOutfitsModel();
        $outfitModel = new OutfitModel();
        $equippedOutfits = $charactersOutfitsModel->where('character_id', $characterId)
            ->where('equipped', 1)
            ->findAll();

        if (!empty($equippedOutfits)) {
            foreach ($equippedOutfits as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $oid = is_numeric($item['outfit_id'] ?? null) ? (int) $item['outfit_id'] : 0;
                if ($oid <= 0) {
                    continue;
                }
                $outfit = $outfitModel->find($oid);
                if (is_array($outfit)) {
                    // W20 (ADR-075): per-instance модификатор брони (детерм.; dormant ×1.0).
                    $rawCoId = $item['id'] ?? null;
                    $coId    = is_numeric($rawCoId) ? (int) $rawCoId : 0;
                    $mult    = $this->itemModifiers->armorMultiplier($coId);
                    // Бонус брони берется из поля armor_value
                    $av = is_numeric($outfit['armor_value'] ?? null) ? (float) $outfit['armor_value'] : 0.0;
                    $bonus['armor_bonus'] += $av * $mult;
                }
            }
        }

        // Получаем экипированное оружие (если есть)
        $charactersWeaponsModel = new CharactersWeaponsModel();
        $weaponModel = new WeaponModel();
        $equippedWeapon = $charactersWeaponsModel->where('character_id', $characterId)
            ->where('equipped', 1)
            ->first();
        if ($equippedWeapon) {
            $weapon = $weaponModel->find($equippedWeapon['weapon_id']);
            if ($weapon) {
                // W19 (ADR-074): per-instance модификатор урона (детерм.; dormant = ×1.0).
                $cwId = 0;
                if (is_array($equippedWeapon)) {
                    $rawCwId = $equippedWeapon['id'] ?? null;
                    $cwId    = is_numeric($rawCwId) ? (int) $rawCwId : 0;
                }
                $mult = $this->itemModifiers->weaponDamageMultiplier($cwId);
                $bonus['weapon_damage'] = (float)$weapon['damage_value'] * $mult;
                $bonus['attack_speed_bonus'] = (float)$weapon['attack_speed'];
                // Дополнительно можно учитывать критический шанс, armor_penetration и т.д.
            }
        }

        $this->logger->debug("Equipment bonuses for character {$characterId}: " . json_encode($bonus));
        return $bonus;
    }

    /**
     * Применяет бонусы от экипировки к объекту BattleCharacter.
     *
     * @param BattleCharacter $character
     */
    public function applyEquipmentBonuses(BattleCharacter $character): void
    {
        $bonuses = $this->getEquipmentBonuses($character->id);

        // Если экипировано оружие, базовый урон заменяется значением из оружия
        if ($bonuses['weapon_damage'] > 0) {
            $character->damageValue = $bonuses['weapon_damage'];
        }

        // Устанавливаем бонус брони и бонус скорости атаки
        $character->armorBonus = $bonuses['armor_bonus'];
        $character->attackSpeedBonus = $bonuses['attack_speed_bonus'];
    }
}
