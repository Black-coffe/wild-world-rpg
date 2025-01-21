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
        $chatId  = $message->getChat()->getId();
        $userId  = $message->getFrom()->getId();

        // 1) Находим TelegramUser и Character
        $telegramUserModel = new TelegramUserModel();
        $telegramUser      = $telegramUserModel->where('telegram_id', $userId)->first();

        if (!$telegramUser) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Telegram-пользователь не найден. Используйте /start для регистрации.',
            ]);
        }

        $characterModel = new CharacterModel();
        $character      = $characterModel->where('telegram_user_id', $telegramUser['id'])->first();

        if (!$character) {
            return Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => 'Персонаж не найден. Используйте /start для создания персонажа.',
                'parse_mode' => 'Markdown',
            ]);
        }

        // 2) Загружаем активные задачи
        $characterTaskModel = new CharacterTaskModel();
        $taskModel          = new TaskModel();

        // Получаем все in_work задачи
        $tasks = $characterTaskModel
            ->where('character_id', $character['id'])
            ->where('status', 'in_work')
            ->findAll();

        // Если нет задач
        if (empty($tasks)) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => "Сейчас у вас нет никаких активных задач.",
            ]);
        }

        // 3) Лимитируем: максимум 20 задач
        $maxTasks = 20;
        if (count($tasks) > $maxTasks) {
            $tasks = array_slice($tasks, 0, $maxTasks);
        }

        // 4) Формируем текст
        $text = "*Активные задачи*\n\n";
        $keyboardButtons = [];
        $i = 1;

        foreach ($tasks as $taskRow) {
            $taskInfo = $taskModel->find($taskRow['task_id']);
            if (!$taskInfo) {
                continue;
            }

            // Считаем остаток времени
            $endTime = Time::parse($taskRow['end_time']);
            $nowTime = Time::now();
            $diffSec = $endTime->getTimestamp() - $nowTime->getTimestamp();

            if ($diffSec <= 0) {
                // Значит формально время вышло, но статус всё ещё in_work
                $timeLeft = "До завершения остались секунды";
            } else {
                // diffSec > 0
                if ($diffSec < 60) {
                    // Меньше минуты
                    $timeLeft = "меньше минуты";
                } else {
                    // Считаем часы и минуты
                    $hours = floor($diffSec / 3600);
                    $mins  = floor(($diffSec % 3600) / 60);
                    $timeLeft = "{$hours} чс. {$mins} мин.";
                }
            }

            $text .= "{$i}) *{$taskInfo['name_rus']}*\n";
            $text .= "   Осталось: `$timeLeft`\n\n";

            // Формируем кнопку
            // IMPORTANT: используем finishAllTasks_ для совместимости с FinishTaskAction
            $keyboardButtons[] = [
                'text' => (string)$i,
                'callback_data' => 'finishAllTasks_' . $taskRow['id']
            ];

            $i++;
        }

        // Дополнительная приписка
        $text .= "---------------------------------\n";
        $text .= "*Нажмите на соответствующую цифру, чтобы моментально снять задачу!*\n\n";

        // 5) Разбиваем кнопки на строки
        // Пример: по 5 кнопок в строке
        $chunkSize = 5;
        $inlineRows = array_chunk($keyboardButtons, $chunkSize);

        // 6) Собираем клавиатуру
        $keyboard = [
            'inline_keyboard' => $inlineRows
        ];

        // Отправляем
        return Request::sendMessage([
            'chat_id' => $chatId,
            'text'    => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
