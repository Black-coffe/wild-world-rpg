---
story: s2-conditional-writes-09
spec: s2-conditional-writes
status: todo
returned:
tier: 3
worker: worker-code
wave: 3
blocked_by: []
red: required
red_reason: "interrupt penalty applied twice, or robot award lost a concurrent increment"
---

# F21 + F24: статус `character_tasks` — один условный писатель; выдача роботов относительная

## Goal

Две точки с одной таблицей и одной формой, сведены в одну story (диф F21 мал рядом с накладными воркера).
**F21:** `FinishTaskAction.php:67-113` пишет `status => interrupted` без условия (`:90`) и штраф `adjust` (`:113`)
безусловно — двойной тап = двойной штраф. После story переход — `transitionIfCurrent('character_tasks', $id,
'status', 'in_work', 'interrupted')`; не `Applied` → существующая ветка «Задача не найдена или уже завершена.» (`:76`)
без штрафа. **F24:** `CompleteRobotGatheringHandler.php:99` и `CompleteRobotExplorationHandler.php:78` пишут статус
задачи повторно, хотя `Worker.php:101-109` уже атомарно клеймит `in_work → completed` до `handle()`; выдача сбора
`:268-283` — read-then-write. После story дубль статуса удалён, выдача ресурсов — `increment` по
`(id_characters, id_resources)` / `insertUnique` при `Missing`, не `quantity = снимок + n`.

## Requirements

> 7. F21 — прерывание задачи (`FinishTaskAction`) применяет штраф только при успешном переходе `in_work → interrupted`
> 10. F24 — обработчики роботов (`CompleteRobotGatheringHandler`, `CompleteRobotExplorationHandler`) не пишут статус задачи
> F21 штраф задачи
> F24 completion-handler'ы роботов

## Files

- app/Controllers/Telegram/Commands/Actions/FinishTaskAction.php
- app/TaskHandlers/CompleteRobotGatheringHandler.php
- app/TaskHandlers/CompleteRobotExplorationHandler.php
- tests/database/FinishTaskInterruptOnceTest.php
- tests/database/RobotGatheringRelativeAwardTest.php

## Non-goals

- `app/Controllers/Worker.php` не трогать — клейм там уже атомарный.
- Не менять размер штрафа прерывания, таблицы лута робота, тексты уведомлений.
- F24: если у `handle()` есть вызывающий кроме `Worker` без клейма (вопрос 3 плана) — не удалять запись, а
  заменить на `transitionIfCurrent(in_work → completed)` с проигнорированным-но-залогированным исходом, и сказать об
  этом в отчёте. Условный переход внутри `Worker`-пути всегда даст `Refused` — это ожидаемо.
- Не править `phpstan-baseline.neon` (устаревшие записи после удаления дубля — списком, `WALL`).

## Map slice

- Recon до волны 1 (Q3, 28.10): обработчики роботов вызывает только `Worker` (`Worker.php:322-323` карта, клейм `:101-109` до `handle()` `:124`); прочие упоминания — комментарии и тексты GameSettings. Избыточную запись статуса убрать (форма плана), `transitionIfCurrent` не нужен.

`memory/map/tasks-worker.md` → Gotchas («Worker ставит статус `completed` ДО вызова `handle()`», три слоя защиты,
`telegram()` в тестах, `task_settings` не в миграциях — рукописный DDL в фикстуре); `memory/map/bases.md` → Entry
points (`StartRobotGatheringAction` / `CompleteRobotGatheringHandler`). Контракт и форма теста — `plan.md` → `## Contracts`.

## Acceptance criteria

- [ ] F21: переход статуса — `transitionIfCurrent`; штраф и любой другой эффект прерывания идут только на `Applied`;
      `Refused`/`Missing` → текст «Задача не найдена или уже завершена.», `Missing` пишет `error`.
- [ ] F24: ни один из двух обработчиков не пишет `status` сам (или только условно, см. Non-goals); выдача ресурсов
      сбора — относительная запись, прочитанное количество в `UPDATE` не участвует; `Missing` → `insertUnique`
      строки со всеми NOT NULL-колонками.
- [ ] Тест `FinishTaskInterruptOnceTest`: задача `in_work`, первый вызов → `interrupted`, штраф применён один раз;
      второй вызов на ту же задачу → отказ, статы персонажа не изменились. Сообщение ассерта при провале:
      `interrupt penalty applied twice, or robot award lost a concurrent increment`.
- [ ] Тест `RobotGatheringRelativeAwardTest`: две выдачи от одного снимка строки ресурса дают сумму в БД (не
      последнюю); выдача в отсутствующую строку создаёт одну строку. Тот же текст ассерта. Без живого Telegram.
- [ ] Фикстура `character_tasks` воспроизводит NOT NULL/ENUM миграции (+ `task_settings` рукописно).

## Verification

`vendor/bin/phpunit --no-coverage --no-progress tests/database/FinishTaskInterruptOnceTest.php && vendor/bin/phpunit --no-coverage --no-progress tests/database/RobotGatheringRelativeAwardTest.php && vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes

## Findings
