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

        // Материалы — перепроверка ПЕРЕД списанием (анти-уход в минус). Story
        // craft-shortfall-buy-13: вместо текстового отказа — общий экран нехватки
        // (CraftShortageService), как остальной крафт. Золото сюда не входит: его
        // перепроверяет `decreaseGold()` ниже, атомарно под row-lock'ом.
        $missingResources = $this->missingResources($characterId);
        $missingComponents = $this->missingComponents($characterId);
        if ($missingResources !== [] || $missingComponents !== []) {
            $this->logRejected($characterId, 'CRAFT_PORTABLE_TELEPORT', 'not_enough_resources');

            return $this->shortageScreen($character, $missingResources, $missingComponents, [
                'item_name_rus'         => PortableTeleportRecipe::ITEM_NAME_RUS,
                'item_name_eng'         => PortableTeleportRecipe::ITEM_NAME_ENG,
                'info_callback'         => 'portableTeleport2',
                'resources'             => $this->recipe->resources(),
                'crafted_items'         => $this->componentsByEng(),
                // fix-07 (остаток критической находки 1 ревью): рецепта нет в
                // Config\CraftRecipes — ключ докупки берём из собственного
                // callback_data старта ('startCraftPortableTeleport2'), тот же
                // формат, что и у остальных ~105 рецептов. Количество здесь всегда
                // 1 — у этого рецепта нет своей "×N" ветки.
                'craft_again_callback' => 'genericCraft_PortableTeleport2_1',
            ], $chatId);
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
     * Нехватка сырья (пусто → всё есть). Ключ — русское имя ресурса (совпадает с
     * `resources.name`), как ожидает `CraftShortageService::describe()`.
     *
     * @return array<string,array{need:int,have:int,name:string}>
     */
    private function missingResources(int $characterId): array
    {
        $missing = [];

        foreach ($this->recipe->resources() as $name => $need) {
            // Свежий инстанс на итерацию: CI4 builder копит where() между вызовами —
            // со второго ресурса условия складываются и запрос возвращает пусто
            // (memory feedback_ci4_model_builder_state_quirk).
            $row  = (new CharacterResourceModel())->getResourceByNameAndCharacterId($name, $characterId);
            $have = (is_array($row) && is_numeric($row['quantity'] ?? null)) ? (int) $row['quantity'] : 0;
            if ($have < $need) {
                $missing[$name] = ['need' => $need, 'have' => $have, 'name' => $name];
            }
        }

        return $missing;
    }

    /**
     * Нехватка крафт-компонентов (пусто → всё есть). Ключ — `crafted_items.name_eng`,
     * как ожидает `CraftShortageService::describe()`.
     *
     * @return array<string,array{need:int,have:int,name:string}>
     */
    private function missingComponents(int $characterId): array
    {
        $missing = [];

        foreach ($this->recipe->components() as $name => $need) {
            $item    = (new CraftedItemsModel())->getCraftedItemByName($name);
            $idRaw   = is_array($item) ? ($item['id'] ?? null) : null;
            $itemEng = (is_array($item) && is_string($item['name_eng'] ?? null)) ? $item['name_eng'] : $name;
            $have    = 0;
            if (is_numeric($idRaw)) {
                $log  = (new CraftedItemsLogModel())
                    ->where('crafted_item_id', (int) $idRaw)
                    ->where('character_id', $characterId)
                    ->first();
                $have = (is_array($log) && is_numeric($log['quantity'] ?? null)) ? (int) $log['quantity'] : 0;
            }
            if ($have < $need) {
                $missing[$itemEng] = ['need' => $need, 'have' => $have, 'name' => $name];
            }
        }

        return $missing;
    }

    /**
     * Полная (не только недостающая) карта компонентов рецепта, ключ — name_eng.
     * Нужна `CraftShortfallBuyService::quote()` для расчёта доли рецепта деньгами.
     *
     * @return array<string,int>
     */
    private function componentsByEng(): array
    {
        $out = [];
        foreach ($this->recipe->components() as $name => $need) {
            $item    = (new CraftedItemsModel())->getCraftedItemByName($name);
            $itemEng = (is_array($item) && is_string($item['name_eng'] ?? null)) ? $item['name_eng'] : $name;
            $out[$itemEng] = $need;
        }

        return $out;
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

    /**
     * ADR-158 / story craft-shortfall-buy-13 — тот же экран нехватки, что видит
     * остальной крафт (`CraftShortageService::describe()`), а не собственный
     * текстовый отказ. `answerCallbackQuery` здесь не дублируем: `handle()` уже
     * снял «часики» в самом начале.
     *
     * @param array<string,array{need:int,have:int,name:string}> $missingResources
     * @param array<string,array{need:int,have:int,name:string}> $missingItems
     * @param array<string,mixed> $recipe
     */
    private function shortageScreen(
        \App\Entities\CharacterEntity $character,
        array $missingResources,
        array $missingItems,
        array $recipe,
        int|string $chatId
    ): ServerResponse {
        $shortage = new \App\Services\Craft\CraftShortageService();
        if ($shortage->isEnabled()) {
            $screen = $shortage->describe($character, $missingResources, $missingItems, 1, $recipe);

            return Request::sendMessage([
                'chat_id'      => $chatId,
                'text'         => $screen['text'],
                'parse_mode'   => 'Markdown',
                'reply_markup' => json_encode($screen['keyboard']),
            ]);
        }

        return $this->error($chatId, 'Не хватает материалов для сборки.');
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
