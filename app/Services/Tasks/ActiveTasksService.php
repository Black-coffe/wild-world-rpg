<?php

namespace App\Services\Tasks;

use App\Models\CharacterTaskModel;
use App\Models\TaskModel;
use CodeIgniter\I18n\Time;
use Longman\TelegramBot\Request;

/**
 * Сервис для работы с активными задачами персонажа.
 */
class ActiveTasksService
{
    protected $characterTaskModel;
    protected $taskModel;

    public function __construct()
    {
        $this->characterTaskModel = new CharacterTaskModel();
        $this->taskModel          = new TaskModel();
    }

    /**
     * Возвращает список АКТИВНЫХ задач (status='in_work') данного персонажа
     * вместе с данными из таблицы `tasks` (например, name_rus).
     *
     * @param int $characterId
     * @return array
     */
    public function getActiveTasksWithDetails(int $characterId): array
    {
        $builder = $this->characterTaskModel->builder();
        $builder->select('
            character_tasks.id AS charTaskId,
            character_tasks.task_id,
            character_tasks.start_time,
            character_tasks.end_time,
            character_tasks.status,
            character_tasks.task_settings,
            tasks.name_rus,
            tasks.name
        ');
        $builder->join('tasks', 'tasks.id = character_tasks.task_id', 'left');
        $builder->where('character_tasks.character_id', $characterId);
        $builder->where('character_tasks.status', 'in_work');
        $results = $builder->get()->getResultArray();

        // Дополнительно считаем "осталось времени" (end_time - now)
        $now = Time::now();

        foreach ($results as &$row) {
            if (!empty($row['end_time'])) {
                $diffSec = strtotime($row['end_time']) - $now->getTimestamp();
                if ($diffSec < 0) {
                    $diffSec = 0;
                }
                $hours   = intdiv($diffSec, 3600);
                $minutes = intdiv($diffSec % 3600, 60);
                $row['time_left_str'] = "{$hours} чс. {$minutes} мин.";
            } else {
                $row['time_left_str'] = "Неизвестно";
            }
        }

        return $results;
    }

    /**
     * Быстрый метод, который проверяет, нет ли у игрока задачи "BaseRelocation".
     * Если есть, отправляет сообщение "Переезд активен" и возвращает true (блокируем).
     * Если нет ― возвращает false (можно продолжать).
     *
     * @param int    $characterId
     * @param string $callbackQueryId  ID колбэка для answerCallbackQuery (чтобы убрать "часики")
     * @param int    $chatId           Куда отправить сообщение
     * @return bool  true если переезд найден (и мы уже отправили блокирующее сообщение),
     *              false если переезда нет ― логика может продолжиться
     */
    public function checkRelocationAndBlock(int $characterId, string $callbackQueryId, int $chatId): bool
    {
        // Получаем все задачи 'in_work'
        $activeTasks = $this->getActiveTasksWithDetails($characterId);

        // Ищем BaseRelocation
        $hasRelocation = false;
        foreach ($activeTasks as $task) {
            if ($task['name'] === 'BaseRelocation') {
                $hasRelocation = true;
                break;
            }
        }

        if ($hasRelocation) {
            // Ответим callbackQuery
            Request::answerCallbackQuery(['callback_query_id' => $callbackQueryId]);

            // Отправим сообщение о блокировке
            Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => "Сейчас идёт *Планируемый переезд базы*.\nПока эта задача активна, это действие недоступно!",
                'parse_mode' => 'Markdown',
            ]);

            return true; // Действие заблокировано
        }

        return false; // Переезда нет, можно продолжать
    }
}
