<?php

namespace App\Services\Player;

use App\Models\CharacterModel;

/**
 * Сервис, который отвечает за логику проверки:
 *  - сколько стоит телепорт (1000 × уровень)
 *  - достаточно ли золота
 *  - при необходимости списывает золото
 */
class TeleportCostService
{
    /**
     * Возвращает стоимость телепорта, исходя из уровня персонажа.
     *
     * @param int $level Уровень персонажа
     * @return int Стоимость (в золоте).
     */
    public function calculateTeleportCost(int $level): int
    {
        // Формула: 1000 × уровень
        return 1000 * $level;
    }

    /**
     * Проверяет, достаточно ли золота у персонажа для телепорта.
     *
     * @param array $characterRow Персонаж (содержит 'level' и 'gold').
     * @return bool true, если золота достаточно, иначе false.
     */
    public function canPayTeleport(array $characterRow): bool
    {
        $cost = $this->calculateTeleportCost((int)$characterRow['level']);
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

        $cost = $this->calculateTeleportCost((int)$character['level']);
        if ((int)$character['gold'] < $cost) {
            return false;
        }

        // Списываем
        $newGold = $character['gold'] - $cost;
        $characterModel->update($characterId, ['gold' => $newGold]);
        return true;
    }
}
