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
use App\Models\TelegramUserModel; // чтобы уведомить старого владельца

// Сервисы для проверки базы
use App\Services\Bases\CampCheckService;

/**
 * Класс TeleportBeaconSetAction:
 * Обрабатывает нажатие «Установить маяк» (X, Y).
 * + Логика перехвата чужого маяка в том же классе (через другой колбэк).
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

    // Дополнительный сервис для проверки, занята ли ячейка базой
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
     * Главный метод handle(), куда попадает callback_data вида:
     * - "teleportBeaconSet_x=NNN_y=MMM" (попытка установить маяк)
     * - "teleportBeaconSet_capture_id=XXX" (перехват чужого маяка)
     * - "teleportBeaconSet_cancel" (отмена)
     */
    public function handle(): ServerResponse
    {
        // Убираем «часики» на кнопке
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
        ]);

        $chatId       = $this->callbackQuery->getMessage()->getChat()->getId();
        $callbackData = $this->callbackQuery->getData();

        // 1. Проверяем, не идёт ли речь о "перехватить маяк"
        if (preg_match('/^teleportBeaconSet_capture_id=(\d+)$/', $callbackData, $m)) {
            $beaconId = (int)$m[1];
            return $this->captureBeacon($beaconId, $chatId);
        }

        // 2. Проверяем, не идёт ли речь об «отменить» (cancel)
        if ($callbackData === 'teleportBeaconSet_cancel') {
            return $this->cancelSet($chatId);
        }

        // 3. Остальное: ожидаем формат "teleportBeaconSet_x=NNN_y=MMM"
        if (!preg_match('/^teleportBeaconSet_x=(\d+)_y=(\d+)$/', $callbackData, $matches)) {
            return $this->sendError($chatId, "Некорректные данные для установки маяка: {$callbackData}");
        }

        $coordX = (int)$matches[1];
        $coordY = (int)$matches[2];

        return $this->processSetBeacon($chatId, $coordX, $coordY);
    }

    /**
     * Обработка установки маяка в координатах (coordX, coordY).
     */
    private function processSetBeacon(int $chatId, int $coordX, int $coordY): ServerResponse
    {
        // Шаги проверки (активная база, наличие предмета и т.д.):

        // 1) Определяем characterId
        $telegramUserId = $this->callbackQuery->getFrom()->getId();
        $characterId    = $this->characterModel->getCharacterIdByTelegramId($telegramUserId);
        if (!$characterId) {
            return $this->sendError($chatId, "Персонаж не найден (TG={$telegramUserId}).");
        }

        // 2) Проверка активной базы
        $activeBase = $this->claimedCellModel
            ->where('character_id', $characterId)
            ->where('status', 'active')
            ->first();
        if (!$activeBase) {
            return $this->sendError($chatId, "Нет активной базы. Без неё нельзя ставить маяк!");
        }

        // 3) Проверяем наличие «Центра телепортации»
        $teleportCenter = $this->buildingModel->where('name_en', 'TeleportationCenter')->first();
        if (!$teleportCenter) {
            return $this->sendError($chatId, "Ошибка: нет здания 'TeleportationCenter' в базе.");
        }
        $centerRow = $this->characterBuildingModel
            ->where('character_id', $characterId)
            ->where('building_id', $teleportCenter['id'])
            ->first();
        if (!$centerRow) {
            return $this->sendError($chatId, "У тебя нет построенного Центра телепортации!");
        }

        // 4) Предмет "TeleportBeaconBasic"
        $beaconItem = $this->craftedItemsModel->where('name_eng', 'TeleportBeaconBasic')->first();
        if (!$beaconItem) {
            return $this->sendError($chatId, "Предмет 'TeleportBeaconBasic' не найден.");
        }
        $beaconLog = $this->craftedItemsLogModel
            ->where('character_id', $characterId)
            ->where('crafted_item_id', $beaconItem['id'])
            ->first();
        if (!$beaconLog || $beaconLog['quantity'] < 1) {
            return $this->sendError($chatId, "У тебя нет ни одного маяка в инвентаре!");
        }

        // 5) Лимит маяков
        $charRow       = $this->characterModel->find($characterId);
        $playerLevel   = (int)($charRow['level'] ?? 1);
        $buildingLevel = max(1, min(10, (int)$centerRow['level']));
        $baseMaxByPlayer = intdiv($playerLevel, 10);
        if ($baseMaxByPlayer > 10) {
            $baseMaxByPlayer = 10;
        }
        $maxBeacons    = $baseMaxByPlayer + $buildingLevel;

        $existingCount = $this->teleportBeaconModel
            ->where('character_id', $characterId)
            ->countAllResults();
        if ($existingCount >= $maxBeacons) {
            return $this->sendError($chatId, "Ты достиг лимита маяков ({$maxBeacons}). Удали старый, чтобы поставить новый.");
        }

        // 6) Ищем ячейку map
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

        // 6.1) Проверка базы (чужой) на этой точке
        if ($this->campCheckService->isCellClaimedByAnyone($cellNumber)) {
            return $this->sendError($chatId, "Невозможно установить маяк: здесь расположена база!");
        }

        // 7) Проверка, нет ли другого маяка (remaining_uses>0) на этих координатах
        $existingBeacon = $this->teleportBeaconModel
            ->where('coordinate_x', $coordX)
            ->where('coordinate_y', $coordY)
            ->where('remaining_uses >', 0)
            ->first();

        if ($existingBeacon) {
            if ($existingBeacon['character_id'] == $characterId) {
                return $this->sendError($chatId, "У тебя уже есть маяк на этой точке!");
            } else {
                // Чужой маяк
                return $this->showCaptureOption($chatId, $existingBeacon, $cellNumber);
            }
        }

        // 8) Если дошли сюда — ставим маяк
        $this->installBeacon($chatId, $characterId, $mapRow, $biomeId, $beaconItem, $playerLevel, $maxBeacons);

        // Возвращаем пустую «заглушку» или уже отправили сообщение внутри installBeacon
        return Request::emptyResponse();
    }

    /**
     * Вспомогательный метод, который действительно вставляет новую запись в teleport_beacons
     * и отправляет финальное сообщение «Маяк установлен».
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
        $mapCellId  = $mapRow['id'];
        $cellNumber = (int)$mapRow['cell_number'];

        // Вставляем
        $dataForBeacon = [
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
        ];
        $this->teleportBeaconModel->insert($dataForBeacon);

        // Теперь считаем общее
        $updatedCount = $this->teleportBeaconModel
            ->where('character_id', $characterId)
            ->countAllResults();

        // Списываем 1 маяк
        $this->craftedItemsLogModel->subtractItem($characterId, 'TeleportBeaconBasic', 1);

        // Узнаём, сколько осталось
        $beaconLogUpdated = $this->craftedItemsLogModel
            ->where('character_id',    $characterId)
            ->where('crafted_item_id', $beaconItem['id'])
            ->first();
        $beaconLeft = $beaconLogUpdated ? $beaconLogUpdated['quantity'] : 0;

        // Описание биома
        $biomeName          = '???';
        $biomeDescription   = '';
        $biomeType          = '';
        $dangerLevelText    = '';
        $biomeDangerLevel   = 0;
        $survivalText       = '';
        $survivalValue      = 0;
        $occurrenceRate     = 0;

        if ($biomeId) {
            $bRow = $this->biomeModel->find($biomeId);
            if ($bRow) {
                $biomeName        = $bRow['name']                       ?? '???';
                $biomeDescription = $bRow['description']                ?? '';
                $biomeType        = $bRow['biome_type']                 ?? '';
                $dangerLevelText  = $bRow['danger_level_text']          ?? '';
                $biomeDangerLevel = (int)($bRow['danger_level']         ?? 0);
                $survivalText     = $bRow['survival_difficulty_text']   ?? '';
                $survivalValue    = (int)($bRow['survival_difficulty']  ?? 0);
                $occurrenceRate   = (float)($bRow['occurrence_rate']    ?? 0);
            }
        }

        // Сообщение
        $textMessage = "⚙️ *Установка телепорт-маяка завершена!*\n\n"
            ."Ты успешно разместил маяк:\n"
            ."• Координаты: `X={$coordX}, Y={$coordY}` (#{$cellNumber})\n"
            ."• Биом: *{$biomeName}*\n"
            ."   _{$biomeDescription}_\n\n"
            ."🔋 *Запас прочности:* 100 использований\n\n"
            ."📋 *Характеристики биома:*\n"
            ."• Тип: `{$biomeType}`\n"
            ."• Опасность: {$biomeDangerLevel}/10 «{$dangerLevelText}»\n"
            ."• Сложность выживания: {$survivalValue}/10 «{$survivalText}»\n"
            ."• Частота встречаемости: {$occurrenceRate}%\n\n"
            ."📦 *Маяков в инвентаре:* {$beaconLeft}\n"
            ."🏗 Теперь у тебя *{$updatedCount}* маяков из макс. *{$maxBeacons}*\n\n"
            ."🔎 Надеюсь, никто не отыщет твой маяк и не разберёт его на детали...";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ['text' => '🏠 База',            'callback_data' => 'Base'],
                ],
            ]
        ];

        Request::sendMessage([
            'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'       => $textMessage,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Показ диалогового окошка о найденном чужом маяке:
     * Можно «Перехватить» или «Отменить».
     */
    private function showCaptureOption(int $chatId, array $existingBeacon, int $cellNumber): ServerResponse
    {
        $uses = (int)$existingBeacon['remaining_uses'];
        $text = "На этой точке уже обнаружен *чужой* маяк!\n"
            ."Остаток телепортов: {$uses}\n\n"
            ."Ты можешь его *перехватить* (присвоить себе) или *отказаться* (ничего не делать).";

        // Колбэк для перехвата: "teleportBeaconSet_capture_id=XXX"
        // Колбэк для отмены:     "teleportBeaconSet_cancel"
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
     * Обработка нажатия «Перехватить».
     * Меняем у маяка character_id, ownership_type = 'captured'.
     * Уведомляем старого владельца, если он не совпадает с нами.
     */
    private function captureBeacon(int $beaconId, int $chatId): ServerResponse
    {
        // 1) Определяем текущего игрока
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

        // 3) Проверяем, не наш ли уже это маяк
        if ((int)$beaconRow['character_id'] === $characterId) {
            // Alert + сообщение — сообщаем, что уже твой
            Request::answerCallbackQuery([
                'callback_query_id' => $this->callbackQuery->getId(),
                'text'             => 'Уже твой маяк!',
                'show_alert'       => true,
            ]);

            return $this->sendError($chatId, "Этот маяк уже принадлежит тебе!");
        }

        // 4) Старый владелец
        $oldOwnerId = (int)$beaconRow['character_id'];

        // 5) Меняем character_id, ownership_type
        $this->teleportBeaconModel->update($beaconId, [
            'character_id'   => $characterId,
            'ownership_type' => 'captured',
        ]);

        // 6) Уведомляем прежнего владельца, если он действительно чужой
        if ($oldOwnerId !== $characterId) {
            $this->notifyOldOwner($oldOwnerId, $beaconRow);
        }

        // 7) Формируем текст для уведомления
        $x    = $beaconRow['coordinate_x'];
        $y    = $beaconRow['coordinate_y'];
        $uses = $beaconRow['remaining_uses'];

        $text = "✅ *Маяк перехвачен!*\n\n"
            . "Теперь он твой (ownership_type='captured').\n"
            . "Координаты: (X={$x}, Y={$y})\n"
            . "Остаток телепортов: {$uses}";

        // 8) Попытка №1: редактирование исходного сообщения с кнопками
        $messageId  = $this->callbackQuery->getMessage()->getMessageId();
        $editResult = Request::editMessageText([
            'chat_id'    => $chatId,
            'message_id' => $messageId,
            'text'       => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => []]), // убираем кнопки
        ]);

        // 9) Попытка №2: если редактирование не удалось, отправляем новое сообщение
        //  ПРОВЕРЯЕМ  $editResult  НА  null  !!!
        if ($editResult === null || !$editResult->isOk()) {
            Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => $text,
                'parse_mode' => 'Markdown',
            ]);
        }

        // 10) Попытка №3: показываем Alert (всплывающее окно) в любом случае
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text'             => 'Маяк перехвачен!',
            'show_alert'       => true,
        ]);

        // Возвращаем пустой ответ — все уведомления уже сделаны
        return Request::emptyResponse();
    }

    /**
     * Уведомляем старого владельца, что маяк захвачен
     */
    private function notifyOldOwner(int $oldOwnerId, array $beaconRow): void
    {
        // 1. Найти characterRow
        $oldChar = $this->characterModel->find($oldOwnerId);
        if (!$oldChar) {
            return; // не нашли
        }

        $tgUserId   = (int)$oldChar['telegram_user_id'];
        if (!$tgUserId) {
            return; // нет связи с telegram_users
        }

        // 2. Найти telegram_id
        $db = db_connect();
        $row = $db->table('telegram_users')
            ->select('telegram_id')
            ->where('id', $tgUserId)
            ->get()
            ->getRowArray();
        if (!$row) {
            return;
        }
        $oldOwnerTgId = (int)$row['telegram_id'];

        // 3. Отправить сообщение
        $x    = $beaconRow['coordinate_x'];
        $y    = $beaconRow['coordinate_y'];
        $uses = $beaconRow['remaining_uses'];

        $text = "‼️ *Тревога!*\n"
            ."Твой маяк на координатах (X={$x}, Y={$y}) был перехвачен.\n"
            ."Остаток телепортов: {$uses}\n"
            ."Теперь маяк тебе не принадлежит :(";

        Request::sendMessage([
            'chat_id'    => $oldOwnerTgId,
            'text'       => $text,
            'parse_mode' => 'Markdown',
        ]);
    }

    /**
     * Обработка нажатия «Отменить» (ни маяк не ставим, ни чужой маяк не трогаем)
     */
    private function cancelSet(int $chatId): ServerResponse
    {
        return Request::sendMessage([
            'chat_id'    => $chatId,
            'text'       => "Операция установки (или перехвата) маяка отменена.",
            'parse_mode' => 'Markdown',
        ]);
    }

    private function sendError(int $chatId, string $msg): ServerResponse
    {
        return Request::sendMessage([
            'chat_id'    => $chatId,
            'text'       => $msg,
            'parse_mode' => 'Markdown',
        ]);
    }
}
