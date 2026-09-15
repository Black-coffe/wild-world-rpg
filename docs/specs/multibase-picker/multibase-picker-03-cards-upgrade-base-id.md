---
story: multibase-picker-03
spec: multibase-picker
status: todo
returned:
tier: 3
worker: worker-code
model: sonnet
tracer: false
wave: 2
blocked_by: [multibase-picker-01]
---

# Карточки построек и «Поднять уровень» по базе из кнопки

## Goal
`BuildingHandlerAction`, 14 карточек построек и живой путь апгрейда (`UpgradeBuildingAction` → `BuildingUpgradeValidator`) разбирают необязательный суффикс `_b<id>` через `BaseCallbackSuffix::split()`. С суффиксом база берётся из `BaseScopeResolver::resolveForBase()` (`unavailable` → его текст); без суффикса — прежний `resolve()`. Кнопка «Поднять уровень» на карточке, открытой с суффиксом, несёт тот же суффикс; апгрейд меняет строку `character_buildings` именно этой базы.

## Requirements
> Кнопка базы в пикере и все кнопки, ведущие с экрана выбранной базы (карточки построек, «Поднять уровень», «🤖 Ангар», «Развитие базы», «Декор базы»), несут идентификатор базы в `callback_data` (≤64 байт, проверено тестом); обработчик заново проверяет, что база принадлежит персонажу, активна и доступна (игрок на ней или под её сигналом), иначе — честный отказ. Кнопка без идентификатора (из старых сообщений) работает по прежнему правилу `BaseScopeResolver`.

## Files
- app/Controllers/Telegram/Commands/Actions/Camp/BuildingHandlerAction.php
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/ArsenalHandler.php
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/BlastFurnaceHandler.php
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/CommunicationTowerHandler.php
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/DefensiveBuildingHandler.php
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/GreenhouseHandler.php
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/GymHandler.php
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/HandPumpHandler.php
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/LaboratoryHandler.php
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/LeanToHandler.php
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/RoboticsWorkshopHandler.php
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/SolarStationHandler.php
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/TeleportationCenterHandler.php
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/WarehouseHandler.php
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/WorkshopHandler.php
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/UpgradeBuildingAction.php
- app/Services/Player/BuildingUpgrade/BuildingUpgradeValidator.php
- tests/unit/Camp/BuildingCardBaseChoiceTest.php

## Non-goals
- Не трогать `tests/unit/Camp/BuildingCardBaseScopeTest.php` и тексты легаси-отказов (`ambiguous`, `no_bases`) — они закреплены и должны остаться зелёными.
- Не трогать `Buildings/Robots/*` (история 05), `TeleportBeacon*` (правило маяков намеренное), мёртвый `BaseBuildingUpgradeAction`.
- Не менять `BaseScopeResolver`/`BaseCallbackSuffix`/маршрутизацию (история 01) — нужна правка контракта → INTERFACES.
- Не чинить девять мест «постройка по персонажу без базы» из хвостов прошлой спеки.

## Map slice
`memory/map/bases.md` — Entry points (`BaseScopeResolver`, 14 карточек), Gotchas (angela-second-base-bugs-07). Контракты plan.md: суффикс, `resolveForBase`.

## Acceptance criteria
- [ ] Одинаковая постройка на двух базах разного уровня; игрок под сигналом Вышки базы-2 открывает `building_…_b<id2>` → карточка показывает уровень строки с `map_cell_id` базы-2. `BuildingCardBaseChoiceTest`.
- [ ] Та же кнопка с id чужой/неактивной/недоступной базы → текст `unavailable` из plan.md, строка не читается чужая.
- [ ] Кнопка «Поднять уровень» на такой карточке кончается на `_b<id2>`, `strlen ≤ 64`; апгрейд по ней меняет `level` строки базы-2 и НЕ меняет строку базы-1.
- [ ] Кнопки без суффикса ведут себя как до истории; `BuildingCardBaseScopeTest` зелёный без правок.
- [ ] Тест строит свою схему сам.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`

## Implementation notes

## Findings
