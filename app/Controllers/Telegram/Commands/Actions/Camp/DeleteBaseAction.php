<?php

namespace App\Controllers\Telegram\Commands\Actions\Camp;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterBuildingModel;
use App\Models\ClaimedCellModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * Класс DeleteBaseAction:
 * удаляет у игрока все записи в character_buildings,
 * а также запись в claimed_cells (база).
 */
class DeleteBaseAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();

        // Базовые проверки
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => '🤖 Ошибка: пользователь или персонаж не найден.',
            ]);
        }

        $callbackData = $this->callbackQuery->getData();

        // Если пришёл второй колбэк "DeleteBase_Confirm"
        if ($callbackData === 'DeleteBase_Confirm') {
            return $this->confirmDeleteBase($character);
        }

        // Иначе показываем "предупреждение/подтверждение"
        return $this->showDeleteBaseConfirmation();
    }

    /**
     * 1) Спрашиваем подтверждение, действительно ли удалить базу.
     */
    private function showDeleteBaseConfirmation(): ServerResponse
    {
        $text = "🤖 Это снова я – *Роби*!\n\n"
            . "Ты собираешься удалить свою базу насовсем. "
            . "Все постройки, все что есть на территории базы, и сам лагерь будут безвозвратно стёрты.\n\n"
            . "Действие это необратимо и не принесёт никакой компенсации.\n\n"
            . "Если ты действительно уверен — нажми кнопку удаления.\n"
            . "Если нет — вернись в меню персонажа.";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '❌ Удалить навсегда', 'callback_data' => 'DeleteBase_Confirm'],
                ],
                [
                    ['text' => 'Отмена', 'callback_data' => 'characterActions'],
                ],
            ]
        ];

        return Request::sendMessage([
            'chat_id'      => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * 2) Собственно удаление всех записей про постройки игрока и сам лагерь.
     */
    private function confirmDeleteBase(array $character): ServerResponse
    {
        // Модель постройки "игрока"
        $characterBuildingModel = new CharacterBuildingModel();
        // Модель "захваченной ячейки" (лагерь)
        $claimedCellModel = new ClaimedCellModel();

        // Удаляем ВСЕ постройки игрока (не только в ячейке базы),
        // если нужно стереть вообще все его здания.
        // Если надо удалить только в текущей ячейке базы —
        // можно добавить фильтр ->where('map_cell_id', $character['cell_number']).
        $characterBuildingModel
            ->where('character_id', $character['id'])
            ->delete();

        // Удаляем все записи о лагере (claimed_cells) у данного игрока
        $claimedCellModel
            ->where('character_id', $character['id'])
            ->delete();

        // Формируем ответ
        $text = "🤖 Это снова я – *Роби*!\n\n"
            . "Ты успешно удалил свою базу со всеми ее сооружениями. "
            . "Надеюсь, это решение было взвешенным. В случае чего — "
            . "ты всегда можешь заново разбить лагерь позже.";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🎮 Развлечения', 'callback_data' => 'entertainment'],
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ['text' => '🎉 События',      'callback_data' => 'events']
                ],
                [
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ['text' => '🛒 Магазин',   'callback_data' => 'shop'],
                ],
            ]
        ];

        // Отвечаем
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return Request::sendMessage([
            'chat_id'      => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
