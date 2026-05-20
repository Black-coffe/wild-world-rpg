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
     * S27 — craft pipeline игрока: активные (in_work) + очередь (queued) крафты.
     *
     * Краф-задачи определяются по `tasks.type='craft'` (generic_craft + legacy
     * craft + repair). `queued`-статус ставит только craft (v0.51.129), end_time
     * у них NULL (активируются FIFO per-recipe при завершении предыдущего).
     *
     * @return array{active: list<array{charTaskId:int, name:string, qty:int, seconds_left:int}>, queued: list<array{charTaskId:int, name:string, qty:int}>}
     */
    public function getCraftQueue(int $characterId): array
    {
        $builder = $this->characterTaskModel->builder();
        $builder->select('
            character_tasks.id AS charTaskId,
            character_tasks.end_time,
            character_tasks.status,
            character_tasks.task_settings,
            tasks.name_rus
        ');
        $builder->join('tasks', 'tasks.id = character_tasks.task_id', 'left');
        $builder->where('character_tasks.character_id', $characterId);
        $builder->where('tasks.type', 'craft');
        $builder->whereIn('character_tasks.status', ['in_work', 'queued']);
        // 'in_work' < 'queued' лексикографически → активные первыми; затем по ETA, затем FIFO id.
        $builder->orderBy('character_tasks.status', 'ASC');
        $builder->orderBy('character_tasks.end_time', 'ASC');
        $builder->orderBy('character_tasks.id', 'ASC');
        $rows = $builder->get()->getResultArray();

        $now    = Time::now()->getTimestamp();
        $active = [];
        $queued = [];
        foreach ($rows as $r) {
            $qty = 1;
            $s   = json_decode((string) ($r['task_settings'] ?? '{}'), true);
            if (is_array($s) && isset($s['quantity']) && is_numeric($s['quantity'])) {
                $qty = max(1, (int) $s['quantity']);
            }
            $name = is_string($r['name_rus'] ?? null) && $r['name_rus'] !== '' ? $r['name_rus'] : 'Крафт';
            $id   = (int) $r['charTaskId'];

            if (($r['status'] ?? '') === 'in_work') {
                $left = !empty($r['end_time']) ? max(0, strtotime((string) $r['end_time']) - $now) : 0;
                $active[] = ['charTaskId' => $id, 'name' => $name, 'qty' => $qty, 'seconds_left' => $left];
            } else {
                $queued[] = ['charTaskId' => $id, 'name' => $name, 'qty' => $qty];
            }
        }

        return ['active' => $active, 'queued' => $queued];
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
