<?php

namespace App\Services\Tasks;

use App\Models\CharacterTaskModel;
use App\Models\TaskModel;
use App\Services\Db\ConditionalWriteService;
use App\Services\Db\WriteOutcome;
use CodeIgniter\I18n\Time;
use App\Services\Telegram\Request;

/**
 * Сервис для работы с активными задачами персонажа.
 */
class ActiveTasksService
{
    protected $characterTaskModel;
    protected $taskModel;
    private ?ConditionalWriteService $writer = null;

    public function __construct()
    {
        $this->characterTaskModel = new CharacterTaskModel();
        $this->taskModel          = new TaskModel();
    }

    private function writer(): ConditionalWriteService
    {
        return $this->writer ??= new ConditionalWriteService();
    }

    /**
     * exploit-fix-09 (ADR-181 §5) — единая точка вставки строки `character_tasks`
     * для старта эксклюзивной 🔒-задачи (второй из двух путей, которым контракт
     * обязателен уже сейчас — первый см. `GenericQuestStartAction`). Вставка идёт
     * через `insertUnique()`, а не голый `Model::insert()`: до появления
     * `UNIQUE`-индекса (story 10) он просто вставляет и отдаёт `Applied`, после —
     * гасит гонку двух почти одновременных стартов в `Refused`, не в исключение.
     *
     * exploit-fix-14 — `insertUnique()` бьёт сырым SQL мимо модели, поэтому
     * `created_at`/`updated_at` (раньше их подставляла `CharacterTaskModel::$useTimestamps`)
     * подставляются здесь, в единственной точке (Non-goals story 14 — не в каждом
     * вызывающем): на проде обе колонки `DATETIME NOT NULL` без DEFAULT (замер
     * 03.09.2026), без них вставка ловит 1364 под `STRICT_TRANS_TABLES`. Вызывающий
     * может передать свои значения — они не перезаписываются.
     *
     * @param array<string,mixed> $row строка `character_tasks` (тот же набор
     *                                 полей, что раньше шёл в `Model::insert()`)
     */
    public function insertExclusiveTaskRow(array $row): WriteOutcome
    {
        $now = Time::now()->toDateTimeString();
        $row += ['created_at' => $now, 'updated_at' => $now];

        return $this->writer()->insertUnique('character_tasks', $row);
    }

    /**
     * Единый текст отказа при повторном (дублирующем) старте эксклюзивной
     * задачи — «уже начато», а не 500 (ADR-181 §5, инвариант 8): 500 на
     * вебхуке заставляет Telegram переслать апдейт и произвести следующий дубль.
     */
    public function alreadyStartedExclusiveTaskText(): string
    {
        return 'Это дело уже начато — проверь *«🚀 Активные задачи»*.';
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
     * ADR-167 — активная задача, которая занимает персонажа целиком
     * (`tasks.parallel_execution_allowed = 0`), либо null.
     *
     * Единая точка правды о «я сейчас занят». До ADR-167 этот запрос жил только
     * внутри BaseAction::checkParallelExecutionAllowed и потому применялся лишь
     * к добыче/движению/Походу; старты крафта и ремонта его не звали и стартовали
     * поверх занятого персонажа.
     *
     * Свежая модель на вызов — CI4-builder накапливает where-состояние между
     * вызовами внутри одного запроса (урок feedback_ci4_model_builder_state_quirk).
     *
     * @param int $ignoreTaskId `tasks.id`, который не считается помехой самому себе.
     *                          Нужен очереди крафта: повторный запуск ТОГО ЖЕ рецепта
     *                          не запускает второе дело, а становится в очередь
     *                          (status='queued', end_time=NULL) и ждёт своей смены —
     *                          инвариант «одновременно идёт одно 🔒» при этом цел.
     * @return array{charTaskId:int, name:string, name_rus:string, end_time:?string, minutes_left:int}|null
     */
    public function findBlockingTask(int $characterId, int $ignoreTaskId = 0): ?array
    {
        $builder = (new CharacterTaskModel())->builder()
            ->select('character_tasks.id AS charTaskId, character_tasks.end_time, tasks.name, tasks.name_rus')
            ->join('tasks', 'tasks.id = character_tasks.task_id', 'inner')
            ->where('character_tasks.character_id', $characterId)
            ->where('character_tasks.status', 'in_work')
            ->where('tasks.parallel_execution_allowed', 0);

        if ($ignoreTaskId > 0) {
            $builder->where('character_tasks.task_id !=', $ignoreTaskId);
        }

        // Раньше всех освободится — его и показываем игроку как «осталось».
        $result = $builder->orderBy('character_tasks.end_time', 'ASC')->get();
        if ($result === false) {
            return null;
        }

        $row = $result->getRowArray();

        if (! is_array($row)) {
            return null;
        }

        $endTime  = isset($row['end_time']) && is_string($row['end_time']) && $row['end_time'] !== ''
            ? $row['end_time']
            : null;
        $leftSec  = $endTime !== null ? strtotime($endTime) - Time::now()->getTimestamp() : 0;
        $nameRus  = is_string($row['name_rus'] ?? null) && $row['name_rus'] !== '' ? $row['name_rus'] : 'Текущее дело';

        return [
            'charTaskId'   => (int) $row['charTaskId'],
            'name'         => is_string($row['name'] ?? null) ? $row['name'] : '',
            'name_rus'     => $nameRus,
            'end_time'     => $endTime,
            'minutes_left' => $leftSec > 0 ? (int) ceil($leftSec / 60) : 0,
        ];
    }

    /**
     * ADR-167 — единое правило «🔒 поверх 🔒 не начинается» + готовый текст отказа.
     *
     * Одна реализация на всех, потому что точек старта 🔒-задач четыре и они в
     * разных слоях: крафт и ремонт (action-handler'ы), полный переезд (валидатор-
     * сервис), полевые действия (свой давний гейт в BaseAction). Разъехавшиеся
     * копии этого правила и были исходной причиной жалобы.
     *
     * @param mixed  $startingParallelFlag `tasks.parallel_execution_allowed` стартующей задачи
     * @param string $attemptedNameRus     что игрок пытается начать
     * @param int    $ignoreTaskId         своя же задача (очередь крафта) — см. findBlockingTask()
     * @return string|null текст отказа (Markdown), либо null если начинать можно
     */
    public function exclusiveConflict(int $characterId, mixed $startingParallelFlag, string $attemptedNameRus = '', int $ignoreTaskId = 0): ?string
    {
        $scope = new ActionScopeService();

        // Фоновое дело (⏳) начинать можно всегда — бейдж это прямо обещает.
        if ($scope->isBackground($startingParallelFlag)) {
            return null;
        }

        if (! $this->exclusiveLockEnabled()) {
            return null;
        }

        $blocking = $this->findBlockingTask($characterId, $ignoreTaskId);
        if ($blocking === null) {
            return null;
        }

        return $scope->exclusiveBlockText(
            $blocking['name_rus'],
            $blocking['minutes_left'],
            $attemptedNameRus,
        );
    }

    /**
     * Killswitch ADR-167 (`craft.exclusive_lock.enabled`, категория «Крафт»).
     * Выключение возвращает ровно доправочное поведение — 🔒-дело снова стартует
     * поверх занятого персонажа. Живёт в админке, чтобы откат не требовал деплоя.
     */
    public function exclusiveLockEnabled(): bool
    {
        $raw = (new \App\Services\GameSettings\GameSettingsService())->get('craft.exclusive_lock.enabled', true);

        if (is_bool($raw)) {
            return $raw;
        }
        if (is_numeric($raw)) {
            return (int) $raw === 1;
        }

        return $raw === 'true';
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
