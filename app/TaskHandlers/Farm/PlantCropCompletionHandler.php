<?php

declare(strict_types=1);

namespace App\TaskHandlers\Farm;

use App\Attributes\HandlerKey;
use App\Models\CharacterModel;
use App\Models\CharacterTaskModel;
use App\Models\ResourceModel;
use App\Models\TelegramUserModel;
use App\Services\BuildingEffects\BuildingEffectsService;
use App\Services\Farming\FarmingService;
use App\TaskHandlers\BaseTaskHandler;

/**
 * V6 (ADR-033) — завершение посадки культуры (активное земледелие).
 *
 * Триггер: `character_tasks` с task='plantCrop' (handler_key='planting') достигает
 * end_time. task_settings = {crop, rotation} (зафиксированы при старте посадки в
 * PlantCropActionStart). Начисляет урожай (существующий ресурс crop_en × yield ×
 * rotation-bonus) в character_resources, обновляет characters.last_planted_crop,
 * уведомляет игрока.
 *
 * Слой ПОВЕРХ пассивной теплицы (GreenhouseProductionHandler не тронут).
 * Урожай детерминирован (без RNG): rotation-флаг заморожен при старте.
 *
 * character_id нарроуится через переменную (урок hotfix v0.51.218,
 * feedback_phpstan_no_mixed_to_int_cast) — НЕ offset-cast.
 */
#[HandlerKey(
    key: 'planting',
    displayName: 'Завершение посадки',
    description: 'Завершает plantCrop task: начисляет урожай (crop × yield × rotation) в character_resources, обновляет last_planted_crop, уведомляет игрока.',
)]
class PlantCropCompletionHandler extends BaseTaskHandler
{
    private CharacterModel     $characterModel;
    private CharacterTaskModel $characterTaskModel;
    private ResourceModel      $resourceModel;
    private TelegramUserModel  $telegramUserModel;
    private FarmingService     $farming;
    private BuildingEffectsService $buildingEffects;

    public function __construct(?FarmingService $farming = null, ?BuildingEffectsService $buildingEffects = null)
    {
        $this->characterModel     = new CharacterModel();
        $this->characterTaskModel = new CharacterTaskModel();
        $this->resourceModel      = new ResourceModel();
        $this->telegramUserModel  = new TelegramUserModel();
        $this->farming            = $farming ?? new FarmingService();
        $this->buildingEffects    = $buildingEffects ?? new BuildingEffectsService();
    }

    /**
     * @param array<string,mixed> $task запись из character_tasks.
     */
    public function handle(array $task = []): void
    {
        // task_settings = {crop, rotation}.
        $crop     = '';
        $rotation = false;
        $tsRaw    = $task['task_settings'] ?? null;
        if (is_string($tsRaw) && $tsRaw !== '') {
            $decoded = json_decode($tsRaw, true);
            if (is_array($decoded)) {
                $crop     = isset($decoded['crop']) && is_string($decoded['crop']) ? $decoded['crop'] : '';
                $rotation = !empty($decoded['rotation']);
            }
        }

        $meta = $this->farming->cropMeta($crop);
        if ($meta === null) {
            $taskIdLog = isset($task['id']) && is_scalar($task['id']) ? (string) $task['id'] : '?';
            log_message('error', '[PlantCropCompletion] неизвестная культура в task_settings: ' . ($crop !== '' ? $crop : '(пусто)') . ', task_id=' . $taskIdLog);
            return;
        }

        // Mark completed (Worker мог уже это сделать атомарным claim'ом).
        $taskRowIdRaw = $task['id'] ?? null;
        $taskRowId    = is_numeric($taskRowIdRaw) ? (int) $taskRowIdRaw : 0;
        if ($taskRowId > 0) {
            $this->characterTaskModel->update($taskRowId, ['status' => 'completed']);
        }

        // character_id через переменную (НЕ offset-cast — strict phpstan).
        $cidRaw         = $task['character_id'] ?? null;
        $characterIdInt = is_numeric($cidRaw) ? (int) $cidRaw : 0;

        // Урожай (детерминированно): base × rotation × greenhouse-yield (V7, по
        // текущему уровню теплицы при сборе; нет теплицы / L1 → ×1.0).
        $ghYieldMult = $this->buildingEffects->getGreenhouseYieldMultiplier($characterIdInt);
        $yield = $this->farming->harvestYield($crop, $rotation, $ghYieldMult);
        if ($yield > 0) {
            $this->resourceModel->addOrIncreaseResource($characterIdInt, $meta['crop_en'], $yield);
        }

        // Rotation-память: запомнить последнюю посаженную культуру.
        $this->characterModel->update($characterIdInt, ['last_planted_crop' => $crop]);

        $this->notifyHarvest($task, $meta, $yield, $rotation);
    }

    /**
     * Уведомление об урожае. Текстовое (urozhai = ресурс, без item-картинки) →
     * media-off compliant by construction. Caption самодостаточен.
     *
     * @param array<string,mixed> $task
     * @param array{seed_en:string, seed_ru:string, crop_en:string, crop_ru:string, icon:string, recipe:string, grow_key:string, grow_default:int, yield_key:string, yield_default:int} $meta
     */
    private function notifyHarvest(array $task, array $meta, int $yield, bool $rotation): void
    {
        $tgRaw          = $task['telegram_user_id'] ?? null;
        $telegramUserId = is_numeric($tgRaw) ? (int) $tgRaw : 0;
        if ($telegramUserId <= 0) {
            return;
        }
        $userRow = $this->telegramUserModel->find($telegramUserId);
        if (!is_array($userRow) || empty($userRow['telegram_id'])) {
            return;
        }
        $chatIdRaw = $userRow['telegram_id'];
        $chatId    = is_numeric($chatIdRaw) ? (int) $chatIdRaw : 0;
        if ($chatId === 0) {
            return;
        }

        $rotationLine = $rotation
            ? "\n🔄 Бонус севооборота применён (+{$this->farming->rotationBonusPercent()}%)."
            : '';

        $text = "🌾 *Урожай собран!*\n\n"
            . "{$meta['icon']} *{$meta['crop_ru']}* ×{$yield} добавлено в запасы.{$rotationLine}\n\n"
            . "Посади новую культуру в теплице, чтобы продолжить. 🌱";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🌱 Посадить ещё', 'callback_data' => 'plantSeedMenu'],
                    ['text' => '🎒 Инвентарь',     'callback_data' => 'inventory'],
                ],
            ],
        ];

        $this->safeSendMessage($chatId, $text, [
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
