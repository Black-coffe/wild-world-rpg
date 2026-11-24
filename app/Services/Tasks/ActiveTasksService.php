<?php

namespace App\Services\Tasks;

use App\Models\CharacterTaskModel;
use App\Models\TaskModel;
use App\Services\Db\ConditionalWriteService;
use App\Services\Db\WriteOutcome;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\BaseResult;
use CodeIgniter\I18n\Time;
use Config\Database;
use App\Services\Telegram\Request;

/**
 * Сервис для работы с активными задачами персонажа.
 */
class ActiveTasksService
{
    protected $characterTaskModel;
    protected $taskModel;
    private ?ConditionalWriteService $writer = null;

    /** @var BaseConnection<object, object>|null */
    private ?BaseConnection $db;

    /** id строки после последнего `Applied` от insertExclusiveTaskRow() (C3), иначе 0. */
    private int $lastInsertedTaskId = 0;

    /**
     * @param BaseConnection<object, object>|null $db соединение для записей `character_tasks`
     *        (null — общее CI4). Отдельное нужно тесту гонки двух стартов.
     */
    public function __construct(?BaseConnection $db = null)
    {
        $this->characterTaskModel = new CharacterTaskModel();
        $this->taskModel          = new TaskModel();
        $this->db                 = $db;
    }

    private function writer(): ConditionalWriteService
    {
        return $this->writer ??= new ConditionalWriteService($this->db());
    }

    /** @return BaseConnection<object, object> */
    private function db(): BaseConnection
    {
        return $this->db ??= Database::connect();
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

        // s1-atomicity-01 (C2, A2): флаг 🔒 — копия `tasks.parallel_execution_allowed = 0` на момент
        // вставки; при выключенном killswitch ADR-167 строка пишется не-🔒 и слот не занимает.
        if (! array_key_exists('is_exclusive', $row)) {
            $row['is_exclusive'] = $this->taskTakesExclusiveSlot($row['task_id'] ?? null) ? 1 : 0;
        }

        $this->lastInsertedTaskId = 0;
        // Занятый слот (`UNIQUE uq_ct_exclusive_slot`) — дубль ключа, `insertUnique()` гасит его в `Refused`.
        $outcome = $this->writer()->insertUnique('character_tasks', $row);
        if ($outcome === WriteOutcome::Applied) {
            $this->lastInsertedTaskId = (int) $this->db()->insertID();
        }

        return $outcome;
    }

    /**
     * s1-atomicity-01 (C3) — id строки, вставленной последним `Applied` от
     * {@see insertExclusiveTaskRow()} этого сервиса; `0`, если последняя вставка не прошла.
     */
    public function lastInsertedTaskId(): int
    {
        return $this->lastInsertedTaskId;
    }

    /**
     * s1-atomicity-01 (C4) — перевод существующей строки (`paused`/`queued`) в `in_work` одной
     * инструкцией `UPDATE IGNORE`: занятый 🔒-слот (`UNIQUE uq_ct_exclusive_slot`) — пропущенная
     * строка с предупреждением, а не ошибка запроса, поэтому `transStatus` открытой транзакции
     * вызывающего не портится и исключения нет.
     *
     * `Applied` — переведена; `Refused` — строка всё ещё в `$fromStatus` (слот занят);
     * `Missing` — строки в `$fromStatus` нет.
     *
     * @param array<string, scalar|null> $set дополнительные колонки (`start_time`, `end_time`, …)
     * @throws \InvalidArgumentException `status`/`exclusive_slot` или невалидное имя в `$set`,
     *                                   `$fromStatus = 'in_work'`
     */
    public function moveToInWork(int $charTaskId, string $fromStatus, array $set = []): WriteOutcome
    {
        if ($fromStatus === 'in_work') {
            throw new \InvalidArgumentException('moveToInWork: строка уже in_work — исход неотличим от отказа');
        }

        $assignments = ['`status` = ?'];
        $params      = ['in_work'];
        foreach ($set as $column => $value) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column) !== 1
                || in_array($column, ['status', 'exclusive_slot'], true)) {
                throw new \InvalidArgumentException("moveToInWork: недопустимая колонка в \$set: \"{$column}\"");
            }
            $assignments[] = "`{$column}` = ?";
            $params[]      = $value;
        }

        $db    = $this->db();
        $table = $db->prefixTable('character_tasks');
        $db->query(
            "UPDATE IGNORE {$table} SET " . implode(', ', $assignments) . ' WHERE id = ? AND status = ?',
            [...$params, $charTaskId, $fromStatus]
        );

        if ($db->affectedRows() > 0) {
            return WriteOutcome::Applied;
        }

        $still = $db->query("SELECT id FROM {$table} WHERE id = ? AND status = ? LIMIT 1", [$charTaskId, $fromStatus]);

        return $still instanceof BaseResult && $still->getRow() !== null ? WriteOutcome::Refused : WriteOutcome::Missing;
    }

    /**
     * s1-atomicity-01 (C5) — текст отказа ADR-167 для любого `Refused` из
     * {@see insertExclusiveTaskRow()} / {@see moveToInWork()} на игроцком пути: «уже занят …»
     * с именем текущего блокировщика; если он успел исчезнуть — «уже начато».
     */
    public function slotTakenText(int $characterId, string $attemptedNameRus = ''): string
    {
        $blocking = $this->findBlockingTask($characterId);
        if ($blocking === null) {
            return $this->alreadyStartedExclusiveTaskText();
        }

        return (new ActionScopeService())->exclusiveBlockText(
            $blocking['name_rus'],
            $blocking['minutes_left'],
            $attemptedNameRus,
        );
    }

    /** `tasks.parallel_execution_allowed = 0` у задачи строки и killswitch ADR-167 включён. */
    private function taskTakesExclusiveSlot(mixed $taskId): bool
    {
        if (! is_numeric($taskId)) {
            return false;
        }

        $db     = $this->db();
        $result = $db->query(
            'SELECT parallel_execution_allowed FROM ' . $db->prefixTable('tasks') . ' WHERE id = ? LIMIT 1',
            [(int) $taskId]
        );
        $task = $result instanceof BaseResult ? $result->getRowArray() : null;
        $flag = is_array($task) ? ($task['parallel_execution_allowed'] ?? null) : null;

        if (! is_numeric($flag) || (int) $flag !== 0) {
            return false;
        }

        return $this->exclusiveLockEnabled();
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
        // W2.N3-02 (ADR-190): список очереди живёт в ядре крафта; форма ответа — прежняя.
        $rows = (new \App\Services\Craft\CraftQueueService())->rows($characterId);

        return [
            'active' => array_map(
                static fn (array $a): array => ['charTaskId' => $a['charTaskId'], 'name' => $a['name'], 'qty' => $a['qty'], 'seconds_left' => $a['seconds_left']],
                $rows['active']
            ),
            'queued' => array_map(
                static fn (array $q): array => ['charTaskId' => $q['charTaskId'], 'name' => $q['name'], 'qty' => $q['qty']],
                $rows['queued']
            ),
        ];
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

    /** Текст отказа во время переезда базы — один для бота и ядра стройки/апгрейда (Markdown бота). */
    public const TEXT_RELOCATION = "Сейчас идёт *Планируемый переезд базы*.
Пока эта задача активна, это действие недоступно!";

    /**
     * w2-n4-tails-01 (ADR-190): идёт ли у персонажа переезд базы (`BaseRelocation` в работе). Без Telegram —
     * её зовут ядра стройки и апгрейда, поэтому отказ получают оба клиента.
     */
    public function hasActiveRelocation(int $characterId): bool
    {
        return $this->characterTaskModel->builder()
            ->join('tasks', 'tasks.id = character_tasks.task_id')
            ->where('character_tasks.character_id', $characterId)
            ->where('character_tasks.status', 'in_work')
            ->where('tasks.name', 'BaseRelocation')
            ->countAllResults() > 0;
    }

    /**
     * Если идёт переезд базы ({@see hasActiveRelocation()}), отвечает на колбэк, шлёт текст отказа и
     * возвращает true (действие заблокировано); иначе false — логика может продолжиться.
     *
     * @param string $callbackQueryId  ID колбэка для answerCallbackQuery (чтобы убрать "часики")
     * @param int    $chatId           Куда отправить сообщение
     */
    public function checkRelocationAndBlock(int $characterId, string $callbackQueryId, int $chatId): bool
    {
        if (! $this->hasActiveRelocation($characterId)) {
            return false;
        }

        Request::answerCallbackQuery(['callback_query_id' => $callbackQueryId]);
        Request::sendMessage([
            'chat_id'    => $chatId,
            'text'       => self::TEXT_RELOCATION,
            'parse_mode' => 'Markdown',
        ]);

        return true;
    }
}
