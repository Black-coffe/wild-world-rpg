<?php

namespace App\TaskHandlers\Built;

use App\Attributes\HandlerKey;
use App\Models\CharacterModel;
use App\Models\CharacterBuildingModel;
use App\Models\BuildingModel;
use App\TaskHandlers\BaseTaskHandler;
use App\Services\GameSettings\GameSettingsReaderTrait;
use Config\GameBalance;

/**
 * v0.51.19 (F2.9 batch-1): extends BaseTaskHandler (per F2.9 contract).
 * Раніше extends Controller — handler НЕ контроллер.
 * Bonus: namespace casing fixed (`app\` → `App\` per PSR-4).
 * `handle()` → `handle(array $task = []): void` (TaskHandlerInterface signature).
 *
 * v0.51.24 (C/F6 expansion): gymStrengthByLevel + tickInterval читаються через
 * config('GameBalance'). Раніше hardcoded private $gymLevels.
 */
#[HandlerKey(
    key: 'gym_production',
    displayName: 'Производство Спортзала',
    description: 'Recurring (Tasks.php every minute): спортзал начисляет силу/опыт владельцу. Per-level config через GameBalance.',
)]
class GymProductionHandler extends BaseTaskHandler
{
    use GameSettingsReaderTrait;

    protected $characterModel;
    protected $characterBuildingModel;
    protected $buildingModel;
    private GameBalance $cfg;

    public function __construct(?GameBalance $cfg = null)
    {
        $this->cfg = $cfg ?? config('GameBalance');
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
        // Tick раз на tick-interval (D: GameSettings live-tunable, fallback GameBalance).
        $tickInterval  = max(1, $this->gsInt('building.gym.tick_interval_minutes', (int) $this->cfg->gymTickIntervalMinutes));
        $currentMinute = (int) date('i');
        if ($currentMinute % $tickInterval !== 0) {
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
            if (!isset($this->cfg->gymStrengthByLevel[$level])) {
                log_message('error', "[GymProductionHandler] Invalid level {$level} for building_id={$buildingRow['id']}");
                continue;
            }

            $strengthIncrement = $this->cfg->gymStrengthByLevel[$level];

            // Получаем персонажа
            $characterId = $buildingRow['character_id'];
            $character = $this->characterModel->find($characterId);
            if (!$character) {
                log_message('error', "[GymProductionHandler] Character ID {$characterId} not found.");
                continue;
            }

            // F (анти-creep): кап силы от Спортзала. 0 = выключен (по умолчанию, без нерфа).
            $cap = $this->gsInt('building.gym.strength_cap', 0);
            if ($cap > 0) {
                $strRaw = $character['strength'] ?? null;
                $curStr = is_numeric($strRaw) ? (float) $strRaw : 0.0;
                if ($curStr >= $cap) {
                    continue; // достигнут потолок силы от Спортзала
                }
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
     *
     * Fix 2026-07-13 (класс lost-update): дельта применяется к СВЕЖЕЙ силе под
     * row-lock'ом (CharacterStatsService), а не к снапшоту find() — параллельный
     * апдейт персонажа (препарат, квест, бой) больше не затирается.
     */
    private function addStrengthToCharacter(int $characterId, float $increment): void
    {
        $result = (new \App\Services\Player\CharacterStatsService())
            ->adjust($characterId, ['strength' => $increment]);

        if ($result === null) {
            log_message('error', "[GymProductionHandler] Character ID {$characterId} not found at addStrengthToCharacter.");
        }
    }
}