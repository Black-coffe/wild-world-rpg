<?php

namespace App\TaskHandlers\Built;

use App\Models\CharacterModel;
use App\Models\CharacterBuildingModel;
use App\Models\BuildingModel;
use App\TaskHandlers\BaseTaskHandler;

/**
 * v0.51.19 (F2.9 batch-1): extends BaseTaskHandler (per F2.9 contract).
 * Раніше extends Controller — handler НЕ контроллер.
 * Bonus: namespace casing fixed (`app\` → `App\` per PSR-4).
 * `handle()` → `handle(array $task = []): void` (TaskHandlerInterface signature).
 */
class GymProductionHandler extends BaseTaskHandler
{
    protected $characterModel;
    protected $characterBuildingModel;
    protected $buildingModel;

    /**
     * Таблица соответствия уровня спортзала и прибавки к силе (раз в цикл).
     */
    private $gymLevels = [
        1  => 0.01,
        2  => 0.02,
        3  => 0.03,
        4  => 0.04,
        5  => 0.07,
        6  => 0.09,
        7  => 0.11,
        8  => 0.12,
        9  => 0.14,
        10 => 0.15,
    ];

    public function __construct()
    {
        $this->characterModel         = new CharacterModel();
        $this->characterBuildingModel = new CharacterBuildingModel();
        $this->buildingModel          = new BuildingModel();
    }

    /**
     * Вызывается по крону. Срабатывает раз в 30 минут.
     *
     * @param array<string,mixed> $task TaskHandlerInterface signature (recurring tasks
     *                                  не приймають task data).
     */
    public function handle(array $task = []): void
    {
        // Проверяем, делится ли текущее количество минут на 5
        $currentMinute = (int) date('i');
        if ($currentMinute % 30 !== 0) {
            return; // не время
        }

        // Ищем ID здания "Gym"
        $gymId = $this->getGymId();
        if (!$gymId) {
            log_message('error', '[GymProductionHandler] Gym building ID not found in DB.');
            return;
        }

        // Получаем все character_buildings для Gym
        $gymBuildings = $this->characterBuildingModel
            ->where('building_id', $gymId)
            ->findAll();

        foreach ($gymBuildings as $buildingRow) {
            // Сначала проверяем налог
            if ($buildingRow['tax_collection_status'] !== 'SUCCESS') {
                // Налог не уплачен => нет бонуса
                continue;
            }

            // Определяем уровень спортзала
            $level = (int) $buildingRow['level'];
            if (!isset($this->gymLevels[$level])) {
                log_message('error', "[GymProductionHandler] Invalid level {$level} for building_id={$buildingRow['id']}");
                continue;
            }

            $strengthIncrement = $this->gymLevels[$level];

            // Получаем персонажа
            $characterId = $buildingRow['character_id'];
            $character = $this->characterModel->find($characterId);
            if (!$character) {
                log_message('error', "[GymProductionHandler] Character ID {$characterId} not found.");
                continue;
            }

            // Прибавляем силу
            $this->addStrengthToCharacter($characterId, $strengthIncrement);
        }
    }

    /**
     * Ищет ID спортзала ("Gym") в таблице buildings.
     */
    private function getGymId(): ?int
    {
        $gym = $this->buildingModel->where('name_en', 'Gym')->first();
        return $gym ? (int) $gym['id'] : null;
    }

    /**
     * Увеличивает силу (strength) персонажу на заданную величину.
     */
    private function addStrengthToCharacter(int $characterId, float $increment): void
    {
        $character = $this->characterModel->find($characterId);
        if (!$character) {
            log_message('error', "[GymProductionHandler] Character ID {$characterId} not found at addStrengthToCharacter.");
            return;
        }

        $newStrength = $character['strength'] + $increment;
        $this->characterModel->update($characterId, ['strength' => $newStrength]);
    }
}