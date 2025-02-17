<?php

namespace App\Services\PVE;

use App\Models\CharactersOutfitsModel;
use App\Models\OutfitModel;
use App\Models\CharactersWeaponsModel;
use App\Models\WeaponModel;
use Psr\Log\LoggerInterface;
use App\Entities\CharacterEntity;

class EquipmentService
{
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
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
                $outfit = $outfitModel->find($item['outfit_id']);
                if ($outfit) {
                    // Бонус брони берется из поля armor_value
                    $bonus['armor_bonus'] += (float)$outfit['armor_value'];
                    // Дополнительно можно учитывать stealth_modifier, speed_modifier и т.д.
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
                $bonus['weapon_damage'] = (float)$weapon['damage_value'];
                $bonus['attack_speed_bonus'] = (float)$weapon['attack_speed'];
                // Дополнительно можно учитывать критический шанс, armor_penetration и т.д.
            }
        }

        $this->logger->debug("Equipment bonuses for character {$characterId}: " . json_encode($bonus));
        return $bonus;
    }

    /**
     * Применяет бонусы от экипировки к объекту CharacterEntity.
     *
     * @param CharacterEntity $character
     */
    public function applyEquipmentBonuses(CharacterEntity $character): void
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
