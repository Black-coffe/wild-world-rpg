<?php

namespace App\Controllers\Telegram\Commands\Actions\Games;

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Entities\InlineKeyboard;
use App\Models\CharacterModel;
use App\Controllers\Telegram\Commands\Actions\BaseAction;

class GuessNumberAction extends BaseAction {
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
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text' => 'Пользователь не найден в базе данных или персонаж не определён.',
            ]);
        }

        if ($this->character['gold'] < 50) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text' => 'К сожалению, у вас недостаточно золотых монет для игры! Необходимо минимум 50 золотых.',
            ]);
        }

        $callbackData = $this->callbackQuery->getData();
        $params = explode('_', $callbackData);

        // Разбор логики в зависимости от параметров
        if (count($params) == 1) {
            // Если дополнительных параметров нет, показываем стартовое меню или экран
            return $this->showBetOptions();
        } elseif($params[1] === 'bet') {
            return $this->handleBetSelection($params);
        } elseif ($params[1] === 'choose') {
            // Обработка выбора числа
            return $this->handleNumberChoice($params);
        } else {
            // Непредвиденный запрос
            return $this->showBetOptions();
        }
    }

    protected function showBetOptions(): ServerResponse {
        $text = "*Готов рискнуть?* 🎲\n\n"
            . "*Испытай удачу в Угадай число!*\n\n"
            . "*💰 Условия:*\n\n"
            . "Ставь золото 🤑 (от 5 до 50)\n"
            . "Выбери 1 из 3 чисел 🔢\n"
            . "Угадай - забери *вдвое меньше выигрыша!* 🤑\n"
            . "Промахнёшься - *потеряешь ставку!* 😥\n\n"
            . "*Готов к азарту?*\n\n"
            . "*⬇️ Выбери ставку:*\n";
        $keyboard = new InlineKeyboard([
            ['text' => '5', 'callback_data' => 'GuessNumber_bet_5'],
            ['text' => '10', 'callback_data' => 'GuessNumber_bet_10'],
            ['text' => '25', 'callback_data' => 'GuessNumber_bet_25'],
            ['text' => '50', 'callback_data' => 'GuessNumber_bet_50'],
        ]);

        $imagePath = base_url('uploads/telegram/guess_the_number.png'); // Укажите актуальный путь к изображению
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return \App\Services\Notifications\MediaSender::sendPhotoOrText([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'   => Request::encodeFile($imagePath),
            'caption' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    public function handleBetSelection($params): ServerResponse {
        $betAmount = $params[2];
        $numbers = range(1, 99);
        shuffle($numbers);
        $text = "Я загадал и запомнил одно из этих трех чисел. Если ты угадаешь верно, получишь выигрыш согласно твоей ставке, но если не угадаешь — ставка списывается.";
        $keyboard = new InlineKeyboard([
            ['text' => $numbers[0], 'callback_data' => 'GuessNumber_choose_' . $betAmount . '_1'],
            ['text' => $numbers[1], 'callback_data' => 'GuessNumber_choose_' . $betAmount . '_2'],
            ['text' => $numbers[2], 'callback_data' => 'GuessNumber_choose_' . $betAmount . '_3'],
        ]);
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendMessage([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text' => $text,
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    public function handleNumberChoice($params): ServerResponse {
        $betAmount = $params[2];
        $chosenNumber = $params[3];
        $correctNumber = rand(1, 4);

        if ($betAmount === '25') {
            $correctNumber = rand(1, 5);
        } elseif ($betAmount === '50') {
            $correctNumber = rand(1, 7);
        }

        if ($chosenNumber == $correctNumber) {
            // Победа: теперь выигрыш составит 1.2 * ставка
            $winGold = intval(round($betAmount * 1.2));
            $this->updateCharacterGold($this->character['id'], $betAmount, true);
            $text = "Поздравляем! Вы угадали число. Ваш выигрыш: {$winGold} золота.";
        } else {
            // Проигрыш
            $this->updateCharacterGold($this->character['id'], $betAmount, false);
            $text = "К сожалению, вы не угадали. Вы потеряли: {$betAmount} золота.";
        }

        $keyboard = new InlineKeyboard([
            ['text' => 'Сыграть еще', 'callback_data' => 'GuessNumber_restart'],
            ['text' => 'Персонаж', 'callback_data' => 'character_info']
        ]);
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendMessage([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text' => $text,
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    protected function updateCharacterGold($characterId, $amount, $win): void {
        $character = $this->characterModel->find($characterId);
        $amount = intval($amount);

        if ($win) {
            // При выигрыше теперь прибавляем 1.2 * ставка, а прирост характеристик снижен
            $newGold = $character['gold'] + intval(round($amount * 1.2));
            $this->characterModel->update($characterId, [
                'gold' => $newGold,
                'experience' => $character['experience'] + 0.01,
                'agility' => $character['agility'] + 0.015,
                'intellect' => $character['intellect'] + 0.02,
            ]);
        } else {
            $newGold = max(0, $character['gold'] - $amount);
            $this->characterModel->update($characterId, [
                'gold' => $newGold,
                'experience' => $character['experience'] - 0.01,
                'agility' => $character['agility'] - 0.02,
                'intellect' => $character['intellect'] - 0.03,
            ]);
        }
    }
}
