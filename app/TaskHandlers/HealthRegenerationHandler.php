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
        $this->characterModel         = new CharacterModel();
        $this->characterBuildingModel = new CharacterBuildingModel();
        $this->characterResourceModel = new CharacterResourceModel();
    }

    public function process()
    {
        $characters = $this->characterModel->findAll();

        foreach ($characters as $character) {

            // 1) Сначала вычислим (или обновим) уровень персонажа
            $level = $this->calculateLevel($character);
            // Запишем новый уровень (если изменился):
            $this->characterModel->update($character['id'], ['level' => $level]);

            // 2) Если уровень >= 20, пропускаем регенерацию
            if ($level >= 20) {
                // Ничего не восстанавливаем, переходим к следующему персонажу
                continue;
            }

            // 3) Обычная логика регенерации, если уровень < 20
            // Регенерация здоровья
            if ($character['health'] < 100.0) {
                $newHealth = min(100.0, $character['health'] + 0.05);
                $this->characterModel->update($character['id'], ['health' => $newHealth]);
            }

            // Регенерация усталости
            if ($character['tired'] < 100.0) {
                $newTired = min(100.0, $character['tired'] + 0.1);
                $this->characterModel->update($character['id'], ['tired' => $newTired]);
            }
        }
    }

    /**
     * Пример простейшего подсчёта уровня на основе (experience + strength + agility + intellect) / 4
     */
    private function calculateLevel(array $character): int
    {
        $total = $character['experience']
            + $character['strength']
            + $character['agility']
            + $character['intellect'];

        $average = $total / 4.0;
        $level   = floor($average);

        return max(1, (int)$level);
    }
}
