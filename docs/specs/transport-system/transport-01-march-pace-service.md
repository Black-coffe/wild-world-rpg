---
story: transport-01
spec: transport-system
status: todo
tier: 3
worker: worker-code
model: sonnet
tracer: true
wave: 0
blocked_by: []
---

# MarchPaceService — один источник темпа Похода

## Goal

Формула темпа Похода перестаёт существовать в двух независимых копиях. Появляется чистый
`App\Services\World\MarchPaceService` (без БД, без Telegram), и оба нынешних call-site —
превью маршрута в `MarchAction::showRouteSetup()` и `MarchingTaskHandler::etaMinutes()` —
считают через него. Числа игрока не меняются ни на единицу: это подготовка почвы под
персональный профиль, который придёт в `transport-04`. Схлопнуть надо **до** появления
множителей, иначе экран Похода начнёт врать владельцу транспорта в самом частом экране игры.

## Requirements

> и на нём можно быстро передвигаться по исследуемым ячейкам, не по исследуемым, скорость передвижения и так далее, механика передвижения

## Files
- app/Services/World/MarchPaceService.php
- app/Controllers/Telegram/Commands/Actions/MarchAction.php
- app/TaskHandlers/MarchingTaskHandler.php
- tests/unit/Transport/MarchPaceServiceTest.php

## Non-goals
- Никакого профиля транспорта, множителей и параметра `$profile` со значением, отличным от нейтрали: сервис принимает профиль по контракту, но в этой story ему передают нейтраль.
- Не трогать `stepDueInterval()`-семантику: формула переезжает в сервис как есть (`minutes_per_cell − 1`, намеренная компенсация дрожания крона).
- Не менять состав остановок Похода (край мира, вода, чужие `claimed_cells`, пол ❤️/💤) и per-cell броски.
- Не выносить в GameSettings новые ключи — `world.march.*` уже там.

## Map slice
`memory/map/world.md` (Entry points, Gotchas), `memory/map/tasks-worker.md`

## Acceptance criteria
- [ ] `MarchPaceService` конструируется без БД и без Telegram; все его методы чистые.
- [ ] Сигнатуры ровно как в `plan.md → ## Contracts`: `cellsPerTick`, `etaMinutes`, `stepDueInterval`, `tiredCostPerCell`, `healthCostPerCell`; константы `TERRAIN_EXPLORED`, `TERRAIN_UNEXPLORED`, `TERRAIN_COLD`.
- [ ] `stepDueInterval($minutesPerCell)` возвращает `minutes_per_cell − 1` и **не принимает профиль** — параметра для него в сигнатуре нет физически.
- [ ] ETA превью и ETA обработчика — один и тот же вызов сервиса: тест прогоняет таблицу (клетки 1,2,3,4,5,7,10,17,60) × (перТик 3) и требует равенства двух путей до целого числа.
- [ ] Байт-идентичность: при `world.march.cells_per_tick=3`, `minutes_per_cell=1` числа ETA и стоимостей ❤️/💤 совпадают с зафиксированными в тесте сегодняшними значениями (ассерт на числа, а не на «вызвался сервис»).
- [ ] `cellsPerTick()` зажат сверху 5 и снизу базой — на нейтральном профиле отдаёт ровно базу.
- [ ] Тест краснеет, если тело формулы испортить (не source-scan): проверяется возвращаемое значение, не наличие строки.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Transport/MarchPaceServiceTest.php`

## Tracer
Первый срез эпика: одна формула проходит путь «превью → сервис → обработчик» и доказывает,
что оба call-site теперь читают одну арифметику. Если окажется, что превью считает по другому
набору входов (например, другой источник `minutes_per_cell`), это меняет форму story 04 —
и узнать это надо здесь, а не на шестой story. Расхождение — в `## Findings`.

## Implementation notes

## Findings
