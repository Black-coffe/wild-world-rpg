<?php
namespace App\Controllers\Telegram\Commands\Actions\Camp;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\ClaimedCellModel;
use App\Models\MapModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class CampCreateConfirmAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        // 1. Закрываем "часики" на инлайн-кнопке
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId()
        ]);

        // 2. Достаём $chatId, $character
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();

        // 3. Подтверждаем, что у игрока НЕТ базы (ещё раз можно проверить)
        //    и что cell_number вообще корректен:
        $cellNumber = $character['cell_number'] ?? 0;
        if (!$cellNumber) {
            return Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => 'Ошибка: у вашего персонажа нет координаты cell_number!',
                'parse_mode' => 'Markdown'
            ]);
        }

        // 4. Достаём mapRow, чтобы знать map.id
        $mapModel = new MapModel();
        $mapRow   = $mapModel->where('cell_number', $cellNumber)->first();
        if (!$mapRow) {
            return Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => "Ошибка: не найдена карта для cell_number={$cellNumber}",
                'parse_mode' => 'Markdown'
            ]);
        }

        // 5. Сохраняем в claimed_cells
        $claimedCellModel = new ClaimedCellModel();
        $newCampData = [
            'character_id' => $character['id'],
            'map_cell_id'  => $mapRow['id'],
            'claimed_at'   => date('Y-m-d H:i:s'),
            'status'       => 'active',
        ];
        $claimedCellModel->save($newCampData);

        // 6. Сообщаем об успехе
        $text = "Ты успешно разбил лагерь на клетке (X={$mapRow['coordinate_x']}, Y={$mapRow['coordinate_y']}).\n"
            . "Теперь это твоя база!";

        return Request::sendMessage([
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'Markdown'
        ]);
    }
}
