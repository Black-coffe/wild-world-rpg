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

// Сервисы
use App\Services\Bases\CampCheckService;
use App\Services\Player\TeleportBeacon\BeaconMessageFormatter;
use App\Services\Player\TeleportBeacon\BeaconPlacementValidator;

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

    // v0.51.52 (Step 1) — validation chain extracted у dedicated service.
    protected BeaconPlacementValidator $placementValidator;

    // v0.51.53 (Step 2) — Markdown templates extracted у formatter.
    protected BeaconMessageFormatter $formatter;

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
        $this->placementValidator     = new BeaconPlacementValidator(
            $this->characterModel,
            $this->claimedCellModel,
            $this->buildingModel,
            $this->characterBuildingModel,
            $this->craftedItemsModel,
            $this->craftedItemsLogModel,
            $this->mapModel,
            $this->teleportBeaconModel,
            $this->campCheckService
        );
        $this->formatter              = new BeaconMessageFormatter();
    }

    /**
     * Helper (v0.51.53): send message via Request::sendMessage з payload-array.
     * Зливає chat_id з content payload.
     *
     * @param array<string,mixed> $payload
     */
    private function send(int|string $chatId, array $payload): ServerResponse
    {
        return Request::sendMessage(array_merge(['chat_id' => $chatId], $payload));
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
     *
     * v0.51.52 (Step 1) — validation chain (~85 LOC) винесена у
     * BeaconPlacementValidator. Тут залишилось branching: error / capture / install.
     */
    private function processSetBeacon(int $chatId, int $coordX, int $coordY): ServerResponse
    {
        $telegramUserId = $this->callbackQuery->getFrom()->getId();
        $result = $this->placementValidator->validate((int) $telegramUserId, $coordX, $coordY);

        if (!$result['ok']) {
            return $this->sendError($chatId, $result['error']);
        }

        // Foreign beacon at coords → capture flow
        if ($result['existingBeacon'] !== null) {
            return $this->showCaptureOption($chatId, $result['existingBeacon'], (int) $result['mapRow']['cell_number']);
        }

        // All clear → install
        $biomeId = $result['mapRow']['biome_id'] ?? null;
        $this->installBeacon(
            $chatId,
            $result['characterId'],
            $result['mapRow'],
            $biomeId,
            $result['beaconItem'],
            $result['playerLevel'],
            $result['maxBeacons']
        );

        return Request::emptyResponse();
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
        $beaconLeft = $beaconLogUpdated ? (int) $beaconLogUpdated['quantity'] : 0;

        // Biome lookup (v0.51.53 — formatting moved до formatter)
        $biomeRow = $biomeId ? $this->biomeModel->find($biomeId) : null;

        $this->send($chatId, $this->formatter->installSuccess(
            (int) $coordX, (int) $coordY, $cellNumber,
            $biomeRow,
            $beaconLeft, (int) $updatedCount, $maxBeacons
        ));
    }

    /**
     * Показ диалога о найденном чужом маяке (remaining_uses>0):
     * «Перехватить!» или «Отменить».
     */
    private function showCaptureOption(int $chatId, array $existingBeacon, int $cellNumber): ServerResponse
    {
        return $this->send($chatId, $this->formatter->captureOption($existingBeacon));
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

        // 7) Текст для нового владельца (v0.51.53 — formatter)
        $text = $this->formatter->captureSuccess($beaconRow);

        // 8) Пытаемся отредактировать текущее сообщение (убираем кнопки)
        $messageId  = $this->callbackQuery->getMessage()->getMessageId();
        $editResult = Request::editMessageText([
            'chat_id'      => $chatId,
            'message_id'   => $messageId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => (string) json_encode(['inline_keyboard' => []]),
        ]);

        // 9) Если редактирование не удалось, шлём новое сообщение
        if ($editResult === null || !$editResult->isOk()) {
            $this->send($chatId, ['text' => $text, 'parse_mode' => 'Markdown']);
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

        // 3) Отправляем (v0.51.53 — formatter)
        $this->send($oldOwnerTgId, $this->formatter->oldOwnerAlert($beaconRow));
    }

    /**
     * Отмена (ни маяк не ставим, ни чужой не трогаем)
     */
    private function cancelSet(int $chatId): ServerResponse
    {
        return $this->send($chatId, $this->formatter->cancel());
    }

    /**
     * Универсальный метод для отправки ошибки в чат
     */
    private function sendError(int $chatId, string $msg): ServerResponse
    {
        return $this->send($chatId, $this->formatter->error($msg));
    }
}
