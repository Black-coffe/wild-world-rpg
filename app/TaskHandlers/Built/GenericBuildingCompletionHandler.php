<?php

declare(strict_types=1);

namespace App\TaskHandlers\Built;

use App\Attributes\HandlerKey;
use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Models\CharacterFactionModel;
use App\Models\CharacterModel;
use App\Models\CharacterTaskModel;
use App\Models\TelegramUserModel;
use App\TaskHandlers\BaseTaskHandler;
use Config\Buildings;

/**
 * F3.B4 — generic-handler завершения постройки любого здания.
 *
 * Заменяет 12 файлов `app/TaskHandlers/Built/BuiltCompletion*Handler.php`
 * (~2500 LOC дубля). Какое именно здание построено — берётся из
 * `task_settings.building` (string) и резолвится через `Config\Buildings`.
 *
 * Действия 1:1 с legacy completion-handlers:
 *   1. mark `character_tasks.status='completed'` (Worker уже это делает в
 *      F0.3 atomic-claim, повтор — no-op).
 *   2. find `buildings.name_en` row.
 *   3. insert/update `character_buildings` (amount++ если уже есть).
 *   4. bump character agility/intellect через `updateAgilityAndIntellect()`.
 *   5. send Telegram уведомление с photo + caption (Markdown).
 *
 * Recipe-поля из Buildings.php (добавлены в B4):
 *   - `completion_text`           — Markdown-текст уведомления
 *   - `completion_image`          — путь к картинке
 *   - `completion_bonus_agility`  — float, прибавка к ловкости
 *   - `completion_bonus_intellect`— float, прибавка к интеллекту
 *   - `completion_building_type`  — null | string. Если null — берётся
 *     из `buildings.building_type`. Иначе — override (legacy hardcoded
 *     'farming' в 9 handler'ах вопреки реальному typeу здания).
 *
 * УРОК v0.16.1: action-side (`GenericBuildingAction`) уже пишет
 * `task_settings.building`. Контракт согласован 1-в-1 — handler-side читает
 * тот же ключ. F2.2 баг (action писал `quantity`, handler ждал `recipe`)
 * не повторится.
 */
#[HandlerKey(
    key: 'generic_building',
    displayName: 'Универсальное завершение постройки',
    description: 'Завершает любой build*/startBuild* task (recipe из task_settings.building в Config\\Buildings). Покрывает 12 зданий: Workshop/Arsenal/Lab/CommTower/Robotics/Solar/Greenhouse/Gym/Warehouse/Teleport/BlastFurnace/HandPump.',
)]
class GenericBuildingCompletionHandler extends BaseTaskHandler
{
    private CharacterModel          $characterModel;
    private CharacterTaskModel      $characterTaskModel;
    private BuildingModel           $buildingModel;
    private CharacterBuildingModel  $characterBuildingModel;
    private CharacterFactionModel   $characterFactionModel;
    private TelegramUserModel       $telegramUserModel;

    public function __construct()
    {
        $this->characterModel          = new CharacterModel();
        $this->characterTaskModel      = new CharacterTaskModel();
        $this->buildingModel           = new BuildingModel();
        $this->characterBuildingModel  = new CharacterBuildingModel();
        $this->characterFactionModel   = new CharacterFactionModel();
        $this->telegramUserModel       = new TelegramUserModel();
    }

    public function handle(array $task = []): void
    {
        // 1. Достаём recipe key из task_settings
        $settings = json_decode($task['task_settings'] ?? '{}', true);
        $buildingKey = $settings['building'] ?? null;
        if ($buildingKey === null || $buildingKey === '') {
            log_message('error', '[GenericBuildingCompletion] task_settings.building не задан, task_id='
                . ($task['id'] ?? '?'));
            return;
        }

        // 2. Резолвим recipe
        /** @var Buildings $cfg */
        $cfg    = config('Buildings');
        $recipe = $cfg->get($buildingKey);
        if ($recipe === null) {
            log_message('error', "[GenericBuildingCompletion] нет рецепта '{$buildingKey}' в Config\\Buildings");
            return;
        }

        // 3. Mark completed (Worker уже это сделал атомарно через F0.3).
        if (!empty($task['id'])) {
            $this->characterTaskModel->update($task['id'], ['status' => 'completed']);
        }

        // 4. Lookup buildings.name_en
        $buildingRow = $this->buildingModel->where('name_en', $buildingKey)->first();
        if (!$buildingRow) {
            log_message('error', "[GenericBuildingCompletion] buildings.name_en='{$buildingKey}' не найдено");
            return;
        }

        // 5. character_buildings insert/update
        $this->updateCharacterBuildings($task, $buildingRow, $recipe);

        // 6. Stats бонусы
        $this->characterModel->updateAgilityAndIntellect(
            $task['character_id'],
            (float) ($recipe['completion_bonus_agility']  ?? 0.0),
            (float) ($recipe['completion_bonus_intellect'] ?? 0.0)
        );

        // 7. Notify
        $this->notifyUser(
            (int) $task['telegram_user_id'],
            (string) ($recipe['completion_text']  ?? ''),
            (string) ($recipe['completion_image'] ?? '')
        );
    }

    private function updateCharacterBuildings(array $task, array $buildingRow, array $recipe): void
    {
        $existing = $this->characterBuildingModel
            ->where('character_id', $task['character_id'])
            ->where('building_id', $buildingRow['id'])
            ->first();

        if ($existing) {
            // Уже есть — увеличиваем amount (1:1 с legacy)
            $this->characterBuildingModel->update($existing['id'], [
                'amount' => $existing['amount'] + 1,
            ]);
            return;
        }

        // Создаём новую запись
        $factionRow = $this->characterFactionModel
            ->where('character_id', $task['character_id'])
            ->first();
        $factionId = $factionRow ? $factionRow['faction_id'] : null;

        $charRow = $this->characterModel->find($task['character_id']);
        if (!$charRow) {
            log_message('error', "[GenericBuildingCompletion] character not found: " . $task['character_id']);
            return;
        }

        // building_type: либо override из recipe (legacy hardcoded 'farming'/'engineering'),
        // либо значение из buildings table.
        $buildingType = $recipe['completion_building_type'] ?? $buildingRow['building_type'];

        $this->characterBuildingModel->insert([
            'character_id'                       => $task['character_id'],
            'building_id'                        => $buildingRow['id'],
            'faction_id'                         => $factionId,
            'map_cell_id'                        => $charRow['cell_number'],
            'amount'                             => 1,
            'character_level_during_construction'=> $charRow['level'],
            'hp'                                 => $buildingRow['hp'],
            'level'                              => 1,
            'built_at'                           => date('Y-m-d H:i:s'),
            'building_type'                      => $buildingType,
            'tax'                                => $buildingRow['tax'],
            'usage'                              => $buildingRow['usage'],
        ]);
    }

    private function notifyUser(int $telegramUserId, string $caption, string $imageRelPath): void
    {
        $tgUser = $this->telegramUserModel->find($telegramUserId);
        if (!$tgUser) {
            log_message('error', "[GenericBuildingCompletion] telegram_user not found: {$telegramUserId}");
            return;
        }

        $imagePath = base_url($imageRelPath);
        $this->safeSendPhoto(
            $tgUser['telegram_id'],
            $imagePath,
            $caption,
            ['parse_mode' => 'Markdown']
        );
    }
}
