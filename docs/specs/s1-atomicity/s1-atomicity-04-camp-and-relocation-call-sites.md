---
story: s1-atomicity-04
spec: s1-atomicity
status: todo
returned:
tier: 3
worker: worker-code
wave: 2
blocked_by: [s1-atomicity-01, s1-atomicity-02]
red: required
# red_reason:
# red_verified_at:
---

# Роботы, теплица, переезд, база, стройка: запись задачи через ядро слота

## Goal
После story старты роботов (команда и action) и посадка в теплице вставляют `character_tasks` через `insertExclusiveTaskRow()` (C2/C3). Это параллельные задачи, так что отказа на них не бывает, но ветка `Refused` обработана без списаний (C6). Ветка `Refused` переезда (`RelocationTaskCreator` → `BaseShiftingCommand`, комментарий на `:209`) показывает `slotTakenText()` (C5) вместо `alreadyStartedExclusiveTaskText()`. Каждый UPDATE → `in_work` в handler'ах завершения переезда, в удалении базы и в `BuildOrderService` разобран. Если строка может быть 🔒, переход идёт через `moveToInWork()` (C4) и на `Refused` завершается без исключения. Если строка параллельная, она остаётся как есть, а решение записано в Implementation notes. Существующие тесты этих файлов получают схему C9.

## Requirements
> 3. Все места записи в `character_tasks` (24 вызова в 14 файлах, список в брифе) пишут через `ActiveTasksService::insertExclusiveTaskRow()`; отказ БД из-за занятого 🔒-слота игрок видит тем же текстом отказа ADR-167 («уже занят …»), а не ошибкой, и ресурсы при отказе не списываются.

## Files
- app/Controllers/Telegram/Commands/StartrobotexplorerCommand.php
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/Robots/StartRobotExplorationAction.php
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/Greenhouse/PlantCropActionStart.php
- app/Services/Player/Relocation/RelocationTaskCreator.php
- app/Controllers/Telegram/Commands/BaseShiftingCommand.php
- app/TaskHandlers/Built/BaseRelocationCompletionHandler.php
- app/TaskHandlers/Built/BaseFullRelocationCompletionHandler.php
- app/Controllers/Telegram/Commands/Actions/Camp/DeleteBaseAction.php
- app/Services/Buildings/BuildOrderService.php
- tests/unit/Controllers/Telegram/RelocationConfirmOutcomeTest.php
- tests/database/BaseRelocationCompletionHandlerTest.php
- tests/database/BuildOrderServiceTest.php
- tests/database/PlantCropSeedConditionalWriteTest.php
- tests/unit/Camp/BuildBotParityTest.php

## Non-goals
- Не трогать `StartRobotGatheringAction` (робот параллелен, вставки там нет) и `RelocationValidator` (гейт ADR-167 остаётся).
- Не менять гейт переезда `hasActiveRelocation()` / `TEXT_RELOCATION` и тексты подтверждения переезда.
- Не переводить на `moveToInWork()` переходы строк, которые не бывают 🔒 (робот, теплица, стройка), и переходы **из** `in_work`.
- Не править `CharacterResourceModel` и вызовы его методов (story 02). В тестах — только DDL по C9 и новые случаи, старые ассерты не переписывать.

## Map slice
`memory/map/tasks-worker.md` → `## Gotchas` (робот-handler'ы не пишут статус; `insertExclusiveTaskRow` для переезда; `hasActiveRelocation`). Нота `tech-writing/services/ActiveTasksService.md` → «Где ещё используется (exploit-fix-09/12)». Контракты C2–C6 в `plan.md`.

## Acceptance criteria
- [ ] Ни в одном файле story не осталось вставки `character_tasks` мимо `insertExclusiveTaskRow()`.
- [ ] Подтверждение переезда при `Refused`: игроку уходит `slotTakenText()` (текст ADR-167 «уже занят …» с именем блокирующей задачи), переезд не начат, ничего не списано. Это проверяет `RelocationConfirmOutcomeTest` — красный тест story.
- [ ] Робот и теплица стартуют рядом с `in_work` 🔒-задачей как раньше.
- [ ] Каждый UPDATE → `in_work` из списка (`BaseRelocationCompletionHandler:67`, `BaseFullRelocationCompletionHandler:76`, `DeleteBaseAction:429`, `BuildOrderService:346`, `RelocationTaskCreator:75`, `StartrobotexplorerCommand:298`, `StartRobotExplorationAction:219`, `PlantCropActionStart:127`) получил вердикт в Implementation notes: «🔒 → `moveToInWork`, на `Refused` — <что>» или «параллельная — без правки».
- [ ] Четыре остальных теста из `## Files` получили DDL по C9 (все таблицы S1 в каждом файле) и зелёные. Воркер гоняет их точечно; в `## Verification` их нет, чтобы `stamp-red` не запечатал (plan A8).

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Controllers/Telegram/RelocationConfirmOutcomeTest.php`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes

## Findings
