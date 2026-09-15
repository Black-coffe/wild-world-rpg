---
story: multibase-picker-05
spec: multibase-picker
status: todo
returned:
tier: 3
worker: worker-code
model: opus
tracer: false
wave: 1
blocked_by: []
---

# Робот-промышленник работает у базы запуска

## Goal
Робот запускается только с базы, на которой стоит игрок и на которой есть СВОЯ Мастерская робототехники (`character_buildings.map_cell_id` = клетка этой базы). Клетка базы запуска пишется в `character_tasks.task_settings` ключом `base_cell`; `CompleteRobotGatheringHandler` собирает вокруг неё. Экран запуска и сообщение-итог называют базу (имя + координаты). Задание без `base_cell` завершается по прежнему правилу.

## Requirements
> Стоя на второй базе без ангара запустила робота промышленника
> В какой локации - непонятно
> Робот-промышленник запускается только с базы, на которой есть своя Мастерская робототехники; клетка базы запуска сохраняется в задании (`character_tasks.task_settings`), и завершение сбора (`CompleteRobotGatheringHandler`) собирает вокруг именно неё. Экран запуска и сообщение-итог называют базу и координаты. Задание, запущенное до выкатки (без сохранённой базы), завершается без ошибки по прежнему правилу.
> Работает у выбранной базы: запуск только с базы, где есть своя Мастерская робототехники; робот копает вокруг этой базы (база запоминается в задании, без новой колонки); экран запуска называет базу и координаты; роботы остаются общим инвентарём персонажа.

## Files
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/Robots/StartRobotGatheringAction.php
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/Robots/RobotGathererActivator.php
- app/TaskHandlers/CompleteRobotGatheringHandler.php
- tests/unit/Camp/StartRobotGatheringBaseTest.php
- tests/unit/TaskHandlers/CompleteRobotGatheringBaseTest.php

## Non-goals
- Не возвращать фильтр Мастерской по `map_cell_id` на завершении в виде, который v0.51.30 убрал (комментарий `CompleteRobotGatheringHandler.php:138-141`): база, перенесённая/заброшенная после запуска, не должна ронять или обнулять сбор — тогда легаси-правило.
- Не привязывать роботов к базе в `crafted_items_log` и не добавлять колонок — роботы остаются инвентарём персонажа.
- Не протягивать номер базы через callback'и `AllRobotsHandler`/`ActivateRobotHandler`, не трогать робота-исследователя.
- Не менять `CommunicationTowerCoverageService` (история 01) и `tests/unit/TaskHandlers/CompleteRobotGatheringNameTest.php`.

## Map slice
`memory/map/bases.md` — Gotchas; `recon.md` — «🤖 Ангар» и роботы. Если есть `memory/map/tasks-worker.md` — раздел про TaskHandlers.

## Acceptance criteria
- [ ] Игрок на базе-2 без Мастерской (Мастерская есть на базе-1) → запуск отказан текстом, который называет эту базу и объясняет «нужна Мастерская робототехники на этой базе»; задание не создаётся. `StartRobotGatheringBaseTest`.
- [ ] Игрок на базе с Мастерской → задание создано, `task_settings` содержит `crafted_item_id` и `base_cell` = клетка этой базы; экран запуска называет базу (`MarkdownSafe::name()`) и координаты. `StartRobotGatheringBaseTest`.
- [ ] Завершение с `base_cell` активной базы персонажа → обход сбора стартует от неё, итог называет базу и координаты. `CompleteRobotGatheringBaseTest`.
- [ ] Завершение без `base_cell` (легаси) и с `base_cell` базы, ставшей неактивной, → без исключения, по прежнему правилу. `CompleteRobotGatheringBaseTest`.
- [ ] Экран запуска и итог полны без картинки (имя базы, координаты, состояние несёт текст); фото — только через `MediaSender`.
- [ ] Тесты строят свою схему сами; `CompleteRobotGatheringNameTest` остаётся зелёным.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`

## Implementation notes

## Findings
