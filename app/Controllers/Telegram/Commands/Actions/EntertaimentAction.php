<?php

namespace App\Controllers\Telegram\Commands\Actions;

use App\Services\Telegram\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\GameSettings\GameSettingsReaderTrait;

class EntertaimentAction extends BaseAction
{
    use GameSettingsReaderTrait;

    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();

        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => 'Пользователь не найден в базе данных или персонаж не определён.',
            ]);
        }

        $text = "🎮 *Развлечения*\n\n"
            . "Перед вами мир азарта, везения и разочарования. \nВо что желаете поиграть сегодня❓\n\n"
            . "Выберите тип игры, чтобы развеяться и приятно провести время 👇\n";

        $rows = [
            [
                ['text' => '🛞 Колесо фортуны', 'callback_data' => 'WheelOfFortune'],
                ['text' => '🎲 Угадай число', 'callback_data' => 'GuessNumber']
            ],
            [
                ['text' => '✂️ Камень-ножницы-бумага', 'callback_data' => 'RockPaperScissors'],
                ['text' => '🔀 Перемешать ресурсы', 'callback_data' => 'ShuffleResources'],
            ],
        ];

        // ADR-133 — «🎲 Оракул острова»: кнопка видна ТОЛЬКО при killswitch oracle.enabled
        // (dormant → скрыта, live-vs-dormant honesty). Для < min_level OracleAction отдаёт
        // lock-экран с объяснением (UX-discoverability).
        if ($this->gsBool('oracle.enabled', false)) {
            $rows[] = [
                ['text' => '🔮 Оракул острова', 'callback_data' => 'oracle'],
            ];
        }

        // ADR-135 Ф3b — «🎯 Доска розыска»: кнопка видна ТОЛЬКО когда включены И подать,
        // И bounty-слой (dormant → скрыта, live-vs-dormant honesty).
        if ($this->gsBool('tribute.enabled', false) && $this->gsBool('tribute.bounty_enabled', false)) {
            $rows[] = [
                ['text' => '🎯 Доска розыска', 'callback_data' => 'bountyBoard'],
            ];
        }

        $rows[] = [
            ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
        ];

        $keyboard = ['inline_keyboard' => $rows];

        $imagePath = base_url('uploads/telegram/fun_games.png'); // Укажите актуальный путь к изображению
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        // #12 edit-in-place (ADR-018): меню развлечений — навигация → редактируем
        // текущее сообщение. editOrSend при любой ошибке edit упадёт обратно на новое.
        return \App\Services\Notifications\MediaSender::editOrSend($this->navTarget() + [
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
