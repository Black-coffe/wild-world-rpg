<?php

namespace App\Services\Player;

use App\Models\CharacterModel;
use App\Services\BuildingEffects\BuildingEffectsService;

/**
 * Сервис, который отвечает за логику проверки:
 *  - сколько стоит телепорт (1000 × уровень × TC-мультиплікатор)
 *  - достаточно ли золота
 *  - при необходимости списывает золото
 *
 * S15 (v0.51.197): додано optional $charId у `calculateTeleportCost()` для
 * застосування TeleportationCenter level discount через BuildingEffectsService.
 * Без $charId — baseline формула (для legacy preview/info actions).
 */
class TeleportCostService
{
    private BuildingEffectsService $buildingEffects;

    public function __construct(?BuildingEffectsService $buildingEffects = null)
    {
        $this->buildingEffects = $buildingEffects ?? new BuildingEffectsService();
    }

    /**
     * Возвращает стоимость телепорта.
     *
     * Базова формула: `1000 × уровень_персонажа`. Якщо передано $charId, ще
     * застосовується TeleportationCenter level multiplier (S15):
     * L2 → 0.85 (-15%), L3 → 0.70 (-30%), L4-L10 cascade на L3.
     *
     * @param int      $level  Уровень персонажа
     * @param int|null $charId Optional: для застосування TC-discount
     * @return int Стоимость (в золоте, round'нута до int).
     */
    public function calculateTeleportCost(int $level, ?int $charId = null): int
    {
        $base = 1000 * $level;
        if ($charId === null) {
            return $base;
        }
        $multiplier = $this->buildingEffects->getTeleportCostMultiplier($charId);
        return max(1, (int) round($base * $multiplier));
    }

    /**
     * Проверяет, достаточно ли золота у персонажа для телепорта.
     *
     * @param array|\App\Entities\CharacterEntity $characterRow Персонаж (содержит 'level', 'gold', 'id').
     * @return bool true, если золота достаточно, иначе false.
     */
    public function canPayTeleport(array|\App\Entities\CharacterEntity $characterRow): bool
    {
        $charId = isset($characterRow['id']) ? (int) $characterRow['id'] : null;
        $cost   = $this->calculateTeleportCost((int)$characterRow['level'], $charId);
        return ((int)$characterRow['gold'] >= $cost);
    }

    /**
     * Списывает золото за телепорт (если возможно).
     * Возвращает true, если успешно, false — если недостаточно золота.
     */
    public function payTeleportIfPossible(int $characterId): bool
    {
        $characterModel = new CharacterModel();
        $character = $characterModel->find($characterId);
        if (!$character) {
            return false;
        }

        $cost = $this->calculateTeleportCost((int)$character['level'], $characterId);
        if ((int)$character['gold'] < $cost) {
            return false;
        }

        // Списываем
        $newGold = $character['gold'] - $cost;
        $characterModel->update($characterId, ['gold' => $newGold]);
        return true;
    }
}
