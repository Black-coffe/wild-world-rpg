<?php

namespace App\TaskHandlers\Built;

use CodeIgniter\Controller;
use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Models\CharacterResourceModel;
use App\Models\CharacterModel;
use App\Models\ResourceModel;
use App\Models\ClaimedCellModel;
use App\Models\MapModel;
use App\Models\BiomeModel;

/**
 * Хендлер для производства воды «Ручной скважиной» с учётом биома.
 * Вызывается по крону (например, каждую минуту).
 */
class HandPumpProductionHandler extends Controller
{
    protected $buildingModel;
    protected $characterBuildingModel;
    protected $characterResourceModel;
    protected $characterModel;
    protected $resourceModel;
    protected $claimedCellModel;
    protected $mapModel;
    protected $biomeModel;

    /**
     * Таблица соответствия уровня скважины и базового количества воды, добываемой за цикл.
     */
    private $handPumpLevels = [
        1  => 1,
        2  => 2,
        3  => 3,
        4  => 4,
        5  => 7,
        6  => 9,
        7  => 11,
        8  => 14,
        9  => 17,
        10 => 20,
    ];

    /**
     * Пример простых коэффициентов для разных типов биома (biome_type).
     * Можно корректировать под ваши нужды.
     */
    private $biomeTypeMultipliers = [
        'wet'      => 1.2,  // реки, леса, тропики — воды больше
        'cold'     => 0.9,  // горы, тундра
        'dry'      => 0.5,  // пустыни
        'volcanic' => 0.7,  // вулканические
        'cave'     => 0.6,  // пещеры
        'plain'    => 1.0,  // равнины
        // остальные при желании
    ];

    public function __construct()
    {
        $this->buildingModel          = new BuildingModel();
        $this->characterBuildingModel = new CharacterBuildingModel();
        $this->characterResourceModel = new CharacterResourceModel();
        $this->characterModel         = new CharacterModel();
        $this->resourceModel          = new ResourceModel();
        $this->claimedCellModel       = new ClaimedCellModel();
        $this->mapModel               = new MapModel();
        $this->biomeModel             = new BiomeModel();
    }

    /**
     * Главный метод, вызываемый по крону.
     * 1. Находит ID здания «HandPump».
     * 2. Ищет все character_buildings c таким building_id.
     * 3. Проверяет налог (tax_collection_status).
     * 4. Определяет биом, вычисляет множитель.
     * 5. Начисляет воду (с учётом биом-множителя), гарантируя минимум 1.
     */
    public function handle()
    {
        // 1. Находим ID здания "HandPump"
        $handPumpId = $this->getHandPumpId();
        if (!$handPumpId) {
            log_message('error', '[HandPumpProductionHandler] "HandPump" building not found in DB.');
            return;
        }

        // 2. Ищем все character_buildings, указывающие на HandPump
        $handPumpBuildings = $this->characterBuildingModel
            ->where('building_id', $handPumpId)
            ->findAll();

        if (empty($handPumpBuildings)) {
            // Никто не построил "Ручную скважину"
            return;
        }

        // 3. Для каждого персонажа с ручной скважиной проверяем налог и начисляем воду.
        foreach ($handPumpBuildings as $build) {
            // Проверим налог
            if ($build['tax_collection_status'] !== 'SUCCESS') {
                // Налог не уплачен => не начисляем воду
                continue;
            }

            // Определяем уровень (1..10)
            $level = (int)$build['level'];
            if (!isset($this->handPumpLevels[$level])) {
                log_message('error', "[HandPumpProductionHandler] Invalid level {$level} for building_id={$build['id']}");
                continue;
            }

            // Базовое кол-во воды
            $baseWater = $this->handPumpLevels[$level];

            // 4. Определяем множитель на основе биома персонажа
            //    Для этого смотрим, где у него "active" лагерь (или просто берем первую запись).
            $characterId = $build['character_id'];
            $character   = $this->characterModel->find($characterId);
            if (!$character) {
                log_message('error', "[HandPumpProductionHandler] Character {$characterId} not found.");
                continue;
            }

            // Получаем текущую ячейку (активная) из claimed_cells
            $claimedCell = $this->claimedCellModel
                ->where('character_id', $characterId)
                ->where('status', 'active')
                ->first();
            if (!$claimedCell) {
                // У персонажа нет активного лагеря, пропускаем
                continue;
            }

            $mapCellId = $claimedCell['map_cell_id'];
            // Смотрим в таблицу map
            $mapRow = $this->mapModel->find($mapCellId);
            if (!$mapRow) {
                log_message('error', "[HandPumpProductionHandler] Map cell {$mapCellId} not found for character {$characterId}.");
                continue;
            }

            $biomeId = $mapRow['biome_id'];
            $biome   = $this->biomeModel->find($biomeId);
            if (!$biome) {
                // Неизвестный биом
                log_message('error', "[HandPumpProductionHandler] Biome {$biomeId} not found for map cell {$mapCellId}.");
                continue;
            }

            // Получаем множитель
            $multiplier = $this->getBiomeMultiplier($biome);

            // Итоговое кол-во воды = baseWater * multiplier
            // Округляем вниз, но гарантируем минимум 1
            $finalWater = (int) floor($baseWater * $multiplier);
            if ($finalWater < 1) {
                $finalWater = 1;
            }

            // 5. Пытаемся найти ресурс "Water" и начисляем воду
            $this->addWaterToCharacter($characterId, $finalWater);
        }
    }

    /**
     * Возвращает ID здания "HandPump" (по name_en).
     */
    private function getHandPumpId(): ?int
    {
        $handPump = $this->buildingModel
            ->where('name_en', 'HandPump')
            ->first();
        return $handPump ? (int)$handPump['id'] : null;
    }

    /**
     * Возвращаем множитель, исходя из типа биома.
     * По умолчанию = 1.0, если вдруг тип не найден.
     *
     * F1.4.1: signature widened — BiomeModel тепер returnType = BiomeEntity,
     * а тести passing raw arrays. ArrayAccess trait у Entity робить $biome['biome_type'] working для обох.
     *
     * @param array<string, mixed>|\App\Entities\BiomeEntity $biome
     */
    private function getBiomeMultiplier(array|\App\Entities\BiomeEntity $biome): float
    {
        $type = $biome['biome_type'] ?? '';
        return $this->biomeTypeMultipliers[$type] ?? 1.0;
    }

    /**
     * Начисляет воду (целое число) персонажу.
     */
    private function addWaterToCharacter(int $characterId, int $amount): void
    {
        // Сначала ищем ресурс "Water" по name_en
        $waterResource = $this->resourceModel->where('name_en', 'Water')->first();
        if (!$waterResource) {
            log_message('error', '[HandPumpProductionHandler] Resource "Water" not found in DB.');
            return;
        }

        // Ищем, есть ли вода уже
        $charRes = $this->characterResourceModel
            ->where('id_characters', $characterId)
            ->where('id_resources', $waterResource['id'])
            ->first();

        if ($charRes) {
            // Увеличиваем quantity
            $newQty = $charRes['quantity'] + $amount;
            $this->characterResourceModel->update($charRes['id'], ['quantity' => $newQty]);
        } else {
            // Создаем новую запись
            $this->characterResourceModel->insert([
                'id_characters' => $characterId,
                'id_resources'  => $waterResource['id'],
                'quantity'      => $amount,
            ]);
        }
    }
}
