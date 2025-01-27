<?php

namespace app\Services\Tasks;

use App\Models\CharacterTaskModel;
use App\Models\TaskModel;
use CodeIgniter\I18n\Time;

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
        // Выбираем поля из character_tasks + поля из tasks
        // например, name_rus, min_duration, max_duration и т. д.
        // Ниже — упрощённый вариант:
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
        // и пишем в массив
        $now = Time::now();

        foreach ($results as &$row) {
            if (!empty($row['end_time'])) {
                $diffSec = strtotime($row['end_time']) - $now->getTimestamp();
                if ($diffSec < 0) {
                    $diffSec = 0; // На всякий случай, если просрочено
                }
                // Переведём в часы/минуты (упрощённо)
                $hours = intdiv($diffSec, 3600);
                $minutes = intdiv($diffSec % 3600, 60);

                $row['time_left_str'] = "{$hours} чс. {$minutes} мин.";
            } else {
                $row['time_left_str'] = "Неизвестно";
            }
        }

        return $results;
    }
}