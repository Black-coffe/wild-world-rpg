---
story: angela-second-base-bugs-02
spec: angela-second-base-bugs
status: todo
tier: 3
worker: worker-code
tracer: false
wave: 1
blocked_by: []
---

# Карточка здания показывает постройку той базы, где стоит игрок

## Goal
Каждый экран-карточка здания на базе перестаёт искать постройку по паре `(character_id, building_id)`
и читает строку `character_buildings` той базы, в клетке которой находится игрок. Игрок с Теплицей
10 уровня на первой базе и Теплицей 1 уровня на второй видит на каждой базе её собственный уровень.

## Requirements
> На второй базе постройки первого уровня при попытке поднять уровень пишут,
> что уровень уже максимальный, десятый
> Если те баги остались еще, устрани их, протестируй на тест-проде и потом залей на боевой. И с смок-тестами.

## Files
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
- tests/unit/Camp/BuildingCardBaseScopeTest.php

## Non-goals
- Не трогать `app/Services/Player/BuildingUpgrade/*`, `UpgradeBuildingAction.php` и `Upgrades/BaseBuildingUpgradeAction.php` — это история 03, она идёт в той же волне и правит те же строки логики в своих файлах.
- Не трогать `Buildings/Robots/*`, `TeleportBeacon*.php`, `RepairBuildingAction.php`, `ShowBaseInfoAction.php` — вне охвата (`## Открытые хвосты` плана).
- Не менять тексты карточек, порядок кнопок, эмодзи и формат `callback_data` — меняется ТОЛЬКО то, какая строка `character_buildings` прочитана.
- Не добавлять методы в `ClaimedCellModel` — им владеет история 01; здесь пользуемся существующим `resolveTargetBaseCell`.
- Не рефакторить четырнадцать хендлеров в общий базовый класс: правка точечная, один и тот же запрос в каждом.

## Map slice
`memory/map/bases.md` (постройки, `character_buildings`, мульти-база), `memory/map/telegram.md` (action-handler'ы).

## Ключевые места (разведка уже сделана, искать не надо)
- Образец дефекта — `GreenhouseHandler.php:48-55`: `idByNameEn('Greenhouse')` даёт КАТАЛОЖНЫЙ `buildings.id` (один на все базы), дальше `->where('character_id',…)->where('building_id',$buildingId)->first()` без `map_cell_id` и без `orderBy`. Уровень для подписи «🆙 Уровень постройки: %d lvl» берётся из этой строки.
- Тот же запрос с тем же смыслом в остальных тринадцати файлах списка (строки ~48-78 каждого).
- Как правильно — `app/Controllers/Telegram/Commands/Actions/Camp/GenericBuildingAction.php:120-121`: `$currentCell = (int) $character['cell_number']`, дальше резолв базы через `ClaimedCellModel`.
- Контраст: `app/Services/Bases/BaseBuildingsList.php:52-58` уже фильтрует по `map_cell_id` — поэтому кнопка списка честно пишет «Теплица L1», а карточка врала «10 lvl».

## Acceptance criteria
- [ ] На базе A и базе B с одним типом здания разных уровней карточка на каждой базе показывает уровень своей строки `character_buildings`.
- [ ] Если у персонажа здание есть только на другой базе, карточка на текущей базе не показывает чужие числа.
- [ ] Если база неоднозначна (`resolveTargetBaseCell` вернул `null`), экран отвечает согласованным текстом из `## Contracts` плана, а не падает и не молчит.
- [ ] Персонаж с одной базой: поведение всех четырнадцати карточек не изменилось.
- [ ] Тексты карточек полноценны без картинок: уровень и состояние постройки несёт сам текст.
- [ ] `vendor/bin/phpstan analyse --memory-limit=512M --no-progress` зелёный по тронутым файлам.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Camp/`

## Замечания по тестам (обязательно прочесть)
- Тест обязан проверять ПОВЕДЕНИЕ выборки, а не наличие подстроки в исходнике: скан исходника оставит сломанный метод зелёным (`feedback_source_scan_tests_are_not_coverage`).
- DB-тест строит свою схему сам — CI гоняет на ПУСТОЙ базе без миграций.
- Схема в тесте должна повторять миграцию, а не быть написанной от руки по памяти.
- НИКОГДА не делай `git stash` / `git checkout` и не роняй общую локальную тест-БД (DROP/migrate). Старый вариант файла — `git show HEAD:<path>`.
- Не запускай весь набор параллельно с другими агентами.

## Implementation notes

## Findings
