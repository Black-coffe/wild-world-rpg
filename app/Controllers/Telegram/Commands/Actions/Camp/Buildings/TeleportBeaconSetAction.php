<?php

namespace App\Controllers\Telegram\Commands\Actions\Camp\Buildings;

use App\Services\Telegram\Request;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;

// Models — only those used directly у Action body (после Step 1-4 decomp).
use CodeIgniter\Entity\Entity;

use App\Models\BiomeModel;
use App\Models\CharacterModel;

// Сервисы — encapsulate всю domain логику.
use App\Services\Db\WriteOutcome;
use App\Services\Player\TeleportBeacon\BeaconCaptureService;
use App\Services\Player\TeleportBeacon\BeaconInstaller;
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

    // Models used directly у Action body (1 inline call each):
    protected CharacterModel $characterModel; // captureBeacon: getCharacterIdByTelegramId()
    protected BiomeModel     $biomeModel;     // installBeacon orchestrator: find($biomeId)

    // v0.51.52 (Step 1): validation chain → service.
    protected BeaconPlacementValidator $placementValidator;

    // v0.51.53 (Step 2): Markdown templates → formatter.
    protected BeaconMessageFormatter $formatter;

    // v0.51.54 (Step 3): DB write + inventory → installer.
    protected BeaconInstaller $installer;

    // v0.51.55 (Step 4): capture flow + notify → service.
    protected BeaconCaptureService $captureService;

    /**
     * v0.51.56 (Step 5 polish — final). Action ctor очищений: 11 моделей +
     * 1 service у Step 0 → 2 моделі + 4 services. Услуги instantiate own
     * dependencies через nullable defaults — Action не передає моделі
     * (CI4 Models lightweight з shared DB connection — overhead ≈ 0).
     */
    public function __construct(CallbackQuery $callbackQuery)
    {
        $this->callbackQuery      = $callbackQuery;
        $this->characterModel     = new CharacterModel();
        $this->biomeModel         = new BiomeModel();

        $this->placementValidator = new BeaconPlacementValidator();
        $this->formatter          = new BeaconMessageFormatter();
        $this->installer          = new BeaconInstaller();
        $this->captureService     = new BeaconCaptureService();
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
     * Створюємо запис у teleport_beacons, списуємо маяк з інвентаря, шлемо
     * повідомлення.
     *
     * v0.51.53 (Step 2) — formatting extract → BeaconMessageFormatter.
     * v0.51.54 (Step 3) — DB write + inventory extract → BeaconInstaller.
     * exploit-fix-07 (ADR-181) — `install()` теперь списывает предмет ДО вставки
     * маяка и возвращает исход (`WriteOutcome`): на отказе маяк не поставлен, и
     * игрок получает честное сообщение вместо тишины или ложного «успеха».
     * Метод тепер тонкий orchestrator: install → outcome check → biome lookup →
     * format → send.
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
        // 1) Persistence (списание предмета → INSERT маяка, одной транзакцией).
        $counters = $this->installer->install($characterId, $mapRow, $playerLevel, $beaconItem);

        if ($counters['outcome'] !== WriteOutcome::Applied) {
            $this->send($chatId, $this->formatter->error(
                'Не удалось поставить маяк: в инвентаре не нашлось предмета «Маяк телепорта» '
                . 'в момент списания (кто-то опередил или он уже кончился). Маяк не поставлен, '
                . 'предмет не списан. Проверь остаток на экране «Маяки» и попробуй ещё раз.'
            ));

            return;
        }

        // 2) Biome lookup (потрібен для display details).
        //
        // 🔴 Прод-баг 2026-07-08 (char 1106): BiomeModel::$returnType = BiomeEntity (F1.4.1),
        // а installSuccess() оголошує `?array $biomeRow` → strict_types кидав TypeError.
        // Маяк на той момент УЖЕ встановлено (крок 1) — гравець не бачив підтвердження.
        // Конвертуємо Entity→array в ОКРЕМУ змінну (урок entity_strict_array_typehint_trap).
        $biomeFound = $biomeId ? $this->biomeModel->find($biomeId) : null;
        $biomeRow   = $biomeFound instanceof Entity ? $biomeFound->toArray() : null;

        // 3) Format + send.
        $this->send($chatId, $this->formatter->installSuccess(
            (int) $mapRow['coordinate_x'],
            (int) $mapRow['coordinate_y'],
            (int) $mapRow['cell_number'],
            $biomeRow,
            $counters['beaconLeft'],
            $counters['updatedCount'],
            $maxBeacons,
            (new \App\Services\Player\TeleportBeacon\BeaconSettings())->maxUses()
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
     * Перехват чужого маяка: orchestrator only.
     *
     * v0.51.55 (Step 4): DB ops + notify lookup → BeaconCaptureService.
     * Action method тепер: lookup captor → service.captureBeacon() →
     *   route by status → format message → edit/send + alert callback.
     */
    private function captureBeacon(int $beaconId, int $chatId): ServerResponse
    {
        // 1) Captor character
        $telegramUserId = $this->callbackQuery->getFrom()->getId();
        $captorCharId   = $this->characterModel->getCharacterIdByTelegramId($telegramUserId);
        if (!$captorCharId) {
            return $this->sendError($chatId, "Персонаж не найден (TG={$telegramUserId}).");
        }

        // 2) Виконуємо capture (DB ops у service)
        $result = $this->captureService->captureBeacon($beaconId, (int) $captorCharId);

        if ($result['status'] === 'not_found') {
            return $this->sendError($chatId, "Маяк #{$beaconId} не найден (возможно, уже удалён?).");
        }

        if ($result['status'] === 'self_capture') {
            Request::answerCallbackQuery([
                'callback_query_id' => $this->callbackQuery->getId(),
                'text'              => 'Уже твой маяк!',
                'show_alert'        => true,
            ]);
            return $this->sendError($chatId, "Этот маяк уже принадлежит тебе!");
        }

        // 3) Captured! Notify old owner (если є).
        $beaconRow = $result['beacon'];
        $oldOwnerCharId = $result['oldOwnerCharId'] ?? null;
        if ($oldOwnerCharId && $oldOwnerCharId !== $captorCharId) {
            $oldOwnerTgId = $this->captureService->lookupOwnerTelegramId($oldOwnerCharId);
            if ($oldOwnerTgId) {
                $this->send($oldOwnerTgId, $this->formatter->oldOwnerAlert($beaconRow));
            }
        }

        // 4) Текст для нового власника + edit OR fallback send.
        $text       = $this->formatter->captureSuccess($beaconRow);
        $messageId  = $this->callbackQuery->getMessage()->getMessageId();
        $editResult = Request::editMessageText([
            'chat_id'      => $chatId,
            'message_id'   => $messageId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => (string) json_encode(['inline_keyboard' => []]),
        ]);
        if ($editResult === null || !$editResult->isOk()) {
            $this->send($chatId, ['text' => $text, 'parse_mode' => 'Markdown']);
        }

        // 5) Alert захватчику
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text'              => 'Маяк перехвачен!',
            'show_alert'        => true,
        ]);

        return Request::emptyResponse();
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
