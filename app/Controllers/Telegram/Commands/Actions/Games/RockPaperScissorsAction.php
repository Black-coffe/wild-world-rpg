<?php

namespace App\Controllers\Telegram\Commands\Actions\Games;

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Entities\InlineKeyboard;
use App\Models\CharacterModel;
use App\Controllers\Telegram\Commands\Actions\BaseAction;

class RockPaperScissorsAction extends BaseAction {
    protected $characterModel;
    protected $character;
    protected $user;

    public function __construct($callbackQuery) {
        parent::__construct($callbackQuery);
        $this->characterModel = new CharacterModel();
    }

    public function handle(): ServerResponse {
        [$this->user, $this->character] = $this->getUserAndCharacter();

        if (!$this->user || !$this->character) {
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text' => 'Пользователь не найден в базе данных или персонаж не определён.',
            ]);
        }

        if ($this->character['experience'] < 0.05 or $this->character['strength'] < 0.05 or $this->character['agility'] < 0.05 or $this->character['intellect'] < 0.05) {
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text' => 'К сожалению, у вас недостаточно необходимых навыков для этой игры!.',
            ]);
        }

        $callbackData = $this->callbackQuery->getData();
        $params = explode('_', $callbackData);

        // Разбор логики в зависимости от параметров
        if (count($params) == 1) {
            // Если дополнительных параметров нет, показываем стартовое меню или экран
            return $this->showOptions();
        } elseif($params[1] === 'param') {
            return $this->showRPSOptions($params);
        } elseif ($params[1] === 'option') {
            return $this->handleUserChoice($params);
        } else {
            // Непредвиденный запрос
            return $this->showOptions();
        }
    }

    protected function showOptions(): ServerResponse {
        $text = "*🗿✂️📄 Камень, Ножницы, Бумага: 🗿✂️📄*\n\n"
            . "*Испытание судьбы*\n\n"
            . "_Сегодня перед тобой стоит возможность проверить свою удачу и интуицию в азартной и захватывающей игре_\n\n"
            . "*Камень, Ножницы, Бумага 🎲*\n\n"
            . "*Помни*, твой выбор может либо принести победу, либо оставить тебя с пустыми руками 🔢\n\n"
            . "Сыграй на выбор: *опыт, сила, ловкость или интеллект*\n\n"
            . "Промахнёшься - *потеряешь ставку!* 😥\n\n"
            . "*Готов к азарту?*\n\n"
            . "*⬇️ Делай ставку:*\n";
        $keyboard = new InlineKeyboard([
            ['text' => '🌟 Опыт', 'callback_data' => 'RockPaperScissors_param_experience'],
            ['text' => '💪 Сила', 'callback_data' => 'RockPaperScissors_param_strength'],
            ['text' => '🤸‍♂️ Ловкость', 'callback_data' => 'RockPaperScissors_param_agility'],
            ['text' => '🧠 Интеллект', 'callback_data' => 'RockPaperScissors_param_intellect'],
        ]);

        $imagePath = base_url('uploads/telegram/stone_paper_scissors.png'); // Укажите актуальный путь к изображению
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return \App\Services\Notifications\MediaSender::sendPhotoOrText([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'   => Request::encodeFile($imagePath),
            'caption' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    protected function showRPSOptions($params): ServerResponse {
        $param = $params[2];
        $text = "Игра началась, ты смелый и отважный!\n\nСделай свой ход:";
        $keyboard = new InlineKeyboard([
            ['text' => '🗿 Камень', 'callback_data' => 'RockPaperScissors_option_'.$param.'_rock'],
            ['text' => '✂️ Ножницы', 'callback_data' => 'RockPaperScissors_option_'.$param.'_scissors'],
            ['text' => '📄 Бумага', 'callback_data' => 'RockPaperScissors_option_'.$param.'_paper'],
        ]);
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendMessage([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text' => $text,
            'reply_markup' => $keyboard,
        ]);
    }

    public function handleUserChoice($params): ServerResponse {
        $userChoice = $params[3];
        $param = $params[2];
        $choices = [
            'rock' => 'Камень',
            'scissors' => 'Ножницы',
            'paper' => 'Бумага'
        ];
        // Компьютерный выбор – равновероятно
        $computerChoiceKey = array_rand($choices);
        $computerChoice = $choices[$computerChoiceKey];

        // Логика определения результата по стандартным правилам
        if ($userChoice === $computerChoiceKey) {
            $text = "Ничья! Вы оба выбрали {$choices[$userChoice]}.";
        } else {
            $winConditions = [
                'rock' => 'scissors',
                'scissors' => 'paper',
                'paper' => 'rock',
            ];

            if ($winConditions[$userChoice] === $computerChoiceKey) {
                // Стандартно игрок выигрывал бы
                // Понижаем шансы выигрыша: победа засчитывается только в 40% случаев
                if (rand(1, 100) <= 40) {
                    $this->updateCharacterParam($this->character['id'], true, $param);
                    $text = "Вы победили! Ваш выбор ({$choices[$userChoice]}) побеждает над {$computerChoice}.";
                } else {
                    // В остальных 60% случаев выигрыш отменяется
                    $this->updateCharacterParam($this->character['id'], false, $param);
                    $text = "К сожалению, фортуна отвернулась от тебя. Несмотря на первоначальное преимущество, ты проиграл.";
                }
            } else {
                $this->updateCharacterParam($this->character['id'], false, $param);
                $text = "Вы проиграли! Ваш выбор ({$choices[$userChoice]}) уступает {$computerChoice}.";
            }
        }

        $keyboard = new InlineKeyboard([
            ['text' => 'Сыграть еще', 'callback_data' => 'RockPaperScissors_restart'],
            ['text' => 'Завершить игру', 'callback_data' => 'character']
        ]);
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendMessage([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text' => $text,
            'reply_markup' => $keyboard,
        ]);
    }

    protected function updateCharacterParam($characterId, $win, $param): void {
        $character = $this->characterModel->find($characterId);

        if (!$param) {
            return;
        }

        $newParamValue = $win ? ($character[$param] + 0.01) : max(0, $character[$param] - 0.01);

        $this->characterModel->update($characterId, [$param => $newParamValue]);
    }
}
