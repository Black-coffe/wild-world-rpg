<?php

namespace App\Controllers\Telegram\Commands\Actions\Camp\Buildings;

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;

// Модели:
use App\Models\CharacterModel;
use App\Models\ClaimedCellModel;
use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Models\CharacterFactionModel;
use App\Models\MapModel;
use App\Models\CraftedItemsModel;
use App\Models\CraftedItemsLogModel;
use App\Models\BiomeModel;
use App\Models\TeleportBeaconModel;
use App\Models\TelegramUserModel; // уведомление старому владельцу

// Сервисы для проверки базы
use App\Services\Bases\CampCheckService;

/**
 * Класс TeleportBeaconSetAction:
 * 1) Обрабатывает попытку «Установить маяк» (callback_data: "teleportBeaconSet_x=.._y=..").
 * 2) Если на точке есть чужой маяк -> предлагаем «Перехватить»/«Отменить».
 * 3) При нажатии «Перехватить» (callback_data: "teleportBeaconSet_capture_id=..") меняем владельца,
 *    уведомляем обоих игроков, пытаемся редактировать предыдущее сообщение и отправляем Alert.
 */
class TeleportBeaconSetAction
{
    protected CallbackQuery $callbackQuery;

    // Модели
    protected CharacterModel         $characterModel;
    protected ClaimedCellModel       $claimedCellModel;
    protected BuildingModel          $buildingModel;
    protected CharacterBuildingModel $characterBuildingModel;
    protected CharacterFactionModel  $characterFactionModel;
    protected MapModel               $mapModel;
    protected CraftedItemsModel      $craftedItemsModel;
    protected CraftedItemsLogModel   $craftedItemsLogModel;
    protected BiomeModel             $biomeModel;
    protected TeleportBeaconModel    $teleportBeaconModel;
    protected TelegramUserModel      $telegramUserModel;

    // Сервис для проверки, не стоит ли здесь база
    protected CampCheckService $campCheckService;

    public function __construct(CallbackQuery $callbackQuery)
    {
        $this->callbackQuery          = $callbackQuery;
        $this->characterModel         = new CharacterModel();
        $this->claimedCellModel       = new ClaimedCellModel();
        $this->buildingModel          = new BuildingModel();
        $this->characterBuildingModel = new CharacterBuildingModel();
        $this->characterFactionModel  = new CharacterFactionModel();
        $this->mapModel               = new MapModel();
        $this->craftedItemsModel      = new CraftedItemsModel();
        $this->craftedItemsLogModel   = new CraftedItemsLogModel();
        $this->biomeModel             = new BiomeModel();
        $this->teleportBeaconModel    = new TeleportBeaconModel();
        $this->telegramUserModel      = new TelegramUserModel();

        $this->campCheckService       = new CampCheckService();
    }

    /**
     * Главный метод. Ожидаем callback_data одного из трёх видов:
     * 1) "teleportBeaconSet_x=...,y=..." — установка маяка
     * 2) "teleportBeaconSet_capture_id=..." — перехват чужого маяка
     * 3) "teleportBeaconSet_cancel" — отмена
     */
    public function handle(): ServerResponse
    {
        // Убираем «часики»
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
        ]);

        $chatId       = $this->callbackQuery->getMessage()->getChat()->getId();
        $callbackData = $this->callbackQuery->getData();

        // (A) Перехват?
        if (preg_match('/^teleportBeaconSet_capture_id=(\d+)$/', $callbackData, $m)) {
            $beaconId = (int)$m[1];
            return $this->captureBeacon($beaconId, $chatId);
        }

        // (B) Отмена?
        if ($callbackData === 'teleportBeaconSet_cancel') {
            return $this->cancelSet($chatId);
        }

        // (C) Иначе — ждём установку "teleportBeaconSet_x=..._y=..."
        if (!preg_match('/^teleportBeaconSet_x=(\d+)_y=(\d+)$/', $callbackData, $matches)) {
            return $this->sendError($chatId, "Некорректные данные callback_data: {$callbackData}");
        }

        $coordX = (int)$matches[1];
        $coordY = (int)$matches[2];

        return $this->processSetBeacon($chatId, $coordX, $coordY);
    }

    /**
     * Пытаемся установить маяк в (coordX, coordY).
     */
    private function processSetBeacon(int $chatId, int $coordX, int $coordY): ServerResponse
    {
        // Определяем characterId
        $telegramUserId = $this->callbackQuery->getFrom()->getId();
        $characterId    = $this->characterModel->getCharacterIdByTelegramId($telegramUserId);
        if (!$characterId) {
            return $this->sendError($chatId, "Персонаж не найден (TG={$telegramUserId}).");
        }

        // Проверяем, есть ли активная база
        $activeBase = $this->claimedCellModel
            ->where('character_id', $characterId)
            ->where('status', 'active')
            ->first();
        if (!$activeBase) {
            return $this->sendError($chatId, "У тебя нет активной базы — без неё нельзя ставить маяк!");
        }

        // Проверяем, есть ли «Центр телепортации»
        $teleportCenter = $this->buildingModel->where('name_en', 'TeleportationCenter')->first();
        if (!$teleportCenter) {
            return $this->sendError($chatId, "Ошибка: в базе нет 'TeleportationCenter'.");
        }
        $centerRow = $this->characterBuildingModel
            ->where('character_id', $characterId)
            ->where('building_id', $teleportCenter['id'])
            ->first();
        if (!$centerRow) {
            return $this->sendError($chatId, "У тебя нет построенного Центра телепортации!");
        }

        // Предмет "TeleportBeaconBasic"
        $beaconItem = $this->craftedItemsModel->where('name_eng', 'TeleportBeaconBasic')->first();
        if (!$beaconItem) {
            return $this->sendError($chatId, "Не найден предмет 'TeleportBeaconBasic'.");
        }
        $beaconLog = $this->craftedItemsLogModel
            ->where('character_id',    $characterId)
            ->where('crafted_item_id', $beaconItem['id'])
            ->first();
        if (!$beaconLog || $beaconLog['quantity'] < 1) {
            return $this->sendError($chatId, "У тебя нет ни одного маяка в инвентаре!");
        }

        // Проверяем лимит маяков
        $charRow       = $this->characterModel->find($characterId);
        $playerLevel   = (int)($charRow['level'] ?? 1);
        $buildingLevel = max(1, min(10, (int)$centerRow['level']));
        $baseMaxByPlayer = intdiv($playerLevel, 10);
        if ($baseMaxByPlayer > 10) {
            $baseMaxByPlayer = 10;
        }
        $maxBeacons = $baseMaxByPlayer + $buildingLevel;

        $existingCount = $this->teleportBeaconModel
            ->where('character_id', $characterId)
            ->countAllResults();
        if ($existingCount >= $maxBeacons) {
            return $this->sendError($chatId, "Ты достиг лимита маяков ({$maxBeacons}). Сначала удали старый маяк.");
        }

        // Ищем ячейку map
        $mapRow = $this->mapModel
            ->where('coordinate_x', $coordX)
            ->where('coordinate_y', $coordY)
            ->first();
        if (!$mapRow) {
            return $this->sendError($chatId, "Не найдена карта (X={$coordX}, Y={$coordY}).");
        }
        $mapCellId  = (int)$mapRow['id'];
        $cellNumber = (int)$mapRow['cell_number'];
        $biomeId    = $mapRow['biome_id'] ?? null;

        // Проверка, нет ли базы на этой точке
        if ($this->campCheckService->isCellClaimedByAnyone($cellNumber)) {
            return $this->sendError($chatId, "Здесь уже расположена чья-то база!");
        }

        // Проверка, нет ли чужого маяка
        $existingBeacon = $this->teleportBeaconModel
            ->where('coordinate_x', $coordX)
            ->where('coordinate_y', $coordY)
            ->where('remaining_uses >', 0)
            ->first();

        if ($existingBeacon) {
            // Если наш — ругаемся
            if ((int)$existingBeacon['character_id'] === $characterId) {
                return $this->sendError($chatId, "У тебя уже есть маяк на этой точке!");
            } else {
                // Чужой маяк => предлагаем «Перехватить»/«Отменить»
                return $this->showCaptureOption($chatId, $existingBeacon, $cellNumber);
            }
        }

        // Если дошли сюда, ставим маяк
        $this->installBeacon($chatId, $characterId, $mapRow, $biomeId, $beaconItem, $playerLevel, $maxBeacons);

        return Request::emptyResponse(); // уже отправили сообщение об успехе
    }

    /**
     * Создаём запись в teleport_beacons, списываем маяк из инвентаря и отправляем сообщение.
     */
    private function installBeacon(
        int $chatId,
        int $characterId,
        array $mapRow,
        ?int $biomeId,
        array $beaconItem,
        int $playerLevel,
        int $maxBeacons
    ): void {
        $coordX = $mapRow['coordinate_x'];
        $coordY = $mapRow['coordinate_y'];
        $mapCellId  = (int)$mapRow['id'];
        $cellNumber = (int)$mapRow['cell_number'];

        // Вставляем новую строку
        $this->teleportBeaconModel->insert([
            'character_id'             => $characterId,
            'faction_id'               => null,
            'player_level_at_creation' => $playerLevel,
            'map_cell_id'              => $mapCellId,
            'coordinate_x'             => $coordX,
            'coordinate_y'             => $coordY,
            'tax_cost'                 => 180,
            'remaining_uses'           => 100,
            'last_teleport_at'         => null,
            'ownership_type'           => 'author',
            'settings_json'            => null,
        ]);

        // Теперь считаем общее кол-во маяков
        $updatedCount = $this->teleportBeaconModel
            ->where('character_id', $characterId)
            ->countAllResults();

        // Списываем 1 «TeleportBeaconBasic»
        $this->craftedItemsLogModel->subtractItem($characterId, 'TeleportBeaconBasic', 1);

        // Сколько маяков осталось
        $beaconLogUpdated = $this->craftedItemsLogModel
            ->where('character_id',    $characterId)
            ->where('crafted_item_id', $beaconItem['id'])
            ->first();
        $beaconLeft = $beaconLogUpdated ? $beaconLogUpdated['quantity'] : 0;

        // Смотрим биом
        $biomeName        = '???';
        $biomeDescription = '';
        $biomeType        = '';
        $dangerLevelText  = '';
        $biomeDangerLevel = 0;
        $survivalText     = '';
        $survivalValue    = 0;
        $occurrenceRate   = 0;

        if ($biomeId) {
            $bRow = $this->biomeModel->find($biomeId);
            if ($bRow) {
                $biomeName        = $bRow['name'] ?? '???';
                $biomeDescription = $bRow['description'] ?? '';
                $biomeType        = $bRow['biome_type'] ?? '';
                $dangerLevelText  = $bRow['danger_level_text'] ?? '';
                $biomeDangerLevel = (int)($bRow['danger_level'] ?? 0);
                $survivalText     = $bRow['survival_difficulty_text'] ?? '';
                $survivalValue    = (int)($bRow['survival_difficulty'] ?? 0);
                $occurrenceRate   = (float)($bRow['occurrence_rate'] ?? 0);
            }
        }

        // Формируем сообщение
        $textMessage = "⚙️ *Установка телепорт-маяка завершена!*\n\n"
            . "Ты успешно разместил маяк:\n"
            . "• Координаты: `X={$coordX}, Y={$coordY}` (#{$cellNumber})\n"
            . "• Биом: *{$biomeName}*\n"
            . "   _{$biomeDescription}_\n\n"
            . "🔋 *Запас прочности:* 100 использований\n\n"
            . "📋 *Характеристики биома:*\n"
            . "• Тип: `{$biomeType}`\n"
            . "• Опасность: {$biomeDangerLevel}/10 «{$dangerLevelText}»\n"
            . "• Сложность выживания: {$survivalValue}/10 «{$survivalText}»\n"
            . "• Частота встречаемости: {$occurrenceRate}%\n\n"
            . "📦 *Маяков в инвентаре:* {$beaconLeft}\n"
            . "🏗 Теперь у тебя *{$updatedCount}* маяков из макс. *{$maxBeacons}*\n\n"
            . "🔎 Надеюсь, никто не отыщет твой маяк и не разберёт его на детали...";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ['text' => '🏠 База',            'callback_data' => 'Base'],
                ],
            ]
        ];

        Request::sendMessage([
            'chat_id'    => $chatId,
            'text'       => $textMessage,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Показ диалога о найденном чужом маяке (remaining_uses>0):
     * «Перехватить!» или «Отменить».
     */
    private function showCaptureOption(int $chatId, array $existingBeacon, int $cellNumber): ServerResponse
    {
        $uses = (int)$existingBeacon['remaining_uses'];
        $text = "На этой точке уже обнаружен *чужой* маяк!\n"
            . "Остаток телепортов: {$uses}\n\n"
            . "Ты можешь его *перехватить* (присвоить себе) или *отказаться* (ничего не делать).";

        // callback_data => "teleportBeaconSet_capture_id=XXX"
        $captureData = "teleportBeaconSet_capture_id={$existingBeacon['id']}";
        $cancelData  = "teleportBeaconSet_cancel";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '👀 Перехватить!', 'callback_data' => $captureData],
                    ['text' => '🚫 Отменить',     'callback_data' => $cancelData],
                ],
            ]
        ];

        return Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Перехват чужого маяка: меняем владельца, уведомляем обоих.
     * Также пытаемся редактировать предыдущее сообщение, если оно свежее.
     */
    private function captureBeacon(int $beaconId, int $chatId): ServerResponse
    {
        // 1) Текущий игрок (захватчик)
        $telegramUserId = $this->callbackQuery->getFrom()->getId();
        $characterId    = $this->characterModel->getCharacterIdByTelegramId($telegramUserId);
        if (!$characterId) {
            return $this->sendError($chatId, "Персонаж не найден (TG={$telegramUserId}).");
        }

        // 2) Находим маяк
        $beaconRow = $this->teleportBeaconModel->find($beaconId);
        if (!$beaconRow) {
            return $this->sendError($chatId, "Маяк #{$beaconId} не найден (возможно, уже удалён?).");
        }

        // 3) Проверяем, не наш ли уже этот маяк
        if ((int)$beaconRow['character_id'] === $characterId) {
            // Alert + сообщение
            Request::answerCallbackQuery([
                'callback_query_id' => $this->callbackQuery->getId(),
                'text'             => 'Уже твой маяк!',
                'show_alert'       => true,
            ]);
            return $this->sendError($chatId, "Этот маяк уже принадлежит тебе!");
        }

        // 4) Старый владелец
        $oldOwnerId = (int)$beaconRow['character_id'];

        // 5) Меняем поля
        $this->teleportBeaconModel->update($beaconId, [
            'character_id'   => $characterId,
            'ownership_type' => 'captured',
        ]);

        // 6) Уведомляем старого владельца
        if ($oldOwnerId && $oldOwnerId !== $characterId) {
            $this->notifyOldOwner($oldOwnerId, $beaconRow);
        }

        // 7) Текст для нового владельца
        $x    = $beaconRow['coordinate_x'];
        $y    = $beaconRow['coordinate_y'];
        $uses = $beaconRow['remaining_uses'];

        $text = "✅ *Маяк перехвачен!*\n\n"
            . "Теперь он твой (ownership_type='captured').\n"
            . "Координаты: (X={$x}, Y={$y})\n"
            . "Остаток телепортов: {$uses}";

        // 8) Пытаемся отредактировать текущее сообщение (убираем кнопки)
        $messageId  = $this->callbackQuery->getMessage()->getMessageId();
        $editResult = Request::editMessageText([
            'chat_id'    => $chatId,
            'message_id' => $messageId,
            'text'       => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => []]), // убираем кнопки
        ]);

        // 9) Если редактирование не удалось, шлём новое сообщение
        if ($editResult === null || !$editResult->isOk()) {
            Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => $text,
                'parse_mode' => 'Markdown',
            ]);
        }

        // 10) Alert захватчику в любом случае
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text'             => 'Маяк перехвачен!',
            'show_alert'       => true,
        ]);

        return Request::emptyResponse();
    }

    /**
     * Уведомляем старого владельца
     */
    private function notifyOldOwner(int $oldOwnerId, array $beaconRow): void
    {
        // 1) Найдём oldChar
        $oldChar = $this->characterModel->find($oldOwnerId);
        if (!$oldChar) {
            return; // нет персонажа
        }

        $tgUserId = (int)$oldChar['telegram_user_id'];
        if (!$tgUserId) {
            return; // нет связи
        }

        // 2) Ищем telegram_id
        $db = db_connect();
        $query = $db->table('telegram_users')
            ->select('telegram_id')
            ->where('id', $tgUserId)
            ->get();
        if ($query === false) {
            return;
        }
        $row = $query->getRowArray();
        if (!$row) {
            return;
        }
        $oldOwnerTgId = (int)$row['telegram_id'];

        // 3) Отправляем
        $x    = $beaconRow['coordinate_x'];
        $y    = $beaconRow['coordinate_y'];
        $uses = $beaconRow['remaining_uses'];

        $text = "‼️ *Тревога!*\n"
            ."Твой маяк на координатах (X={$x}, Y={$y}) перехвачен.\n"
            ."Остаток телепортов: {$uses}\n"
            ."Теперь маяк тебе не принадлежит :(";

        Request::sendMessage([
            'chat_id'    => $oldOwnerTgId,
            'text'       => $text,
            'parse_mode' => 'Markdown',
        ]);
    }

    /**
     * Отмена (ни маяк не ставим, ни чужой не трогаем)
     */
    private function cancelSet(int $chatId): ServerResponse
    {
        return Request::sendMessage([
            'chat_id'    => $chatId,
            'text'       => "Операция установки (или перехвата) маяка отменена.",
            'parse_mode' => 'Markdown',
        ]);
    }

    /**
     * Универсальный метод для отправки ошибки в чат
     */
    private function sendError(int $chatId, string $msg): ServerResponse
    {
        return Request::sendMessage([
            'chat_id'    => $chatId,
            'text'       => $msg,
            'parse_mode' => 'Markdown',
        ]);
    }
}
