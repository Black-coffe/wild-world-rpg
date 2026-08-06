<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\TeleportBeacon;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Models\CharacterModel;
use App\Models\CharacterResourceModel;
use App\Models\CharacterTaskModel;
use App\Models\ClaimedCellModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Models\ResourceModel;
use App\Models\TaskModel;
use App\Services\Notifications\MediaSender;
use App\Services\Player\Craft\PortableTeleportRecipe;
use App\Services\Tasks\ActionScopeService;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * Запуск сборки «📡 Портативный телепорт» (callback `startCraftPortableTeleport2`).
 *
 * 🔴 В отличие от соседних телепорт-рецептов, требования здесь перепроверяются ПОВТОРНО
 * перед списанием: экран требований мог быть открыт час назад, ресурсы за это время
 * потратились, а старая кнопка в чате живёт вечно. Соседи (`StartCraftTeleportBackpack2Action`)
 * доверяют предыдущему экрану и уводят количества в минус — здесь так не делаем.
 *
 * Списание идёт после создания задачи; состав материалов и все числа — из
 * {@see PortableTeleportRecipe} (один источник для показа и списания).
 */
class StartCraftPortableTeleport2Action extends BaseAction
{
    // 🔴 Имена НЕ повторяют свойства BaseAction (`characterModel`/`resourceModel`/
    // `characterTaskModel`/`taskModel`): там они без типов, и типизированное
    // переобъявление в наследнике — фатальная ошибка PHP.
    protected CharacterResourceModel $characterResourceModel;
    protected ResourceModel $resModel;
    protected CharacterModel $charModel;
    protected BuildingModel $buildingModel;
    protected CharacterBuildingModel $characterBuildingModel;
    protected ClaimedCellModel $claimedCellModel;
    protected CraftedItemsLogModel $craftedItemsLogModel;
    protected CraftedItemsModel $craftedItemsModel;
    protected CharacterTaskModel $charTaskModel;
    protected TaskModel $taskRegistryModel;
    protected PortableTeleportRecipe $recipe;

    public function __construct(CallbackQuery $callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterResourceModel = new CharacterResourceModel();
        $this->resModel               = new ResourceModel();
        $this->charModel              = new CharacterModel();
        $this->buildingModel          = new BuildingModel();
        $this->characterBuildingModel = new CharacterBuildingModel();
        $this->claimedCellModel       = new ClaimedCellModel();
        $this->craftedItemsLogModel   = new CraftedItemsLogModel();
        $this->craftedItemsModel      = new CraftedItemsModel();
        $this->charTaskModel          = new CharacterTaskModel();
        $this->taskRegistryModel      = new TaskModel();
        $this->recipe                 = new PortableTeleportRecipe();
    }

    public function handle(): ServerResponse
    {
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();

        if (! $user || ! $character) {
            return $this->error($chatId, 'Пользователь или персонаж не найдены.');
        }

        if ((new \App\Services\Tasks\ActiveTasksService())->checkRelocationAndBlock(
            $character['id'],
            $this->callbackQuery->getId(),
            $chatId
        )) {
            return Request::emptyResponse();
        }

        $characterId = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;
        if ($characterId <= 0) {
            return $this->error($chatId, 'Персонаж не определён.');
        }

        if (! $this->recipe->enabled()) {
            $this->logRejected($characterId, 'CRAFT_PORTABLE_TELEPORT', 'killswitch_off');

            return $this->error($chatId, 'Сборка портативного телепорта сейчас закрыта.');
        }

        // Пререквизиты (могли отвалиться после показа экрана требований).
        $level    = is_numeric($character['level'] ?? null) ? (int) $character['level'] : 1;
        $minLevel = $this->recipe->minLevel();
        if ($level < $minLevel) {
            $this->logRejected($characterId, 'CRAFT_PORTABLE_TELEPORT', 'low_level');

            return $this->error($chatId, "Нужен уровень {$minLevel}, у тебя {$level}.");
        }

        $hasBase = $this->claimedCellModel
            ->where('character_id', $characterId)
            ->where('status', 'active')
            ->first();
        if (! $hasBase) {
            $this->logRejected($characterId, 'CRAFT_PORTABLE_TELEPORT', 'no_base');

            return $this->error($chatId, 'Нужна активная база: 🏠 База → 🏕 Разбить лагерь.');
        }

        if (! $this->ownsBuilding($characterId, 'TeleportationCenter')) {
            $this->logRejected($characterId, 'CRAFT_PORTABLE_TELEPORT', 'no_teleport_center');

            return $this->error($chatId, 'Нужен Центр телепортации: 🏠 База → 🏗 Строить.');
        }

        if (! $this->ownsBuilding($characterId, 'Workshop')) {
            $this->logRejected($characterId, 'CRAFT_PORTABLE_TELEPORT', 'no_workshop');

            return $this->error($chatId, 'Нужен 1-й верстак (Мастерская): 🏠 База → 🏗 Строить.');
        }

        // Материалы и золото — перепроверка ПЕРЕД списанием (анти-уход в минус).
        $missing = $this->missingRequirements($characterId);
        if ($missing !== []) {
            $this->logRejected($characterId, 'CRAFT_PORTABLE_TELEPORT', 'not_enough_resources');

            return $this->error(
                $chatId,
                "Не хватает материалов:\n• " . implode("\n• ", $missing)
                . "\n\nОткрой экран рецепта — там видно всё разом."
            );
        }

        $taskRow = $this->ensureTaskRow();
        if ($taskRow === null) {
            return $this->error($chatId, "Не удалось подготовить задачу сборки. Попробуй ещё раз.");
        }
        $taskId = is_numeric($taskRow['id'] ?? null) ? (int) $taskRow['id'] : 0;

        $activeTask = $this->charTaskModel
            ->where('character_id', $characterId)
            ->where('task_id', $taskId)
            ->where('status', 'in_work')
            ->first();
        if ($activeTask) {
            return $this->error($chatId, 'Портативный телепорт уже собирается — дождись завершения.');
        }

        // Золото списываем ПЕРВЫМ и через decreaseGold: он снимает от свежего значения
        // под row-lock'ом и сам перепроверяет достаточность (фикс класса lost-update
        // 2026-07-13). Не прошло — задачу не создаём и материалы не трогаем.
        if (! $this->charModel->decreaseGold($characterId, $this->recipe->goldCost())) {
            $this->logRejected($characterId, 'CRAFT_PORTABLE_TELEPORT', 'not_enough_gold');

            return $this->error($chatId, "Не хватает золота: нужно {$this->recipe->goldCost()}.");
        }

        $minutes   = $this->recipe->durationMinutes();
        $startTime = new \DateTime();
        $endTime   = (clone $startTime)->add(new \DateInterval('PT' . $minutes . 'M'));

        $this->charTaskModel->insert([
            'character_id'     => $characterId,
            'telegram_user_id' => $user['id'],
            'task_id'          => $taskId,
            'start_time'       => $startTime->format('Y-m-d H:i:s'),
            'end_time'         => $endTime->format('Y-m-d H:i:s'),
            'status'           => 'in_work',
        ]);

        $this->chargeResources($characterId);
        $this->chargeComponents($characterId);

        $scope      = new ActionScopeService();
        $background = $scope->isBackground($taskRow['parallel_execution_allowed'] ?? 1);

        $text = "*Сборка запущена!*\n\n"
            . $scope->startedBlock(ActionScopeService::KIND_CRAFT, $background) . "\n\n"
            . "Ты собираешь: *📡 Портативный телепорт*.\n"
            . "Время: ~{$minutes} мин. Зарядов у готового устройства: *{$this->recipe->charges()}*.\n\n"
            . "Как применить, когда будет готов: находясь вне базы — *📡 Телепорт* → "
            . "*📡 Портативный телепорт*, и ты дома. Ждать между применениями не нужно.";

        return MediaSender::editTextOrSend($this->navTarget() + [
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode([
                'inline_keyboard' => [[
                    ['text' => '📋 Дела', 'callback_data' => 'questAndTask'],
                    ['text' => '🌀 К телепортам', 'callback_data' => 'teleportBeaconCraft2'],
                ]],
            ]),
        ]);
    }

    /**
     * Список нехваток человеческим текстом (пусто → всё есть).
     *
     * @return list<string>
     */
    private function missingRequirements(int $characterId): array
    {
        $missing = [];

        foreach ($this->recipe->resources() as $name => $need) {
            $row  = $this->characterResourceModel->getResourceByNameAndCharacterId($name, $characterId);
            $have = (is_array($row) && is_numeric($row['quantity'] ?? null)) ? (int) $row['quantity'] : 0;
            if ($have < $need) {
                $missing[] = "{$name}: есть {$have}, нужно {$need}";
            }
        }

        foreach ($this->recipe->components() as $name => $need) {
            $item  = $this->craftedItemsModel->getCraftedItemByName($name);
            $idRaw = is_array($item) ? ($item['id'] ?? null) : null;
            $have  = 0;
            if (is_numeric($idRaw)) {
                $log  = $this->craftedItemsLogModel
                    ->where('crafted_item_id', (int) $idRaw)
                    ->where('character_id', $characterId)
                    ->first();
                $have = (is_array($log) && is_numeric($log['quantity'] ?? null)) ? (int) $log['quantity'] : 0;
            }
            if ($have < $need) {
                $missing[] = "{$name}: есть {$have}, нужно {$need}";
            }
        }

        $charRow = $this->charModel->find($characterId);
        $gold    = (is_array($charRow) && is_numeric($charRow['gold'] ?? null)) ? (int) $charRow['gold'] : 0;
        if ($gold < $this->recipe->goldCost()) {
            $missing[] = "Золото: есть {$gold}, нужно {$this->recipe->goldCost()}";
        }

        return $missing;
    }

    /** @return array<int|string,mixed>|null */
    private function ensureTaskRow(): ?array
    {
        $row = $this->taskRegistryModel->where('name', PortableTeleportRecipe::TASK_NAME)->first();
        if (is_array($row)) {
            return $row;
        }

        $newId = $this->taskRegistryModel->insert([
            'name'                       => PortableTeleportRecipe::TASK_NAME,
            'name_rus'                   => 'Сборка портативного телепорта',
            'description'                => 'Задача сборки карманного устройства мгновенного возврата на базу',
            'min_duration'               => 45,
            'max_duration'               => 90,
            'type'                       => 'craft',
            'difficulty_level'           => 9,
            'execution_limit'            => 0,
            'parallel_execution_allowed' => 1,
            'interruptible'              => 1,
            'created_at'                 => date('Y-m-d H:i:s'),
            'updated_at'                 => date('Y-m-d H:i:s'),
        ]);
        if (! $newId) {
            log_message('error', '[PortableTeleport] не удалось создать задачу ' . PortableTeleportRecipe::TASK_NAME);

            return null;
        }

        $row = $this->taskRegistryModel->find($newId);

        return is_array($row) ? $row : null;
    }

    private function ownsBuilding(int $characterId, string $nameEn): bool
    {
        $building = $this->buildingModel->where('name_en', $nameEn)->first();
        $idRaw    = is_array($building) ? ($building['id'] ?? null) : null;
        if (! is_numeric($idRaw)) {
            return true;
        }

        return $this->characterBuildingModel
            ->where('character_id', $characterId)
            ->where('building_id', (int) $idRaw)
            ->first() !== null;
    }

    private function chargeResources(int $characterId): void
    {
        foreach ($this->recipe->resources() as $name => $qty) {
            $row   = $this->resModel->getResourceByName($name);
            $idRaw = is_array($row) ? ($row['id'] ?? null) : null;
            if (! is_numeric($idRaw)) {
                continue;
            }
            // Свежий инстанс модели на итерацию — CI4 builder копит where()
            // (memory feedback_ci4_model_builder_state_quirk).
            (new CharacterResourceModel())
                ->where('id_characters', $characterId)
                ->where('id_resources', (int) $idRaw)
                ->set('quantity', 'quantity - ' . $qty, false)
                ->update();
        }
    }

    private function chargeComponents(int $characterId): void
    {
        foreach ($this->recipe->components() as $name => $qty) {
            $item  = $this->craftedItemsModel->getCraftedItemByName($name);
            $idRaw = is_array($item) ? ($item['id'] ?? null) : null;
            if (! is_numeric($idRaw)) {
                continue;
            }
            (new CraftedItemsLogModel())
                ->where('character_id', $characterId)
                ->where('crafted_item_id', (int) $idRaw)
                ->set('quantity', 'quantity - ' . $qty, false)
                ->update();
        }
    }

    private function error(int|string $chatId, string $message): ServerResponse
    {
        return Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => $message,
            'reply_markup' => json_encode([
                'inline_keyboard' => [[
                    ['text' => '🌀 К телепортам', 'callback_data' => 'teleportBeaconCraft2'],
                    ['text' => '🏠 База', 'callback_data' => 'Base'],
                ]],
            ]),
        ]);
    }
}
