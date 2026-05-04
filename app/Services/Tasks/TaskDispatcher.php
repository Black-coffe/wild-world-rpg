<?php

namespace App\Services\Tasks;

use App\Models\TaskModel;
use App\TaskHandlers\Contracts\TaskHandlerInterface;
use Throwable;

/**
 * F2.4 — DI-контейнер для task-handler'ов.
 *
 * Зачем:
 *   `Worker.php::handleTask` сейчас вызывает `getHandlerClassName()` →
 *   `class_exists()` → `new $className()` → `method_exists('handle')` →
 *   `$handler->handle($task)`. Это reflection-based, без типобезопасности,
 *   с маппингом на 60+ строк (`taskHandlerMap`).
 *
 *   После того как все handler'ы перейдут на `TaskHandlerInterface`
 *   (F2.9), этот dispatcher заменит reflection-логику на простой lookup.
 *
 * Использование (после F2.9 миграции всех handler'ов):
 *   $dispatcher = service('taskDispatcher');
 *   $dispatcher->dispatch($task);
 *
 * Регистрация мапы — через `app/Config/TaskHandlerMap.php`-конфиг
 * (создадим при ребилде; пока маппинг отдельный аргумент конструктора).
 *
 * Сейчас — этот класс закладывает инфраструктуру, но не интегрирован
 * с Worker.php. Подключение — отдельный коммит после F2.9 ребилда
 * хотя бы 5-10 handler'ов как proof-of-concept.
 */
class TaskDispatcher
{
    /**
     * @var array<string,class-string<TaskHandlerInterface>>
     *       Карта `tasks.name` → имя класса, реализующего TaskHandlerInterface.
     */
    private array $handlerMap;

    private TaskModel $taskModel;

    /**
     * @param array<string,class-string<TaskHandlerInterface>> $handlerMap
     */
    public function __construct(array $handlerMap = [], ?TaskModel $taskModel = null)
    {
        $this->handlerMap = $handlerMap;
        $this->taskModel  = $taskModel ?? new TaskModel();
    }

    /**
     * Запустить handler для конкретной строки `character_tasks`.
     *
     * Контракт:
     *   - На неизвестное имя задачи — log_message + return (не исключение).
     *   - На исключение handler'а — пробрасываем дальше (Worker сам
     *     решит откатить статус через свою try/catch обвязку, см.
     *     Worker.php F0.3).
     *
     * @param array<string,mixed> $task строка `character_tasks` (с join'ом
     *                                  на `tasks.name` если нужно).
     */
    public function dispatch(array $task): void
    {
        $taskName = $this->resolveTaskName($task);
        if ($taskName === null) {
            log_message('error', '[TaskDispatcher] не могу определить имя задачи для task-id=' . ($task['id'] ?? 'unknown'));
            return;
        }

        $handlerClass = $this->handlerMap[$taskName] ?? null;
        if ($handlerClass === null) {
            log_message('error', "[TaskDispatcher] нет маппинга для задачи '{$taskName}'");
            return;
        }

        if (!is_subclass_of($handlerClass, TaskHandlerInterface::class)) {
            log_message('error', "[TaskDispatcher] класс '{$handlerClass}' не реализует TaskHandlerInterface");
            return;
        }

        /** @var TaskHandlerInterface $handler */
        $handler = new $handlerClass();
        $handler->handle($task);
    }

    /**
     * Получить `tasks.name` для строки `character_tasks`.
     *
     * Если `$task` уже содержит `name` (после JOIN-а в Worker'е) —
     * используем его. Иначе делаем lookup в `tasks`.
     */
    private function resolveTaskName(array $task): ?string
    {
        if (!empty($task['name'])) {
            return (string) $task['name'];
        }
        if (empty($task['task_id'])) {
            return null;
        }
        // Используем findCached() из F1.7 если есть, иначе обычный find.
        if (method_exists($this->taskModel, 'findCached')) {
            $row = $this->taskModel->findCached((int) $task['task_id']);
        } else {
            $row = $this->taskModel->find((int) $task['task_id']);
        }
        return $row['name'] ?? null;
    }

    /**
     * Зарегистрировать handler динамически (для тестов / расширений).
     *
     * @param class-string<TaskHandlerInterface> $handlerClass
     */
    public function register(string $taskName, string $handlerClass): self
    {
        $this->handlerMap[$taskName] = $handlerClass;
        return $this;
    }

    /**
     * @return array<string,class-string<TaskHandlerInterface>>
     */
    public function getMap(): array
    {
        return $this->handlerMap;
    }
}
