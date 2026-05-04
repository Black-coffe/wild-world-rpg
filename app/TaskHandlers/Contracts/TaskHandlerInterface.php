<?php

namespace App\TaskHandlers\Contracts;

/**
 * F2.9 — общий контракт для всех task-handler'ов.
 *
 * Сейчас каждый task-handler в `app/TaskHandlers/**` живёт сам по себе:
 *  - одни наследуют CI4 `Controller` (исторически неправильно, см.
 *    `mmorpg-vault/lore/refactor/CI4-style.md` P1 item 6),
 *  - сигнатуры `handle(...)` различаются (`handle(array $task)`,
 *    `handle()`, `process()`, `run()`),
 *  - возвращаемые типы не унифицированы.
 *
 * Этот интерфейс задаёт минимально необходимый контракт. Постепенная
 * миграция: новые handler'ы реализуют его сразу, старые — по мере
 * ребилда. Worker.php и `TaskDispatcher` (F2.4) проверяют этот интерфейс
 * перед вызовом — и могут типобезопасно гонять любой handler.
 *
 * Используется парой с `BaseTaskHandler` (общий базовый класс, который
 * уже реализует init Telegram, log_message и т.п.). Но требовать
 * наследование не обязательно — реализация интерфейса достаточна.
 */
interface TaskHandlerInterface
{
    /**
     * Выполнить task-handler.
     *
     * @param array<string,mixed> $task Запись из `character_tasks` (или []
     *                                  для recurring-handler'ов которые
     *                                  не привязаны к конкретной задаче).
     */
    public function handle(array $task = []): void;
}
