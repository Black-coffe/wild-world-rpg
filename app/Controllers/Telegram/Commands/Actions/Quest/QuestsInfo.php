<?php

namespace App\Controllers\Telegram\Commands\Actions\Quest;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Notifications\MediaSender;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use App\Models\QuestModel;
use App\Models\CharacterModel;

class QuestsInfo extends BaseAction
{
    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        // Создаем модели квестов и персонажей
        $questModel = new QuestModel();
        $characterModel = new CharacterModel();

        // Получаем информацию о персонаже по его chat_id
        $characterId = $characterModel->getCharacterIdByTelegramId($chatId);
        $character = $characterModel->find($characterId);

        if (!$character) {
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Персонаж не найден.',
                'parse_mode' => 'Markdown',
            ]);
        }

        $callbackData = $this->callbackQuery->getData();
        $params = explode('_', $callbackData);

        // Получаем список всех квестов
        $quests = $questModel->findAll();

        if(isset($params[1])){
            if ($params[1] == "Explore30Cells") {
                return $this->sendQuestInfoExplore30Cells($chatId);
            }elseif ($params[1] == "ExploreAllBiomes") {
                return $this->sendQuestInfoExploreAllBiomes($chatId);
            }elseif ($params[1] == "Explore300Cells") {
                return $this->sendQuestInfoExplore300Cells($chatId);
            }elseif ($params[1] == "FirstAidkitBasic") {
                return $this->sendQuestInfoFirstAidkitBasic($chatId);
            }
        }

        if (empty($quests)) {
            $text = "В игре отсутствуют квесты. Проверьте позже!";
        } else {
            // Формируем текст приветствия и краткого описания квестов
            $text = "*📜 Список всех квестов:*\n\n";
            foreach ($quests as $quest) {
                $text .= "🔹 {$quest['title_ru']}\n";
            }
            $text .= "\n_Выберите квест 👇, чтобы получить подробную информацию_";
        }
        $keyboard = $this->generateQuestKeyboard($quests);
        // Отправляем сообщение с информацией о квестах
        return $this->sendTelegramMessage($chatId, $text, $keyboard);
    }

    private function generateQuestKeyboard($quests)
    {
        $keyboard = ['inline_keyboard' => []];
        $row = [];
        foreach ($quests as $quest) {
            $row[] = ['text' => $quest['title_ru'], 'callback_data' => 'questInfo_' . $quest['title_en']];
            if (count($row) == 2) {
                $keyboard['inline_keyboard'][] = $row;
                $row = [];
            }
        }
        if (!empty($row)) {
            $keyboard['inline_keyboard'][] = $row;
        }

        // Add standard buttons
        $keyboard['inline_keyboard'][] = [
            ['text' => '📜 Квесты и задания', 'callback_data' => 'questAndTask'],
            ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
        ];

        return $keyboard;
    }

    // Универсальный метод отправки сообщения в чат.
    // #12 edit-in-place (ADR-018): список квестов / карточка квеста — навигация → редактируем
    // сообщение, на котором нажата кнопка (fallback на новое при ошибке/клике с photo-экрана).
    // $chatId сохранён в сигнатуре для совместимости с вызовами, но chat_id берётся из navTarget().
    private function sendTelegramMessage($chatId, $text, $keyboard = null)
    {
        if (!$keyboard) {
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '📜 Квесты и задания', 'callback_data' => 'questAndTask'],
                        ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character']
                    ],
                ]
            ];
        }

        // Остановка анимации загрузки
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return MediaSender::editTextOrSend($this->navTarget() + [
            'text' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    // Метод для отправки информации о квесте "Изучить 30 ячеек"
    private function sendQuestInfoFirstAidkitBasic($chatId)
    {
        $text = "*🔍 Крафт: Аптечки базовой*\n\n";
        $text .= "📜 *Описание* 📜\n_Создай крафтовый, медицинский предмет_ *Аптечка базовая* _и как вознаграждение получи 1500 золотых монет. Кроме награды, ты окунешься в мир крафта и поймешь механику взаимосвязанных крафтовых предметов._\n*Важно!* _Не просто создай аптечку,но чтобы она побыла в инвентаре пару минут, тогда применится награда и закроется квест_\n\n";
        $text .= "🔒 *Условия*: Доступен с *3-го* уровня персонажа.\n\n";
        $text .= "🏆 *Награды*: 1 500 золотых монет.\n\n";
        $text .= "🔄 *Одноразовый*\n\n";
        $text .= "⏳ *Имеет срок выполнения или бессрочный*: Бессрочный";

        return $this->sendTelegramMessage($chatId, $text);
    }

    // Метод для отправки информации о квесте "Изучить 30 ячеек"
    private function sendQuestInfoExplore30Cells($chatId)
    {
        $text = "*🔍 Изучить 30 ячеек*\n\n";
        $text .= "📜 *Описание* 📜\n_Исследуйте 30 различных ячеек на карте мира и получите за это вознаграждение в виде 1000 золотых монет! Квест может быть выполнен в любое время начиная с первого уровня игры!_\n\n";
        $text .= "🔒 *Условия*: Доступен с *1-го* уровня персонажа.\n\n";
        $text .= "🏆 *Награды*: 1 000 золотых монет.\n\n";
        $text .= "🔄 *Одноразовый*\n\n";
        $text .= "⏳ *Имеет срок выполнения или бессрочный*: Бессрочный";

        return $this->sendTelegramMessage($chatId, $text);
    }

    // Метод для отправки информации о квесте "Изучить 30 ячеек"
    private function sendQuestInfoExplore300Cells($chatId)
    {
        $text = "*🔍 Изучить 300 ячеек*\n\n";
        $text .= "📜 *Описание* 📜\n_Исследуйте 300 различных ячеек на карте мира и получите за это вознаграждение в виде 10 000 золотых монет!\n Также по+ 2  единицы к опыту,  силе, ловкости и интеллекту.\nКвест может быть выполнен в любое время начиная с 10-го уровня игры!_\n\n";
        $text .= "🔒 *Условия*: Доступен с *10-го* уровня персонажа.\n\n";
        $text .= "🏆 *Награды*: 10 000 золотых монет.\n\n";
        $text .= "🔄 *Одноразовый*\n\n";
        $text .= "⏳ *Имеет срок выполнения или бессрочный*: Бессрочный";

        return $this->sendTelegramMessage($chatId, $text);
    }

    // Метод для отправки информации о квесте "Изучить все биомы"
    private function sendQuestInfoExploreAllBiomes($chatId)
    {
        $text = "*🌎 Изучить все биомы*\n\n";
        $text .= "📜 *Описание* 📜\n_Исследуйте все биомы, доступные на карте мира. Всего их есть 9 штук, как только вы хотя бы раз увидите каждый из  них вы получите награду!_\n\n";
        $text .= "🔒 *Условия*: Доступен с *1-го* уровня персонажа.\n\n";
        $text .= "🏆 *Награды*: 2 опыта.\n\n";
        $text .= "🔄 *Одноразовый*\n\n";
        $text .= "⏳ *Имеет срок выполнения или бессрочный*: Бессрочный";

        return $this->sendTelegramMessage($chatId, $text);
    }

}
