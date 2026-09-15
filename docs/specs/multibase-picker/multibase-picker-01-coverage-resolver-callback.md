---
story: multibase-picker-01
spec: multibase-picker
status: done
returned: DONE
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
- Files: `CommunicationTowerCoverageService.php` (rewritten: `coverageByBase()`, deterministic `checkCoverage()`, GameSettings radius), `BaseScopeResolver.php` (+`resolveForBase()`, constants; `resolve()` untouched), new `BaseCallbackSuffix.php`, `CallbackqueryCommand.php` (+static `actionOf()`, used in `execute()`), new migration `2026-12-07-100000_SeedTowerCoveragePerLevelSetting.php` (`php -l` clean), 3 new tests. `CallbackRouter.php` / `CallbackPrefixDispatcher.php` — unchanged (diff empty).
- Routing needed no code change: every layer hands the handler the original `CallbackQuery`; suffixed forms resolve by the same first segment / same dispatcher prefix. Proven in `BaseCallbackSuffixRoutingTest` via real `CallbackRouter::resolve`, `CallbackRoutes::resolve`, `actionOf`, and the `NavigationMapService::PREFIX_DISPATCHER` mirror; no existing route key ends in `_b\d+`.
- Rejected: refactoring the dispatcher into a `PREFIXES` table — `PrefixDispatcherMirrorSyncTest` and `CallbackDataRoutingTest` source-scan its `str_starts_with($callbackData, '…')` literals; reverted.
- Tower belongs to a base by `character_buildings.map_cell_id = claimed_cells.map_cell_id`. A tower row on an abandoned/other cell no longer counts (the old code counted any tower of the character).
- `checkCoverage()`: first covering base with a tower (by id), else first base with a tower; messages/shape unchanged. Surprise: `CharacterModel` returns `CharacterEntity` (ArrayAccess) — an `is_array()` guard made every character "not found"; caught by the test, fixed.
- Tests: own tables (`mbpc_*` via `setPrefix`, `mbpr_claimed_cells`), incl. a prefixed `game_settings` row to prove the key changes `maxCoverage`. Ran only the 3 own files on private DB `wildworld_ci_mbp01`: 23 passed.
- phpstan: 0 errors from this story's code, but 8 `ignore.unmatched` baseline entries for `CommunicationTowerCoverageService.php` (offset access on `cell_number`/`coordinate_x`×2/`coordinate_y`×2/`id`/`level`/`map_cell_id`) now fail as non-ignorable — they must be deleted from `phpstan-baseline.neon` (Queen's delta; not touched here). Plus 1 unrelated error `StartRobotGatheringAction.php:162 cast.int` — file modified in the tree by a parallel worker, not this story.
- INTERFACES: `CommunicationTowerCoverageService::coverageByBase(int $characterId, int $playerCell): list<array{base_id:int, cell:int, name:string, x:int, y:int, towerLevel:int, distance:int, maxCoverage:int, isCovered:bool}>` — `name` = raw `camp_name` ('' if unset; escape with `MarkdownSafe::name($name, 'База')`); `distance=-1` when player or base map row is missing. GameSettings key exactly `communication_tower.coverage_per_level` (int, default 100, category `buildings`, soft 50–200, hard 1–1000); constants `SETTING_COVERAGE_PER_LEVEL`, `DEFAULT_COVERAGE_PER_LEVEL`; constructor gained optional `?GameSettingsService $settings = null`. `BaseScopeResolver::resolveForBase(int $characterId, int $currentCell, int $baseId): array{cell:?int, base_id:?int, reason:string, text:string}` — `text=''` on `on_base`/`tower`; constants `REASON_ON_BASE|REASON_TOWER|REASON_UNAVAILABLE`, `TEXT_UNAVAILABLE` (byte-exact plan text). `BaseCallbackSuffix::append(string,int): string` (throws `\LengthException` > `MAX_BYTES=64`), `::split(string): array{0:string,1:?int}`. `CallbackqueryCommand::actionOf(string): string` (public static). Router does NOT strip the suffix: handlers get full `callback_data` via `$callbackQuery->getData()` and must call `BaseCallbackSuffix::split()` themselves.

## Findings
