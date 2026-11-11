---
story: s1-atomicity-01
spec: s1-atomicity
status: todo
returned:
tier: 3
worker: worker-code
wave: 1
blocked_by: []
red: required
# red_reason:
# red_verified_at:
---

# Занятый 🔒-слот держит БД: колонка, UNIQUE и точки записи `ActiveTasksService`

## Goal
После story в `character_tasks` есть `is_exclusive`, STORED-колонка `exclusive_slot` и `UNIQUE uq_ct_exclusive_slot` (Contract C1). Их ставит миграция с бэкфиллом флага из `tasks.parallel_execution_allowed` и с проверкой нарушений до создания индекса (C10). `ActiveTasksService::insertExclusiveTaskRow()` сам ставит флаг и отдаёт `Refused`, когда слот занят (C2). Рядом появляются `lastInsertedTaskId()` (C3), `moveToInWork()` (C4) и `slotTakenText()` (C5): ими пользуются story волны 2, а фикстуры story 06–08 копируют определения колонок из этой миграции. Тест на двух реальных соединениях доказывает, что из двух одновременных стартов 🔒-задачи проходит ровно один.

## Requirements
> 2. У персонажа не может быть двух задач `in_work` с `tasks.parallel_execution_allowed = 0`: ограничение держит БД (вычисляемая колонка + UNIQUE по образцу ADR-186), а не только проверка ADR-167; параллельные задачи (крафт, роботы) по-прежнему идут рядом с 🔒-задачей; `paused`/`queued`/`completed` ограничение не задевают — проверяется тестом с двумя одновременными стартами 🔒-задачи.
> 6. Миграции безопасны для прода: перед созданием UNIQUE проверяют дубли и при их наличии падают с понятным сообщением, а не удаляют строки; `php -l` чист; новые колонки и индексы попадают в тестовую схему (фикстуры, которые пишут DDL `character_tasks` руками); `WipeManifest` не меняется (новых таблиц нет).
> «одна эксклюзивная задача» в `character_tasks` со всеми местами записи через `insertUnique()` (F11)

## Files
- app/Database/Migrations/2026-12-25-100000_S1CharacterTasksExclusiveSlot.php
- app/Models/CharacterTaskModel.php
- app/Services/Tasks/ActiveTasksService.php
- tests/database/ExclusiveTaskSlotConstraintTest.php
- tests/database/ExclusiveTaskLockTest.php

## Non-goals
- Не переводить вызывающих (`CraftOrderService`, `RelocationTaskCreator`, actions, handler'ы) — это волна 2.
- Не менять логику `findBlockingTask()` / `exclusiveConflict()`: гейт ADR-167 остаётся первым слоем.
- Не трогать `app/Config/Database.php` и `app/Config/WipeManifest.php`. Ни триггеров, ни новых таблиц.
- Не добавлять примитив в `ConditionalWriteService`: `moveToInWork` живёт в `ActiveTasksService`.

## Map slice
`memory/map/tasks-worker.md` → `## Gotchas` (пункты про ADR-167, exploit-audit `character_tasks`, ADR-181 `insertExclusiveTaskRow`, `task_settings`). Ноты `tech-writing/services/ActiveTasksService.md`, `tech-writing/services/ConditionalWriteService.md` (раздел «Контракт внутри чужой транзакции»).

## Acceptance criteria
- [ ] **Воркер проверяет сам:** точные имена и типы FK персонажа и задачи в `character_tasks` и список значений `status`. `exclusive_slot` получает тип FK персонажа. Если что-то в коде копирует целую строку задачи в insert/update, это записано в Implementation notes для story 03/05.
- [ ] Порядок `up()`: добавить `is_exclusive` → бэкфилл `1` по join на `tasks` для всех строк с `parallel_execution_allowed = 0`, в любом статусе → посчитать персонажей с 2+ строками `in_work` и `is_exclusive = 1` (при наличии `RuntimeException` с числом и до 5 id персонажей, строки не трогать) → добавить `exclusive_slot` и UNIQUE. `down()` обратим.
- [ ] Тест строит `character_tasks`/`tasks` так: базовая таблица может быть рукописной (у `task_settings` нет миграции, см. map), а колонки и индекс S1 появляются только вызовом `up()` этой миграции. Синтаксическая ошибка в миграции роняет тест.
- [ ] Два соединения одновременно стартуют 🔒-задачу одного персонажа через `insertExclusiveTaskRow()`: одно `Applied`, другое `Refused`, в `in_work` ровно одна строка, исключения нет.
- [ ] Параллельная задача (`parallel_execution_allowed = 1`) рядом с `in_work` 🔒 — `Applied`. Строки 🔒 в `paused` / `queued` / `completed` рядом с `in_work` 🔒 сосуществуют.
- [ ] `moveToInWork()` строки `paused` 🔒, когда другая 🔒 в `in_work`: `Refused`, строка остаётся `paused`. Внутри открытого `transStart()` `transStatus()` остаётся `true`. Строки нет в `$fromStatus` → `Missing`.
- [ ] Killswitch `craft.exclusive_lock.enabled = 0` → вставка пишет `is_exclusive = 0` (assumption A2).
- [ ] `slotTakenText()` возвращает текст `ActionScopeService::exclusiveBlockText` с именем блокирующей задачи. Без блокировщика — `alreadyStartedExclusiveTaskText()`.
- [ ] `CharacterTaskModel::$allowedFields` содержит `is_exclusive` и не содержит `exclusive_slot`.
- [ ] Рукописный DDL `ExclusiveTaskLockTest` — по C9, тест зелёный. Воркер гоняет его точечно; в `## Verification` его нет, чтобы `stamp-red` не запечатал (A8).

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/database/ExclusiveTaskSlotConstraintTest.php`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
`git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`

## Implementation notes

## Findings
