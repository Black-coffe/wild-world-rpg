<?php

namespace App\Controllers\Telegram\Commands\Actions\Camp\Buildings;

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;

// Модели
use App\Models\TeleportBeaconModel;
use App\Models\MapModel;
use App\Models\BiomeModel;
use App\Models\CharacterModel;

/**
 * Класс TeleportBeaconMoveAction:
 * Показывает список маяков игрока и даёт кнопки для выбора, куда переместиться.
 */
class TeleportBeaconMoveAction
{
    protected CallbackQuery $callbackQuery;

    protected TeleportBeaconModel $teleportBeaconModel;
    protected MapModel            $mapModel;
    protected BiomeModel          $biomeModel;
    protected CharacterModel      $characterModel;

    public function __construct(CallbackQuery $callbackQuery)
    {
        $this->callbackQuery       = $callbackQuery;
        $this->teleportBeaconModel = new TeleportBeaconModel();
        $this->mapModel            = new MapModel();
        $this->biomeModel          = new BiomeModel();
        $this->characterModel      = new CharacterModel();
    }

    public function handle(): ServerResponse
    {
        // 1) Убираем "часики" на нажатой кнопке
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
        ]);

        // 2) Получаем chat_id
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        // 3) Узнаём telegramId
        $telegramUserId = $this->callbackQuery->getFrom()->getId();

        // 4) Находим characterId
        $characterId = $this->characterModel->getCharacterIdByTelegramId($telegramUserId);
        if (!$characterId) {
            return $this->sendError($chatId, "Ошибка: персонаж не найден для пользователя #{$telegramUserId}.");
        }

        // 5) Получаем все маяки игрока
        $beacons = $this->teleportBeaconModel
            ->where('character_id', $characterId)
            ->findAll();

        $beaconCount = count($beacons);
        if ($beaconCount === 0) {
            // Если у игрока нет ни одного маяка
            return Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => "У тебя пока нет установленных маяков, перемещаться некуда!",
                'parse_mode' => 'Markdown'
            ]);
        }

        // 6) Собираем текстовый список маяков и готовим кнопки
        $lines = [];             // строки с описанием маяков
        $inlineKeyboard = [];    // матрица кнопок (каждый элемент массива — это «ряд» кнопок)
        $rowButtons = [];        // кнопки в текущем ряду

        foreach ($beacons as $beacon) {
            $beaconId      = $beacon['id'];
            $x             = $beacon['coordinate_x'];
            $y             = $beacon['coordinate_y'];
            $remainingUses = $beacon['remaining_uses'];

            // Получаем mapRow, чтобы вытащить cell_number и biome_id
            $mapRow = $this->mapModel->find($beacon['map_cell_id']);
            $cellNumber = $mapRow['cell_number'] ?? 0;
            $biomeId    = $mapRow['biome_id']    ?? null;

            // Получаем название биома
            $biomeName = "???";
            if ($biomeId) {
                $biomeRow = $this->biomeModel->find($biomeId);
                if ($biomeRow) {
                    $biomeName = $biomeRow['name'] ?? "???";
                }
            }

            // Формируем строчку описания маяка
            $lines[] = "👉 X={$x}, Y={$y} | 🧷#{$cellNumber} | 🪸{$biomeName} | ТП. {$remainingUses}";

            // Создаём кнопку
            // Вместо координат используем ID маяка:
            $callbackData = "teleportBeaconMoveGo_id={$beaconId}";
            $buttonText   = "🌎 Маяк (X={$x}, Y={$y})";

            $rowButtons[] = [
                'text'          => $buttonText,
                'callback_data' => $callbackData
            ];

            // Каждые 2 маяка — новая строка
            if (count($rowButtons) === 2) {
                $inlineKeyboard[] = $rowButtons;
                $rowButtons = [];
            }
        }

        // Добавляем «хвостовой» ряд, если остались кнопки
        if (!empty($rowButtons)) {
            $inlineKeyboard[] = $rowButtons;
        }

        // 7) Финальный текст
        $introText = "У тебя сейчас *{$beaconCount}* установленных маяков.\n".
            "Выбери, к какому из них ты хочешь переместиться:\n\n";
        // Собираем все строки о маяках
        $textMessage = $introText . implode("\n", $lines)
            . "\n\n"
            . "🤔 *Не откладывай решения!* Действуй 🏃🏻‍➡️";

        // 8) Отправляем ответ с кнопками
        return Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => $textMessage,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $inlineKeyboard]),
        ]);
    }

    private function sendError(int $chatId, string $message): ServerResponse
    {
        return Request::sendMessage([
            'chat_id'    => $chatId,
            'text'       => $message,
            'parse_mode' => 'Markdown',
        ]);
    }
}
