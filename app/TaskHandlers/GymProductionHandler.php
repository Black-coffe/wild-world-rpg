<?php

namespace App\TaskHandlers;

use App\Models\CharacterModel;
use App\Models\CharacterBuildingModel;
use App\Models\BuildingModel;
use CodeIgniter\Controller;

class GymProductionHandler extends Controller
{
    protected $characterModel;
    protected $characterBuildingModel;
    protected $buildingModel;

    public function __construct()
    {
        $this->characterModel = new CharacterModel();
        $this->characterBuildingModel = new CharacterBuildingModel();
        $this->buildingModel = new BuildingModel();
    }

    public function handle()
    {
        // Получаем текущее время
        $currentMinute = (int)date('i');

        // Проверяем, делится ли текущее количество минут на 5
        if ($currentMinute % 5 !== 0) {
            return;
        }

        $gymId = $this->getGymId();
        if (!$gymId) {
            log_message('error', 'Gym building ID not found.');
            return;
        }

        $charactersWithGym = $this->characterBuildingModel->where('building_id', $gymId)->findAll();

        foreach ($charactersWithGym as $characterBuilding) {
            $characterId = $characterBuilding['character_id'];
            $this->addStrengthToCharacter($characterId);
        }
    }

    private function getGymId()
    {
        $gym = $this->buildingModel->where('name_en', 'Gym')->first();
        return $gym ? $gym['id'] : null;
    }

    private function addStrengthToCharacter($characterId)
    {
        $character = $this->characterModel->find($characterId);
        if (!$character) {
            log_message('error', 'Character ID ' . $characterId . ' not found.');
            return;
        }

        $newStrength = $character['strength'] + 0.01;
        $this->characterModel->update($characterId, ['strength' => $newStrength]);
    }
}
