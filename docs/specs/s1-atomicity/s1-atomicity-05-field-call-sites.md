---
story: s1-atomicity-05
spec: s1-atomicity
status: todo
returned:
tier: 3
worker: worker-code
model: opus
wave: 2
blocked_by: [s1-atomicity-01, s1-atomicity-02]
red: required
# red_reason:
# red_verified_at:
---

# Поле: Поход, добыча, мини-разведка, состояние игрока — через ядро слота

## Goal
Почему opus: решение «что делать при отказе» принимается отдельно на каждом из шести путей (игроцкий и фоновый, вставка и возобновление из `paused`), и цена ошибки — 500 в Походе или зависший персонаж.

После story следующий шаг Похода (`MarchingTaskHandler`) вставляет строку через `insertExclusiveTaskRow()` (C2/C3). Каждый UPDATE → `in_work` 🔒-строки в `MarchingTaskHandler`, `MarchService`, `GatherAction`, `CancelGatherAction`, `MarchMiniInvestigateAction` и `PlayerStateService` идёт через `moveToInWork()` (C4). Игроцкий путь на `Refused` отвечает `slotTakenText()` (C5) и ничего не списывает (C6). Фоновый путь (handler) не бросает исключение: шаг не создаётся, игроку уходит тот же текст существующим путём сообщений handler'а (A4). Возобновление `paused` 🔒 поверх `in_work` 🔒 отказывается, строка остаётся `paused` (A3). Два существующих теста Похода получают схему C9.

## Requirements
> 3. Все места записи в `character_tasks` (24 вызова в 14 файлах, список в брифе) пишут через `ActiveTasksService::insertExclusiveTaskRow()`; отказ БД из-за занятого 🔒-слота игрок видит тем же текстом отказа ADR-167 («уже занят …»), а не ошибкой, и ресурсы при отказе не списываются.
> 2. У персонажа не может быть двух задач `in_work` с `tasks.parallel_execution_allowed = 0`: ограничение держит БД (вычисляемая колонка + UNIQUE по образцу ADR-186), а не только проверка ADR-167; параллельные задачи (крафт, роботы) по-прежнему идут рядом с 🔒-задачей; `paused`/`queued`/`completed` ограничение не задевают — проверяется тестом с двумя одновременными стартами 🔒-задачи.

## Files
- app/TaskHandlers/MarchingTaskHandler.php
- app/Services/World/MarchService.php
- app/Controllers/Telegram/Commands/Actions/GatherAction.php
- app/Controllers/Telegram/Commands/Actions/CancelGatherAction.php
- app/Controllers/Telegram/Commands/Actions/MarchMiniInvestigateAction.php
- app/Services/Player/PlayerStateService.php
- tests/database/FieldTaskExclusiveSlotTest.php
- tests/database/MarchingTaskHandlerTest.php
- tests/unit/Transport/MarchingTransportTest.php

## Non-goals
- Не менять `BaseAction::checkParallelExecutionAllowed()` и гейт ADR-167 — БД только второй слой.
- Не менять баланс, длительности, тексты Похода и добычи, кроме ветки отказа. Новых кнопок нет.
- Не трогать переходы **из** `in_work` (пауза, отмена, завершение) — слот они только освобождают.
- Не править `Worker.php` и claim `in_work → completed`. В тестах — только DDL по C9 и новые случаи, старые ассерты не переписывать.

## Map slice
`memory/map/tasks-worker.md` → `## Gotchas` (Worker ставит `completed` до `handle()`; ADR-167; exploit-audit «3 перехлёста Похода»; фоновые `send*` в виртуальный чат — `WebDelivery`). Ноты `tech-writing/services/ActiveTasksService.md` (ADR-167), `tech-writing/services/MarchService.md`. Контракты C2–C6 в `plan.md`.

## Acceptance criteria
- [ ] **Воркер проверяет сам:** как `GatherAction`, `MarchService` и `PlayerStateService` создают или переводят строку задачи (`insert`, `save` другой модели, UPDATE существующей) и копирует ли код целую строку задачи. Вывод записан в Implementation notes.
- [ ] В файлах story не осталось вставки `character_tasks` мимо `insertExclusiveTaskRow()`. Код, копирующий строку задачи, снимает `id` и `exclusive_slot` (C1).
- [ ] Два одновременных старта добычи (или Похода) одного персонажа дают одну строку `in_work`. Проигравший получает `slotTakenText()`, исключения нет — это проверяет красный `FieldTaskExclusiveSlotTest` (два соединения, схема S1 через `up()` миграции story 01).
- [ ] Возобновление `paused` 🔒, пока другая 🔒 в `in_work`: отказ текстом `slotTakenText()`, строка осталась `paused`. Без занятого слота возобновление работает как раньше.
- [ ] Следующий шаг Похода при занятом слоте не создаётся, handler завершается без исключения, игрок получает текст отказа. Ресурсы и награды за ещё не созданный шаг не начислены и не списаны.
- [ ] Каждый UPDATE → `in_work` из списка (`MarchingTaskHandler:681`, `MarchService:223,311`, `GatherAction:261`, `CancelGatherAction:58`, `MarchMiniInvestigateAction:108`, `PlayerStateService:101,128`) получил вердикт в Implementation notes: «🔒 → `moveToInWork`, на `Refused` — <что>» или «не 🔒 / не переход в `in_work` — без правки».
- [ ] `MarchingTaskHandlerTest` и `MarchingTransportTest` получили DDL по C9 (все таблицы S1 в каждом файле) и зелёные. Воркер гоняет их точечно; в `## Verification` их нет, чтобы `stamp-red` не запечатал (plan A8).

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/database/FieldTaskExclusiveSlotTest.php`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes

## Findings
