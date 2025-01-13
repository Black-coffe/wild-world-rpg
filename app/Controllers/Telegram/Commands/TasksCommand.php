<?php
namespace App\Controllers\Telegram\Commands;

use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use App\Models\CharacterModel;
use App\Models\TelegramUserModel;
use App\Models\CharacterTaskModel;
use App\Models\TaskModel;
use CodeIgniter\I18n\Time;

class TasksCommand extends UserCommand
{
    protected $name = 'tasks';
    protected $description = 'Displays a list of all active tasks for your character.';
    protected $usage = '/tasks';
    protected $version = '1.0';

    public function execute(): ServerResponse
    {
        $message = $this->getMessage();
        $chatId = $message->getChat()->getId();
        $userId = $message->getFrom()->getId();

        $telegramUserModel  = new TelegramUserModel();
        $telegramUser = $telegramUserModel->where('telegram_id', $userId)->first();

        $characterModel = new CharacterModel();
        $character = $characterModel->where('telegram_user_id', $telegramUser['id'])->first();

        if (!$character) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Персонаж не найден. Пожалуйста, используйте /start для создания игрового персонажа.',
                'parse_mode' => 'Markdown',
            ]);
        }

        $characterTaskModel = new CharacterTaskModel();
        $taskModel = new TaskModel();

        $tasks = $characterTaskModel->where('character_id', $character['id'])
            ->where('status', 'in_work')
            ->findAll();

        $text = "\n*Активные задачи:*\n\n";

        foreach ($tasks as $task) {
            $taskInfo = $taskModel->find($task['task_id']);
            $endTime = Time::parse($task['end_time']);
            $nowTime = Time::now();

            // Расчет разницы во времени
            $diff = $endTime->getTimestamp() - $nowTime->getTimestamp();

            if ($diff > 0) {
                // Переводим разницу в секундах в часы, минуты
                $hours = floor($diff / 3600);
                $mins = floor(($diff - ($hours * 3600)) / 60);

                $timeLeft = "{$hours} чс. {$mins} мин.";
            } else {
                $timeLeft = "задача завершена";
            }

            $text .= "📌 *{$taskInfo['name_rus']}*\n";
//            $text .= "_{$taskInfo['description']}_\n";
            $text .= "⏳ *Времени осталось:* $timeLeft\n\n";
        }

        if (empty($tasks)) {
            $text = "Сейчас у вас нет никаких активных задач.";
        }

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🏁 Завершить все задачи', 'callback_data' => 'finishAllTasks']
                ]
            ]
        ];

        return Request::sendMessage([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
