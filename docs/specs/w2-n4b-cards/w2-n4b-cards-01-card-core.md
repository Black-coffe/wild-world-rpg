---
story: w2-n4b-cards-01
spec: w2-n4b-cards
status: todo
returned:
tier: 2
worker: worker-code
wave: 1
blocked_by: []
red: required
red_reason: снимок карточки здания расходится с ядром (текст/кнопки бота), или карточка обороны/Навеса открывается во время переезда
---

# Ядро карточки постройки и один отрисовщик бота

## Goal
Есть `App\Services\Bases\BuildingCardService`: модель карточки «моей» постройки для 14 зданий без `chat_id`,
с переездом и выбором базы внутри. `BuildingHandlerAction` — единственный отрисовщик бота, 14 классов
`Camp/Buildings/*Handler.php` удалены. Текст, кнопки и photo/text бота — байт-в-байт по снимкам, снятым со
старых классов до правки.

## Requirements
> 1. Карточка постройки — одно ядро для бота и веба на все 14 зданий (включая Навес и три оборонных), бот — один отрисовщик, текст и кнопки байт-в-байт прежние.
> 4. Номер базы и блок на время переезда проверяет ядро, для обоих клиентов.

## Files
- app/Services/Bases/BuildingCardService.php
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
- app/Services/Navigation/NavigationMapService.php
- tests/unit/Camp/BuildingCardParityTest.php
- tests/unit/Camp/BuildingCardSnapshots.json
- tests/unit/Camp/BuildingHandlerCoverageTest.php
- tests/unit/Camp/BuildingCardBaseChoiceTest.php
- tests/unit/Camp/BuildingCardBaseScopeTest.php
- tests/unit/BuildingHandlerNoHardcodedIdTest.php
- tests/unit/Display/ConfigImageMapsFilesExistTest.php

## Non-goals
- Не чинить кривой текст бота («lvl», «0 всего / Без ограничений», «0$», «общая» вместо «коллективная») — расхождения копий ядро несёт флагом, список — в Findings.
- Не трогать собственные действия зданий (`Greenhouse/*`, `Robots/*`, `TeleportBeacon*`, `RepairBuildingAction`, `Upgrades/*`, `UpgradeBuildingAction`) и комментарии `ImageRegistry`.
- Не трогать веб — это story 02.

## Map slice
`memory/map/bases.md` (постройки, мульти-база), `memory/map/telegram.md` (MediaSender, navTarget).

## Acceptance criteria
- [ ] Снимки сняты со старых классов ДО их удаления (отдельный коммит или первый шаг теста с фикстурами) по матрице из plan.md § Assumptions; новый отрисовщик совпадает с каждым байт-в-байт, включая выбор photo vs text при отсутствии файла.
- [ ] Ядро отдаёт модель по контракту plan.md для всех ключей `Config\Buildings`, которые имеют карточку; неизвестный ключ — отказ с прежним текстом «🏚 Не удалось открыть это строение…» и кнопкой «🏠 База».
- [ ] Переезд: все 14 карточек отказывают тем же путём, что сейчас 12; тест на стену и Навес во время переезда.
- [ ] Суффикс `_b<id>` перепроверяется ядром (`BaseScopeResolver::resolveForBase`); прежние тесты выбора базы зелёные на отрисовщике.
- [ ] `BuildingHandlerCoverageTest` проверяет, что у каждой постройки конфига есть запись в таблице ядра.
- [ ] phpstan L9 чист на новых и изменённых файлах.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Camp/BuildingCardParityTest.php` && `vendor/bin/phpunit --no-coverage --no-progress tests/unit/Camp/BuildingHandlerCoverageTest.php` && `vendor/bin/phpunit --no-coverage --no-progress tests/unit/Camp/BuildingCardBaseScopeTest.php` && `vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes

## Findings
