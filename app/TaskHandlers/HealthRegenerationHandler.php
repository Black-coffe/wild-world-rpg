<?php

namespace App\TaskHandlers;

use App\Models\CharacterModel;
use App\Models\CharacterBuildingModel;
use App\Models\CharacterResourceModel;

class HealthRegenerationHandler
{
    protected $characterModel;
    protected $characterBuildingModel;
    protected $characterResourceModel;

    public function __construct()
    {
        $this->characterModel = new CharacterModel();
        $this->characterBuildingModel = new CharacterBuildingModel();
        $this->characterResourceModel = new CharacterResourceModel();
    }

    public function process()
    {
        $characters = $this->characterModel->findAll();

        foreach ($characters as $character) {
            // Регенерация здоровья
            if ($character['health'] < 100.0) {
                $newHealth = min(100.0, $character['health'] + 0.05); // Увеличиваем здоровье на 0.05 единицы за цикл, но не более 100
                $this->characterModel->update($character['id'], ['health' => $newHealth]);
            }

            // Регенерация усталости
            if ($character['tired'] < 100.0) {
                $newTired = min(100.0, $character['tired'] + 0.1); // Увеличиваем усталость на 0.1 единицы за цикл, но не более 100
                $this->characterModel->update($character['id'], ['tired' => $newTired]);
            }

            // Пересчёт уровня персонажа может остаться без изменений
            $level = $this->calculateLevel($character);
            $this->characterModel->update($character['id'], ['level' => $level]);
        }
    }

    private function calculateLevel($character)
    {
        $total = $character['experience'] + $character['strength'] + $character['agility'] + $character['intellect'];
        $average = $total / 4;
        $level = floor($average);

        return max(1, $level); // Гарантируем, что уровень не опустится ниже 1
    }
}
