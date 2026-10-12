---
story: oct-world-robot-fix-01
spec: oct-world-robot-fix
status: todo
returned:
tier: 1
worker: worker-code
model: sonnet
wave: 1
blocked_by: []
---

# «🌍 Мир» без отказа при занятости + робот-добытчик с лагеря через покрытую базу

## Goal
Callback `move` при сборе/исследовании открывает экран ходьбы, как нижняя кнопка «🌍 Мир».
`launchBase()` для игрока, стоящего на своей базе без Мастерской, находит покрытую Вышкой базу с
Мастерской; отказ — только когда такой нет.

## Files
- app/Controllers/Telegram/Commands/Actions/MoveCharacterAction.php
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/Robots/StartRobotGatheringAction.php
- tests/unit/Camp/StartRobotGatheringBaseTest.php
- tests/unit/World/MoveCharacterActionBusyTest.php
- tests/unit/World/MoveStepBusyDuringGatherTest.php

## Non-goals
- Не трогать `MoveService::step` и его отказ `BUSY`: шагать во время занятости по-прежнему нельзя.
- Не убирать отказ при переезде (`checkRelocationAndBlock`).
- Не переписывать `RobotGathererActivator`: он зовёт тот же `launchBase()` и подхватит фикс сам.
- Не менять правило «робот работает у базы, с которой запущен» и формулы охвата/времени.

## Map slice
`memory/map/telegram.md` (action-handler'ы), `memory/map/bases.md` (мульти-база, Вышка связи).

## Acceptance criteria
- [ ] Callback `move` у персонажа с активным сбором (и отдельно — с исследованием) отдаёт экран `MoveSurfaceService::show`, а не «Вы заняты…».
- [ ] Тест: у персонажа с активной задачей `Gather` (и отдельно `ExploreTheArea`), заведённой в тест-схеме с `parallel_execution_allowed = 0` как на проде, `MoveService::step` возвращает `BUSY` и клетка персонажа не меняется.
- [ ] Tier-3 на testbot (тест-чар `telegram_user_id=25`): запустить сбор → экран дрона → «🌍 Мир» открывает экран ходьбы → тап по стрелке отвечает «Невозможно переместиться», координаты в профиле не изменились. Доказательство — в Implementation notes.
- [ ] Игрок на своей базе без Мастерской, покрытый Вышкой другой своей базы с Мастерской: `launchBase()` возвращает клетку той базы и `text = null`.
- [ ] Игрок на своей базе без Мастерской, покрытых баз с Мастерской нет: `launchBase()` возвращает его базу и текст «нет Мастерской» с её названием (как раньше).
- [ ] Игрок на базе с Мастерской — по-прежнему её база (регрессия существующих тестов зелёная).
- [ ] Tech-writing ноты `MoveCharacterAction` и `StartRobotGatheringAction` в `mmorpg-vault/tech-writing/handlers/` обновлены (`last_reviewed: 2026-10-12`).

## Verification
`vendor/bin/phpunit --no-coverage --no-progress && vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes

## Findings
