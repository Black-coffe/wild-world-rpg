<?php

namespace App\Controllers\Telegram\Commands\Actions\Games;

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Entities\InlineKeyboard;
use App\Models\CharacterModel;
use App\Services\GameSettings\GameSettingsReaderTrait;
use App\Controllers\Telegram\Commands\Actions\BaseAction;

/**
 * 🎲 Угадай число — честная мини-игра на золото (N5, ADR-040).
 *
 * Шанс ЧЕСТНЫЙ 1-из-N: показываем N чисел, секрет = rand(1,N), победа если выбор совпал
 * (P=1/N by construction — соврать о шансе невозможно). Весь баланс live-tunable через
 * GameSettings (economy.guess.*): killswitch, гейт золота, options_count (=шанс), payout,
 * ставки. Caption печатает реальный шанс/множитель → текст не расходится с механикой.
 * Азартная игра НЕ трогает навыки (ADR-040 решение 3).
 */
class GuessNumberAction extends BaseAction {
    use GameSettingsReaderTrait;

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

        if (!$this->gsBool('economy.guess.enabled', true)) {
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text' => 'Игра «Угадай число» временно недоступна.',
            ]);
        }

        $minGold = $this->gsInt('economy.guess.min_gold_to_play', 50);
        if ((int) ($this->character['gold'] ?? 0) < $minGold) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text' => "К сожалению, у вас недостаточно золотых монет для игры! Необходимо минимум {$minGold} золотых.",
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
            // Непредвиденный запрос (в т.ч. restart)
            return $this->showBetOptions();
        }
    }

    protected function showBetOptions(): ServerResponse {
        $count   = $this->gsInt('economy.guess.options_count', 3);
        $mult    = $this->gsFloat('economy.guess.payout_multiplier', 1.5);
        $pct     = (int) round(100 / max(1, $count));
        $multStr = $this->formatNumber($mult);

        $text = "*Готов рискнуть?* 🎲\n\n"
            . "*Испытай удачу в «Угадай число»!*\n\n"
            . "*💰 Условия:*\n\n"
            . "Я загадаю одно из *{$count}* чисел 🔢\n"
            . "Угадаешь — заберёшь выигрыш *×{$multStr} от ставки*! 🤑\n"
            . "Шанс честный: *1 из {$count}* (~{$pct}%).\n"
            . "Промахнёшься — *потеряешь ставку!* 😥\n\n"
            . "*⬇️ Выбери ставку:*\n";

        $buttons = [];
        foreach ($this->betOptions() as $bet) {
            $buttons[] = ['text' => (string) $bet, 'callback_data' => 'GuessNumber_bet_' . $bet];
        }
        $keyboard = new InlineKeyboard($buttons);

        $imagePath = base_url('uploads/telegram/guess_the_number.png');
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
        $betAmount = (int) ($params[2] ?? 0);
        $chatId    = $this->callbackQuery->getMessage()->getChat()->getId();

        if (!in_array($betAmount, $this->betOptions(), true)) {
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return $this->showBetOptions();
        }

        // Per-bet affordability: нельзя поставить больше, чем есть.
        if ((int) ($this->character['gold'] ?? 0) < $betAmount) {
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => "У вас недостаточно золота для ставки {$betAmount}.",
            ]);
        }

        $count = $this->gsInt('economy.guess.options_count', 3);
        $mult  = $this->formatNumber($this->gsFloat('economy.guess.payout_multiplier', 1.5));

        // N случайных различных чисел для антуража (вероятность всё равно честные 1/N).
        $numbers = range(1, 99);
        shuffle($numbers);
        $shown = array_slice($numbers, 0, $count);

        $text = "Я загадал и запомнил одно из этих *{$count}* чисел.\n"
            . "Угадаешь — выигрыш *×{$mult}* от ставки ({$betAmount}); нет — теряешь ставку.";

        $buttons = [];
        foreach ($shown as $i => $num) {
            $buttons[] = ['text' => (string) $num, 'callback_data' => 'GuessNumber_choose_' . $betAmount . '_' . ($i + 1)];
        }
        $keyboard = new InlineKeyboard($buttons);

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendMessage([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    public function handleNumberChoice($params): ServerResponse {
        $betAmount    = (int) ($params[2] ?? 0);
        $chosenNumber = (int) ($params[3] ?? 0);
        $chatId       = $this->callbackQuery->getMessage()->getChat()->getId();

        // Re-verify ставку и платёжеспособность (золото могло измениться).
        if (!in_array($betAmount, $this->betOptions(), true)
            || (int) ($this->character['gold'] ?? 0) < $betAmount) {
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => "Ставка {$betAmount} недоступна (недостаточно золота).",
            ]);
        }

        $count = $this->gsInt('economy.guess.options_count', 3);
        $mult  = $this->gsFloat('economy.guess.payout_multiplier', 1.5);

        // Честный 1-из-N: секрет равновероятен среди N показанных позиций.
        $secret = rand(1, max(1, $count));

        $charId = is_numeric($this->character['id'] ?? null) ? (int) $this->character['id'] : null;
        if ($chosenNumber === $secret) {
            $winGold = (int) round($betAmount * $mult);
            $this->applyGold($this->character['id'], $winGold);
            $text = "Поздравляем! Вы угадали число. Ваш выигрыш: {$winGold} золота.";
            $this->logActivity($charId, 'GAMBLE_GUESS', "WIN bet={$betAmount} gold=+{$winGold}");
        } else {
            $this->applyGold($this->character['id'], -$betAmount);
            $text = "К сожалению, вы не угадали. Вы потеряли: {$betAmount} золота.";
            $this->logActivity($charId, 'GAMBLE_GUESS', "LOSE bet={$betAmount} gold=-{$betAmount}");
        }

        $keyboard = new InlineKeyboard([
            ['text' => 'Сыграть еще', 'callback_data' => 'GuessNumber_restart'],
            ['text' => 'Персонаж', 'callback_data' => 'character_info']
        ]);
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendMessage([
            'chat_id' => $chatId,
            'text' => $text,
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Применяет дельту золота (>0 выигрыш, <0 проигрыш). Без изменения навыков.
     *
     * @param mixed $characterId
     */
    protected function applyGold($characterId, int $delta): void {
        $character = $this->characterModel->find($characterId);
        if (!$character) {
            return;
        }
        $newGold = max(0, (int) ($character['gold'] ?? 0) + $delta);
        $this->characterModel->update($characterId, ['gold' => $newGold]);
    }

    /**
     * @return list<int>
     */
    protected function betOptions(): array {
        $raw  = $this->gsString('economy.guess.bet_options', '5,10,25,50');
        $bets = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if ($part !== '' && ctype_digit($part)) {
                $bets[] = (int) $part;
            }
        }
        return $bets !== [] ? $bets : [5, 10, 25, 50];
    }

    protected function formatNumber(float $value): string {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
