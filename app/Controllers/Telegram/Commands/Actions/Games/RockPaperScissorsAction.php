<?php

namespace App\Controllers\Telegram\Commands\Actions\Games;

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Entities\InlineKeyboard;
use App\Models\CharacterModel;
use App\Services\GameSettings\GameSettingsReaderTrait;
use App\Controllers\Telegram\Commands\Actions\BaseAction;

/**
 * 🗿✂️📄 Камень-ножницы-бумага — ЧЕСТНАЯ игра на навык (N5, ADR-040).
 *
 * Скрытый 40%-cap убран: исход честный 33/33/33 (КНБ — общеизвестно честная игра, скрытая
 * подкрутка била по доверию). Дом-эдж перенесён в ПРОЗРАЧНУЮ асимметрию награды: победа
 * +win_gain к навыку, поражение −loss_penalty (loss_penalty > win_gain ⇒ честный sink),
 * ничья без изменений. Всё live-tunable через GameSettings (economy.rps.*), раскрыто в тексте.
 * Ставка КНБ — это сам навык (опыт/сила/ловкость/интеллект), поэтому навык-эффекты остаются.
 */
class RockPaperScissorsAction extends BaseAction {
    use GameSettingsReaderTrait;

    protected $characterModel;
    protected $character;
    protected $user;

    /** @var list<string> */
    private const ALLOWED_PARAMS = ['experience', 'strength', 'agility', 'intellect'];

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

        if (!$this->gsBool('economy.rps.enabled', true)) {
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text' => 'Игра «Камень-ножницы-бумага» временно недоступна.',
            ]);
        }

        $minSkill = $this->gsFloat('economy.rps.min_skill_to_play', 0.05);
        if ((float) ($this->character['experience'] ?? 0) < $minSkill
            || (float) ($this->character['strength'] ?? 0) < $minSkill
            || (float) ($this->character['agility'] ?? 0) < $minSkill
            || (float) ($this->character['intellect'] ?? 0) < $minSkill) {
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text' => 'К сожалению, у вас недостаточно необходимых навыков для этой игры!',
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
            // Непредвиденный запрос (в т.ч. restart)
            return $this->showOptions();
        }
    }

    protected function showOptions(): ServerResponse {
        $wg = $this->formatNumber($this->gsFloat('economy.rps.win_gain', 0.01));
        $lp = $this->formatNumber($this->gsFloat('economy.rps.loss_penalty', 0.02));

        $text = "*🗿✂️📄 Камень, Ножницы, Бумага*\n\n"
            . "_Честная игра: 1/3 победа, 1/3 ничья, 1/3 поражение._\n\n"
            . "Сыграй на выбор: *опыт, сила, ловкость или интеллект*.\n\n"
            . "🏆 Победа: *+{$wg}* к выбранному навыку\n"
            . "🤝 Ничья: без изменений\n"
            . "💀 Поражение: *−{$lp}* к навыку\n\n"
            . "*⬇️ Делай ставку:*\n";
        $keyboard = new InlineKeyboard([
            ['text' => '🌟 Опыт', 'callback_data' => 'RockPaperScissors_param_experience'],
            ['text' => '💪 Сила', 'callback_data' => 'RockPaperScissors_param_strength'],
            ['text' => '🤸‍♂️ Ловкость', 'callback_data' => 'RockPaperScissors_param_agility'],
            ['text' => '🧠 Интеллект', 'callback_data' => 'RockPaperScissors_param_intellect'],
        ]);

        $imagePath = base_url('uploads/telegram/stone_paper_scissors.png');
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
        $param = $params[2] ?? '';
        if (!in_array($param, self::ALLOWED_PARAMS, true)) {
            return $this->showOptions();
        }
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
        $param      = $params[2] ?? '';
        $userChoice = $params[3] ?? '';

        if (!in_array($param, self::ALLOWED_PARAMS, true)) {
            return $this->showOptions();
        }

        $choices = [
            'rock' => 'Камень',
            'scissors' => 'Ножницы',
            'paper' => 'Бумага'
        ];
        if (!isset($choices[$userChoice])) {
            return $this->showOptions();
        }

        // Компьютерный выбор – равновероятно. Исход ЧЕСТНЫЙ (без скрытого 40%-cap).
        $computerChoiceKey = array_rand($choices);
        $computerChoice = $choices[$computerChoiceKey];

        $winGain     = $this->gsFloat('economy.rps.win_gain', 0.01);
        $lossPenalty = $this->gsFloat('economy.rps.loss_penalty', 0.02);

        if ($userChoice === $computerChoiceKey) {
            $text = "Ничья! Вы оба выбрали {$choices[$userChoice]}.";
        } else {
            $winConditions = [
                'rock' => 'scissors',
                'scissors' => 'paper',
                'paper' => 'rock',
            ];

            if ($winConditions[$userChoice] === $computerChoiceKey) {
                $this->updateCharacterParam($this->character['id'], $winGain, $param);
                $text = "Вы победили! Ваш выбор ({$choices[$userChoice]}) побеждает {$computerChoice}.";
            } else {
                $this->updateCharacterParam($this->character['id'], -$lossPenalty, $param);
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

    /**
     * Применяет дельту навыка (>0 победа, <0 поражение). $param уже из whitelist.
     *
     * @param mixed $characterId
     */
    protected function updateCharacterParam($characterId, float $delta, string $param): void {
        $character = $this->characterModel->find($characterId);
        if (!$character || !in_array($param, self::ALLOWED_PARAMS, true)) {
            return;
        }

        $newParamValue = max(0, (float) ($character[$param] ?? 0) + $delta);
        $this->characterModel->update($characterId, [$param => $newParamValue]);
    }

    protected function formatNumber(float $value): string {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }
}
