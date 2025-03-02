<?php

namespace App\Controllers\Telegram\Commands\Actions\Poll;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\PollModel;
use App\Models\PollAnswerModel;
use App\Models\PollVoteModel;
use App\Models\TelegramUserModel;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\ServerResponse;

class PollVoteAction extends BaseAction
{
    protected $pollModel;
    protected $pollAnswerModel;
    protected $pollVoteModel;
    protected $telegramUserModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);

        $this->pollModel = new PollModel();
        $this->pollAnswerModel = new PollAnswerModel();
        $this->pollVoteModel = new PollVoteModel();
        $this->telegramUserModel = new TelegramUserModel();
    }

    public function handle(): ServerResponse
    {
        $data = $this->callbackQuery->getData();
        $parts = explode('_', $data);
        if (count($parts) < 3) {
            return Request::emptyResponse();
        }
        $pollId   = (int)$parts[1];
        $answerId = (int)$parts[2];
        return $this->handleVote($pollId, $answerId);
    }

    /**
     * Обработка голоса: проверяем, записываем, показываем статистику.
     *
     * @param int $pollId
     * @param int $answerId
     * @return ServerResponse
     */
    public function handleVote(int $pollId, int $answerId): ServerResponse
    {
        $callbackQuery = $this->callbackQuery;
        $fromUser = $callbackQuery->getFrom();
        $chatId = $callbackQuery->getMessage()->getChat()->getId();

        // 1) Находим пользователя
        $userRow = $this->telegramUserModel->where('telegram_id', $fromUser->getId())->first();
        if (!$userRow) {
            Request::answerCallbackQuery([
                'callback_query_id' => $callbackQuery->getId(),
                'text' => 'Пользователь не найден в базе. Попробуй /start.',
                'show_alert' => true,
            ]);
            return Request::emptyResponse();
        }

        // 2) Ищем опрос и ответ
        $poll = $this->pollModel->find($pollId);
        if (!$poll) {
            Request::answerCallbackQuery([
                'callback_query_id' => $callbackQuery->getId(),
                'text' => 'Опрос не найден.',
                'show_alert' => true,
            ]);
            return Request::emptyResponse();
        }
        if ((int)$poll['active'] !== 1) {
            Request::answerCallbackQuery([
                'callback_query_id' => $callbackQuery->getId(),
                'text' => 'Опрос не активен или уже закрыт.',
                'show_alert' => true,
            ]);
            return Request::emptyResponse();
        }

        $answer = $this->pollAnswerModel->find($answerId);
        if (!$answer || (int)$answer['poll_id'] !== $pollId) {
            Request::answerCallbackQuery([
                'callback_query_id' => $callbackQuery->getId(),
                'text' => 'Ответ не найден или не принадлежит этому опросу.',
                'show_alert' => true,
            ]);
            return Request::emptyResponse();
        }

        // 3) Проверяем, голосовал ли уже
        $existingVote = $this->pollVoteModel
            ->where('poll_id', $pollId)
            ->where('telegram_user_id', $userRow['id'])
            ->first();

        if ($existingVote) {
            Request::answerCallbackQuery([
                'callback_query_id' => $callbackQuery->getId(),
                'text' => 'Вы уже голосовали в этом опросе.',
                'show_alert' => true,
            ]);
            return Request::emptyResponse();
        }

        // 4) Записываем голос
        $this->pollVoteModel->insert([
            'poll_id' => $pollId,
            'telegram_user_id' => $userRow['id'],
            'answer_id' => $answerId,
            'voted_at' => date('Y-m-d H:i:s'),
        ]);

        // 5) Считаем статистику и формируем сообщение
        $stats = $this->pollVoteModel->getStatistics($pollId);
        $totalVotes = 0;
        $votesByAnswerId = [];
        foreach ($stats as $statRow) {
            $totalVotes += $statRow['votes'];
            $votesByAnswerId[$statRow['answer_text']] = $statRow['votes'];
        }

        $currentAnswerText = $answer['answer_text'];

        $allAnswers = $this->pollAnswerModel->where('poll_id', $pollId)->findAll();
        $statsText = "";
        foreach ($allAnswers as $ansRow) {
            $ansText = $ansRow['answer_text'];
            $count = isset($votesByAnswerId[$ansText]) ? $votesByAnswerId[$ansText] : 0;
            $percent = ($totalVotes > 0) ? round(($count / $totalVotes) * 100) : 0;
            $statsText .= "• <b>{$ansText}</b>: {$count} голос(ов) ({$percent}%)\n";
        }

        $msg = "Ваш голос учтён! Вы выбрали: <b>{$currentAnswerText}</b>\n\n";
        $msg .= "Текущие результаты (всего {$totalVotes} голосов):\n";
        $msg .= $statsText;

        Request::answerCallbackQuery(['callback_query_id' => $callbackQuery->getId()]);
        return Request::sendMessage([
            'chat_id' => $chatId,
            'text' => $msg,
            'parse_mode' => 'HTML',
        ]);
    }
}
