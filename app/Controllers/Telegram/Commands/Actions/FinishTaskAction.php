<?php
namespace App\Controllers\Telegram\Commands\Actions;

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Entities\CallbackQuery;
use App\Models\CharacterModel;
use App\Models\TelegramUserModel;
use App\Models\CharacterTaskModel;
use App\Models\TaskModel;  // чтобы узнать name_rus

class FinishTaskAction
{
    protected $callbackQuery;
    protected $characterModel;
    protected $telegramUserModel;
    protected $characterTaskModel;
    protected $taskModel;

    public function __construct(CallbackQuery $callbackQuery)
    {
        $this->callbackQuery      = $callbackQuery;
        $this->characterModel     = new CharacterModel();
        $this->telegramUserModel  = new TelegramUserModel();
        $this->characterTaskModel = new CharacterTaskModel();
        $this->taskModel          = new TaskModel();
    }

    public function handle(): ServerResponse
    {
        $chatId         = $this->callbackQuery->getMessage()->getChat()->getId();
        $telegramUserId = $this->callbackQuery->getFrom()->getId();
        $callbackData   = $this->callbackQuery->getData(); // напр. "finishTask_12"

        // 1) Находим TelegramUser
        $telegramUser = $this->telegramUserModel->where('telegram_id', $telegramUserId)->first();
        if (!$telegramUser) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Пользователь не найден.',
            ]);
        }

        // 2) Персонаж
        $character = $this->characterModel->where('telegram_user_id', $telegramUser['id'])->first();
        if (!$character) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Персонаж не найден.',
            ]);
        }

        // 3) Парсим колбэк
        $parts = explode('_', $callbackData);
        if (count($parts) !== 2 || $parts[0] !== 'finishAllTasks') {
            return Request::answerCallbackQuery([
                'callback_query_id' => $this->callbackQuery->getId(),
                'text' => 'Непонятный формат команды.',
                'show_alert' => false,
            ]);
        }

        $taskId = (int) $parts[1];

        // 4) Ищем задачу
        $taskRow = $this->characterTaskModel
            ->where('id', $taskId)
            ->where('character_id', $character['id'])
            ->where('status', 'in_work')
            ->first();

        if (!$taskRow) {
            Request::answerCallbackQuery([
                'callback_query_id' => $this->callbackQuery->getId(),
                'text' => 'Задача не найдена или уже завершена.',
                'show_alert' => false,
            ]);
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Задача не найдена или уже завершена.'
            ]);
        }

        // Берём название задачи
        $taskInfo = $this->taskModel->find($taskRow['task_id']);
        $taskName = $taskInfo ? $taskInfo['name_rus'] : '???';

        // 5) Меняем статус
        $this->characterTaskModel->update($taskRow['id'], [
            'status' => 'interrupted'
        ]);

        // Допустим, при завершении задачи игрок теряет характеристики:
        // (как пример, можно скопировать из finishAllTasks)
        // Не забудьте прописать model->update($character['id'], [...])
        $lostExp       = 0.5;
        $lostHealth    = 1;
        $lostStrength  = 0.25;
        $lostAgility   = 0.5;
        $lostIntellect = 0.5;
        $lostTired     = 0.2;

        $newExperience = max(0.1, $character['experience'] - $lostExp);
        $newHealth     = max(0.1, $character['health'] - $lostHealth);
        $newStrength   = max(0.1, $character['strength'] - $lostStrength);
        $newAgility    = max(0.1, $character['agility'] - $lostAgility);
        $newIntellect  = max(0.1, $character['intellect'] - $lostIntellect);
        $newTired      = max(0.1, $character['tired'] - $lostTired);

        $this->characterModel->update($character['id'], [
            'experience' => $newExperience,
            'health'     => $newHealth,
            'strength'   => $newStrength,
            'agility'    => $newAgility,
            'intellect'  => $newIntellect,
            'tired'      => $newTired,
        ]);

        // 6) Ответ пользователю
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text' => 'Задача прервана.',
            'show_alert' => false,
        ]);

        $text = "Задача *«{$taskName}»* была прервана! \n\n"
            . "Вы потеряли немного в параметрах:\n"
            . "- 🌟 Опыт: -0,5\n"
            . "- 💖 Здоровье: -1\n"
            . "- 💪 Сила: -0,25\n"
            . "- 🤸‍♂️ Ловкость: -0,5\n"
            . "- 🧠 Интеллект: -0,5\n"
            . "- 🥱 Выносливость: -0,2\n\n"
            . "Думаю, это было продуманное и верное решение. Лучше потерять ресурсы, нежели потерять жизнь! 💡\n\n"
            . "Не падай духом! Выживание - это искусство принятия трудных решений и учеба на собственных ошибках. "
            . "Всякий опыт ценен, а каждая неудача приближает тебя к пониманию, как стать сильнее и мудрее. 💪📚\n\n"
            . "Вперед, к новым вызовам! С каждым днем ты становишься только лучше. 🚀🌈";

        return Request::sendMessage([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'Markdown'
        ]);
    }
}
