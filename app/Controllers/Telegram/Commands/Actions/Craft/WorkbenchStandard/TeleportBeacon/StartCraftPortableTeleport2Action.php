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
use App\Services\Telegram\Request;

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
    // 🔴 Имена НЕ повторяют свойства BaseAction (`characterModel`/`characterTaskModel`/
    // `taskModel`): там они без типов, и типизированное переобъявление в наследнике —
    // фатальная ошибка PHP. Модели, которые нужны ВНУТРИ циклов, здесь намеренно НЕ
    // хранятся: CI4 builder копит where() между вызовами, поэтому они создаются
    // заново на каждой итерации (memory feedback_ci4_model_builder_state_quirk).
    protected CharacterModel $charModel;
    protected BuildingModel $buildingModel;
    protected CharacterBuildingModel $characterBuildingModel;
    protected ClaimedCellModel $claimedCellModel;
    protected CharacterTaskModel $charTaskModel;
    protected TaskModel $taskRegistryModel;
    protected PortableTeleportRecipe $recipe;

    public function __construct(CallbackQuery $callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->charModel              = new CharacterModel();
        $this->buildingModel          = new BuildingModel();
        $this->characterBuildingModel = new CharacterBuildingModel();
        $this->claimedCellModel       = new ClaimedCellModel();
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
            // Свежий инстанс на итерацию: CI4 builder копит where() между вызовами —
            // со второго ресурса условия складываются и запрос возвращает пусто
            // (memory feedback_ci4_model_builder_state_quirk).
            $row  = (new CharacterResourceModel())->getResourceByNameAndCharacterId($name, $characterId);
            $have = (is_array($row) && is_numeric($row['quantity'] ?? null)) ? (int) $row['quantity'] : 0;
            if ($have < $need) {
                $missing[] = "{$name}: есть {$have}, нужно {$need}";
            }
        }

        foreach ($this->recipe->components() as $name => $need) {
            $item  = (new CraftedItemsModel())->getCraftedItemByName($name);
            $idRaw = is_array($item) ? ($item['id'] ?? null) : null;
            $have  = 0;
            if (is_numeric($idRaw)) {
                $log  = (new CraftedItemsLogModel())
                    ->where('crafted_item_id', (int) $idRaw)
                    ->where('character_id', $characterId)
                    ->first();
                $have = (is_array($log) && is_numeric($log['quantity'] ?? null)) ? (int) $log['quantity'] : 0;
            }
            if ($have < $need) {
                $missing[] = "{$name}: есть {$have}, нужно {$need}";
            }
        }

        $gold = self::goldOf($this->charModel->find($characterId));
        if ($gold < $this->recipe->goldCost()) {
            $missing[] = "Золото: есть {$gold}, нужно {$this->recipe->goldCost()}";
        }

        return $missing;
    }


    /**
     * Золото персонажа из строки `characters`. 🔴 `CharacterModel::find()` возвращает
     * **CharacterEntity**, а не массив (ArrayAccess у неё есть, но `is_array()` — false).
     * Проверка «только is_array» тихо давала 0 золота и роняла крафт в
     * «не хватает материалов» — поймано Tier-3 смоуком на testbot 2026-08-06
     * (memory feedback_entity_strict_array_typehint_trap).
     */
    private static function goldOf(mixed $charRow): int
    {
        if ($charRow instanceof \App\Entities\CharacterEntity) {
            $raw = $charRow->gold;
        } elseif (is_array($charRow)) {
            $raw = $charRow['gold'] ?? null;
        } else {
            return 0;
        }

        return is_numeric($raw) ? (int) $raw : 0;
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

    /**
     * Списание сырья. 🔴 `ResourceModel::getResourceByName()` возвращает **ResourceEntity**,
     * а не массив — проверка через `is_array()` молча пропускала все ресурсы, и сборка
     * уходила бесплатной по материалам (поймано Tier-3 смоуком 2026-08-06).
     *
     * Списываем builder-выражением `quantity - N` (атомарно), а НЕ через
     * `CharacterResourceModel::deductResource()`: тот читает строку джойном
     * `character_resources.*, resources.*`, где `resources.id` затирает id строки, и
     * обновляет чужую запись.
     */
    private function chargeResources(int $characterId): void
    {
        foreach ($this->recipe->resources() as $name => $qty) {
            $resourceId = self::idOf((new ResourceModel())->getResourceByName($name));
            if ($resourceId <= 0) {
                log_message('error', "[PortableTeleport] ресурс «{$name}» не найден в справочнике — списание пропущено.");

                continue;
            }
            // Свежий инстанс модели на итерацию — CI4 builder копит where()
            // (memory feedback_ci4_model_builder_state_quirk).
            (new CharacterResourceModel())
                ->where('id_characters', $characterId)
                ->where('id_resources', $resourceId)
                ->set('quantity', 'quantity - ' . $qty, false)
                ->update();
        }
    }

    /** id строки из Entity или массива (mixed → int, phpstan L9). */
    private static function idOf(mixed $row): int
    {
        if (is_object($row) && isset($row->id) && is_numeric($row->id)) {
            return (int) $row->id;
        }
        if (is_array($row) && is_numeric($row['id'] ?? null)) {
            return (int) $row['id'];
        }

        return 0;
    }

    /**
     * Списание компонентов через канонический `deductCraftedItem` (транзакция + проверка
     * достаточности + удаление пустой строки). Builder-выражение `quantity - N` здесь
     * НЕ работает: `CraftedItemsLogModel::update()` с raw-set возвращает false и запрос
     * не уходит — соседний `StartCraftTeleportBackpack2Action` этим и страдает.
     */
    private function chargeComponents(int $characterId): void
    {
        foreach ($this->recipe->components() as $name => $qty) {
            $itemId = self::idOf((new CraftedItemsModel())->getCraftedItemByName($name));
            if ($itemId <= 0) {
                log_message('error', "[PortableTeleport] компонент «{$name}» не найден в crafted_items — списание пропущено.");

                continue;
            }
            if (! (new CraftedItemsLogModel())->deductCraftedItem($itemId, $characterId, $qty)) {
                log_message('error', "[PortableTeleport] не удалось списать «{$name}» x{$qty} у персонажа {$characterId}.");
            }
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
