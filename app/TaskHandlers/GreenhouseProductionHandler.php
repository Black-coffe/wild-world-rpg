<?php

namespace App\TaskHandlers;

use App\Models\CharacterBuildingModel;
use App\Models\CharacterResourceModel;
use App\Models\BuildingModel;
use App\Models\ResourceModel;
use CodeIgniter\Controller;

class GreenhouseProductionHandler extends Controller
{
    protected $characterBuildingModel;
    protected $characterResourceModel;
    protected $buildingModel;
    protected $resourceModel;

    public function __construct()
    {
        $this->characterBuildingModel = new CharacterBuildingModel();
        $this->characterResourceModel = new CharacterResourceModel();
        $this->buildingModel = new BuildingModel(); // Изменено с ResourceModel на BuildingModel
        $this->resourceModel = new ResourceModel();
    }

    public function handle()
    {
        $greenhouseId = $this->getGreenhouseId();
        if (!$greenhouseId) {
            log_message('error', 'Greenhouse building ID not found.');
            return;
        }

        $charactersWithGreenhouse = $this->characterBuildingModel->where('building_id', $greenhouseId)->findAll();

        foreach ($charactersWithGreenhouse as $characterBuilding) {
            $characterId = $characterBuilding['character_id'];
            $this->addResourceToCharacter($characterId, 'Fruit', 2);
            $this->addResourceToCharacter($characterId, 'Berries', 1);
        }
    }

    private function getGreenhouseId()
    {
        $greenhouse = $this->buildingModel->where('name_en', 'Greenhouse')->first();
        return $greenhouse ? $greenhouse['id'] : null;
    }

    private function addResourceToCharacter($characterId, $resourceName, $quantity)
    {
        $resource = $this->resourceModel->where('name_en', $resourceName)->first();

        if (!$resource) {
            log_message('error', 'Resource ' . $resourceName . ' not found.');
            return;
        }

        $characterResource = $this->characterResourceModel->where('id_characters', $characterId)
            ->where('id_resources', $resource['id'])
            ->first();

        if ($characterResource) {
            $newQuantity = $characterResource['quantity'] + $quantity;
            $this->characterResourceModel->update($characterResource['id'], ['quantity' => $newQuantity]);
        } else {
            $this->characterResourceModel->insert([
                'id_characters' => $characterId,
                'id_resources' => $resource['id'],
                'quantity' => $quantity
            ]);
        }
    }
}
