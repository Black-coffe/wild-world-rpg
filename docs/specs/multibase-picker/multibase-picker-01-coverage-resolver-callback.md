---
story: multibase-picker-01
spec: multibase-picker
status: todo
returned:
tier: 3
worker: worker-code
model: opus
tracer: true
wave: 1
blocked_by: []
---

# Покрытие по каждой базе, проверка выбранной базы, суффикс базы в callback

## Goal
`CommunicationTowerCoverageService` считает покрытие по каждой активной базе персонажа (по `claimed_cells.id` ASC), радиус на уровень Вышки — ключ `GameSettings`. `BaseScopeResolver` получает путь с явной базой. Появляется кодек суффикса базы `BaseCallbackSuffix`, и суффиксный callback доходит до того же обработчика с полным `callback_data`. Это общая основа для историй волны 2 (см. `## Contracts` в plan.md).

## Requirements
> `CommunicationTowerCoverageService` оценивает покрытие по КАЖДОЙ активной базе персонажа в детерминированном порядке (по `id`), а не по одной `first()` без `orderBy`; радиус покрытия на уровень вышки — ключ `GameSettings` с rationale/effect/above/below, soft/hard-границами и Reset-to-default (сейчас зашито `towerLevel * 100`), дефолт равен нынешнему поведению.
> обработчик заново проверяет, что база принадлежит персонажу, активна и доступна (игрок на ней или под её сигналом), иначе — честный отказ. Кнопка без идентификатора (из старых сообщений) работает по прежнему правилу `BaseScopeResolver`.
> База в самой кнопке: номер базы добавляется в callback кнопки постройки (как в выборе базы телепорта); кнопки из старых сообщений продолжают работать по старому правилу; хранилище не нужно.

## Files
- app/Services/Coverage/CommunicationTowerCoverageService.php
- app/Services/Bases/BaseScopeResolver.php
- app/Services/Bases/BaseCallbackSuffix.php
- app/Controllers/Telegram/Commands/SystemCommands/CallbackqueryCommand.php
- app/Services/Telegram/CallbackRouter.php
- app/Services/Telegram/CallbackPrefixDispatcher.php
- app/Database/Migrations/2026-12-07-100000_SeedTowerCoveragePerLevelSetting.php
- tests/unit/Camp/CommunicationTowerCoverageByBaseTest.php
- tests/unit/Camp/BaseScopeResolverForBaseTest.php
- tests/unit/Telegram/BaseCallbackSuffixRoutingTest.php

## Non-goals
- Не менять `BaseScopeResolver::resolve()` и его тексты; не трогать `tests/unit/Camp/BuildingCardBaseScopeTest.php`.
- Не править вызывающих (`DetailedBaseInfoAction`, `StartRobotGatheringAction`, карточки) — это истории 02–06.
- Не вводить хранилище «выбранной базы» (кэш, колонка, сессия).
- Не трогать `phpstan-baseline.neon`.

## Map slice
`memory/map/bases.md` — Entry points (`BaseScopeResolver`), Gotchas (angela-second-base-bugs-07). `recon.md` — «🏠 База» и Вышка связи.

## Acceptance criteria
- [ ] `coverageByBase()` по контракту plan.md: две активные базы, у второй Вышка покрывает клетку игрока, у первой нет → вторая `isCovered=true`, первая `false`; порядок по `id`; `abandoned` не попадает. `CommunicationTowerCoverageByBaseTest`.
- [ ] Радиус = `towerLevel × <ключ GameSettings>`; ключ засеян идемпотентной миграцией с rationale/effect/above/below, soft/hard и дефолтом 100; при дефолте результат равен прежнему `towerLevel * 100`. Смена значения ключа в тесте меняет `maxCoverage`.
- [ ] `checkCoverage()` сохраняет форму ответа и детерминирован (без `first()` без `orderBy`).
- [ ] `resolveForBase()`: игрок на базе → `on_base`; не на ней, но под её Вышкой → `tower`; чужая / неактивная / вне сигнала → `unavailable` с текстом из plan.md байт в байт. `BaseScopeResolverForBaseTest`.
- [ ] `BaseCallbackSuffix::append/split` по контракту; `append` бросает `\LengthException` за 64 байтами; суффиксные `building_…_b<N>`, `upgrade_building_…_b<N>`, `hangar_b<N>` и суффиксный callback экрана «🏠 База» маршрутизируются к тем же обработчикам, что без суффикса, с полным `callback_data`; ни один существующий маршрут не кончается на `_b<цифры>`. `BaseCallbackSuffixRoutingTest`.
- [ ] Тесты строят свою схему сами (не опираются на состояние общей тест-БД); `php -l` новой миграции чист.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`

## Tracer
Слой данных (`claimed_cells`, `character_buildings` Вышки) → сервис покрытия → резолвер → кодек → маршрутизатор. Если маршрутизатор не отдаёт обработчику полный `callback_data` или суффикс конфликтует с существующими маршрутами — остановиться и сообщить в INTERFACES: контракт волны 2 меняется.

## Implementation notes

## Findings
