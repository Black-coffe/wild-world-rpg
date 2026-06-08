<?php

namespace App\TaskHandlers\Built;

use App\Attributes\HandlerKey;
use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Models\CharacterResourceModel;
use App\Models\CharacterModel;
use App\Models\ResourceModel;
use App\Models\ClaimedCellModel;
use App\Models\MapModel;
use App\Models\BiomeModel;
use App\TaskHandlers\BaseTaskHandler;
use App\Services\GameSettings\GameSettingsReaderTrait;
use Config\GameBalance;

/**
 * Хендлер для производства воды «Ручной скважиной» с учётом биома.
 * Вызывается по крону каждую минуту.
 *
 * v0.51.19 (F2.9 batch-1): extends BaseTaskHandler (per F2.9 contract).
 * Раніше extends Controller — handler НЕ контроллер.
 * `handle()` → `handle(array $task = []): void` (TaskHandlerInterface signature).
 *
 * v0.51.24 (C/F6 expansion): handPumpLevels + handPumpBiomeMultipliers
 * читаються через config('GameBalance'). Раніше hardcoded private arrays.
 */
#[HandlerKey(
    key: 'handpump_production',
    displayName: 'Производство Ручной скважины',
    description: 'Recurring (Tasks.php every minute): ручная скважина производит воду с учётом биомного множителя.',
)]
class HandPumpProductionHandler extends BaseTaskHandler
{
    use GameSettingsReaderTrait;

    protected $buildingModel;
    protected $characterBuildingModel;
    protected $characterResourceModel;
    protected $characterModel;
    protected $resourceModel;
    protected $claimedCellModel;
    protected $mapModel;
    protected $biomeModel;
    private GameBalance $cfg;

    public function __construct(?GameBalance $cfg = null)
    {
        $this->cfg = $cfg ?? config('GameBalance');
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
     *
     * @param array<string,mixed> $task TaskHandlerInterface signature (recurring tasks
     *                                  не приймають task data).
     */
    public function handle(array $task = []): void
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

        // v0.51.25 perf: lift Water resource lookup outside loop (loop-invariant).
        // Раніше: N pumps × 1 Water query (всередині addWaterToCharacter) = N queries.
        // Тепер: 1 query, передаємо $waterResource['id'] у addWaterToCharacter.
        $waterResource = $this->resourceModel->where('name_en', 'Water')->first();
        if (!$waterResource) {
            log_message('error', '[HandPumpProductionHandler] Resource "Water" not found in DB.');
            return;
        }
        $waterResourceId = (int) $waterResource['id'];

        // 3. Для каждого персонажа с ручной скважиной проверяем налог и начисляем воду.
        foreach ($handPumpBuildings as $build) {
            // Проверим налог
            if ($build['tax_collection_status'] !== 'SUCCESS') {
                // Налог не уплачен => не начисляем воду
                continue;
            }

            // Определяем уровень (1..10)
            $level = (int)$build['level'];
            if (!isset($this->cfg->handPumpLevels[$level])) {
                log_message('error', "[HandPumpProductionHandler] Invalid level {$level} for building_id={$build['id']}");
                continue;
            }

            // Базовое кол-во воды
            $baseWater = $this->cfg->handPumpLevels[$level];

            // 4. Множитель по биому КЛЕТКИ, где стоит скважина (ADR-102: per-base —
            //    биом из map_cell_id строки скважины, а НЕ из активной базы игрока;
            //    для одно-базовых это та же клетка → byte-identical).
            $characterId = $build['character_id'];
            $mapCellId   = is_numeric($build['map_cell_id'] ?? null) ? (int) $build['map_cell_id'] : 0;
            if ($mapCellId <= 0) {
                continue;
            }
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
            // F: round() вместо floor() — биом-множитель работает и на низких уровнях
            // (раньше floor занижал дробные значения, min 1).
            $finalWater = (int) round($baseWater * $multiplier);
            if ($finalWater < 1) {
                $finalWater = 1;
            }

            // 5. Начисляем воду (Water resource id pre-loaded outside loop)
            $this->addWaterToCharacter($characterId, $finalWater, $waterResourceId);
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
        $typeRaw = $biome['biome_type'] ?? '';
        $type    = is_string($typeRaw) ? $typeRaw : '';
        // D: GameSettings live-tunable биом-множитель, fallback на GameBalance.
        $default = $this->cfg->handPumpBiomeMultipliers[$type] ?? 1.0;
        return $this->gsFloat('building.handpump.biome_multiplier.' . $type, (float) $default);
    }

    /**
     * Начисляет воду (целое число) персонажу.
     *
     * v0.51.25 perf: $waterResourceId pre-loaded outside hot loop (was N+1 query).
     */
    private function addWaterToCharacter(int $characterId, int $amount, int $waterResourceId): void
    {
        // Ищем, есть ли вода уже
        $charRes = $this->characterResourceModel
            ->where('id_characters', $characterId)
            ->where('id_resources', $waterResourceId)
            ->first();

        if ($charRes) {
            // Увеличиваем quantity
            $newQty = $charRes['quantity'] + $amount;
            $this->characterResourceModel->update($charRes['id'], ['quantity' => $newQty]);
        } else {
            // Создаем новую запись
            $this->characterResourceModel->insert([
                'id_characters' => $characterId,
                'id_resources'  => $waterResourceId,
                'quantity'      => $amount,
            ]);
        }
    }
}
