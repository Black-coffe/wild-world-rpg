---
story: multibase-picker-09
spec: multibase-picker
status: todo
returned:
tier: 3
worker: worker-code
model: opus
tracer: false
wave: 4
blocked_by: [multibase-picker-08]
---

# Робот и легаси-кнопки берут базу, которая реально покрывает игрока

## Goal
Совет, раунд 1, ask 5 RED. Игрок с 2+ базами стоит вне базы под сигналом только базы-2. Ветка tower в `BaseScopeResolver::resolve()` возвращает `findAllActiveCells()[0]`, то есть базу-1. Запуск робота (`StartRobotGatheringAction:148`, `RobotGathererActivator:74`) идёт с базы, которая игрока не покрывает. После этой истории:
1. **`resolve()`, ветка tower**: возвращает первую по `claimed_cells.id` базу, которую покрывает её собственная Вышка (`coverageByBase()`, `isCovered=true`). Остальные ветки (`on_base`, одна база, ambiguous, нет баз), все тексты и форма ответа не меняются байт в байт.
2. **База запуска робота** (общий static-хелпер в `StartRobotGatheringAction`, который зовёт и `RobotGathererActivator`). Если игрок стоит на своей базе, берётся она. Иначе берётся первая по `id` база, которую покрывает её собственная Вышка и на которой есть своя Мастерская (`workshopAtBase()`). Если покрытые базы есть, но Мастерской нет ни на одной, запуск отказывается текстом `noWorkshopOnBaseMessage()` с именем первой покрытой базы. Если покрытых баз нет, отказ идёт прежним текстом `resolve()`. Непокрытая база не выбирается никогда.
3. **Легаси-завершение** в `CompleteRobotGatheringHandler` (нет `base_cell` или база неактивна) берёт активную базу детерминированно, с наименьшим `id` (lead-review minor 8).
4. **Тест успешного запуска** ловит только ожидаемое исключение фото-транспорта. Имя базы проверяется в caption, который строит само действие (lead-review minor 7).

## Requirements
> Стоя на второй базе без ангара запустила робота промышленника
> Робот-промышленник запускается только с базы, на которой есть своя Мастерская робототехники; клетка базы запуска сохраняется в задании (`character_tasks.task_settings`), и завершение сбора (`CompleteRobotGatheringHandler`) собирает вокруг именно неё. Экран запуска и сообщение-итог называют базу и координаты. Задание, запущенное до выкатки (без сохранённой базы), завершается без ошибки по прежнему правилу.
> Работает у выбранной базы: запуск только с базы, где есть своя Мастерская робототехники; робот копает вокруг этой базы (база запоминается в задании, без новой колонки); экран запуска называет базу и координаты; роботы остаются общим инвентарём персонажа.
> обработчик заново проверяет, что база принадлежит персонажу, активна и доступна (игрок на ней или под её сигналом), иначе — честный отказ. Кнопка без идентификатора (из старых сообщений) работает по прежнему правилу `BaseScopeResolver`.

## Files
- app/Services/Bases/BaseScopeResolver.php
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/Robots/StartRobotGatheringAction.php
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/Robots/RobotGathererActivator.php
- app/TaskHandlers/CompleteRobotGatheringHandler.php
- tests/unit/Camp/BaseScopeResolverCoveredBaseTest.php
- tests/unit/Camp/StartRobotGatheringBaseTest.php
- tests/unit/TaskHandlers/CompleteRobotGatheringBaseTest.php

## Non-goals
- Не протягивать номер базы через callback'и `AllRobotsHandler` / `ActivateRobotHandler` / кнопки роботов в Ангаре (открытый хвост в plan.md).
- Не менять тексты `resolve()`, его ветки, кроме tower, и `resolveForBase()`. Не править `tests/unit/Camp/BuildingCardBaseScopeTest.php`. Если он краснеет, это WALL: остановиться и описать сценарий в Findings.
- Не менять сигнатуры `StartRobotGatheringAction::workshopAtBase()` / `::baseLabel()`: их зовёт `HangarAction` (история 10 в той же волне).
- Не трогать `CommunicationTowerCoverageService`, `phpstan-baseline.neon`, `CompleteRobotGatheringNameTest`.

## Map slice
`memory/map/bases.md`: Entry points (`BaseScopeResolver`), Gotchas. Контракты plan.md: `coverageByBase`, `resolveForBase`, `base_cell`. Implementation notes историй 01 и 05.

## Acceptance criteria
- [ ] `resolve()`: две активные базы, игрок вне обеих, покрывает только Вышка базы-2 → клетка базы-2. Покрывают обе → база-1. Не покрывает ни одна → прежний ответ. Тексты сверены с константами байт в байт. `BaseScopeResolverCoveredBaseTest`.
- [ ] Вне базы, 2 базы, покрывает только база-2 и на ней Мастерская → задание создано, `task_settings.base_cell` равен клетке базы-2, caption называет базу-2 с координатами. `StartRobotGatheringBaseTest`.
- [ ] Вне базы, 2 базы, покрывают обе, Мастерская только на базе-2 → запуск с базы-2. Покрывает только база-2, Мастерская только на базе-1 → отказ называет базу-2, задание не создано. Не покрывает ни одна → отказ, задание не создано. `StartRobotGatheringBaseTest`.
- [ ] Экран робота в `RobotGathererActivator` выбирает базу тем же хелпером и называет ту же базу, что запуск.
- [ ] Легаси-завершение при двух активных базах берёт базу с наименьшим `id` (вставка в обратном порядке) без исключения. `CompleteRobotGatheringBaseTest`.
- [ ] Тест успешного запуска ловит только ожидаемое исключение фото-транспорта. Имя базы проверяется в caption из реального вызова действия, не через отдельный `launchCaption()` с ручными аргументами.
- [ ] `BuildingCardBaseScopeTest` зелёный без правки. Тесты строят свою схему сами.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`

## Implementation notes

## Findings
