---
story: multibase-picker-03
spec: multibase-picker
status: done
returned: DONE
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

- `BuildingHandlerAction.php`: strips `_b<id>` via `BaseCallbackSuffix::split()` before the
  name-routing `explode()` (defensive/explicit — the old code already tolerated the suffix
  since list-destructuring ignores extra `explode()` elements, but relying on that silently was
  fragile).
- All 14 card handlers (`Arsenal…WorkshopHandler`, `DefensiveBuildingHandler`, `LeanToHandler`):
  parse `_b<baseId>` via `BaseCallbackSuffix::split(getData())`; with a baseId use
  `BaseScopeResolver::resolveForBase()`, else the legacy `resolve()`. 13 of them also thread
  `$baseId` into the "🆙 Поднять уровень" button via `BaseCallbackSuffix::append()`. `LeanToHandler`
  has no upgrade button by design (S5/ADR-142 — one-shot no-upgrade); its `baseDevelopment`/`Base`
  buttons were left untouched (out of acceptance criteria, avoids overlap with story 06/02).
- `UpgradeBuildingAction.php`: `askForUpgrade()`/`confirmUpgrade()` now parse `_b<baseId>` from the
  raw `callback_data` via `BaseCallbackSuffix::split()` and forward it into
  `BuildingUpgradeValidator::validate(..., ?int $baseId)`. `buildingId` parsing itself
  (`$parts[2]`/`$parts[3]`) is unaffected — the suffix trails after it either way.
- `BuildingUpgradeValidator.php`: `validate()` gained a trailing `?int $baseId = null` param
  (backward-compatible — every existing caller, incl. `BuildingUpgradeBaseScopeTest` and
  `PoolAdoptionRepairUpgradeTest`, omits it and keeps hitting `resolve()`). With `$baseId` it calls
  `resolveForBase()` instead.
- New test `tests/unit/Camp/BuildingCardBaseChoiceTest.php` — own `bcbc_`-prefixed schema
  (`telegram_users, characters, buildings, character_buildings, claimed_cells, map, game_settings,
  tasks, character_tasks`), reuses `BuildingCardBaseScopeTest`'s namespaced `fopen()` shim (via its
  public static flag — a second `function fopen()` declaration in the same PHP process is a fatal
  redeclare, so this file does NOT declare its own). Resets `BuildingModel::$byNameEnCache`
  (process-wide static, keyed by `name_en`) in `setUp()` — without it, a later test reusing the same
  building name (e.g. `HandPump`) picks up the row id from an EARLIER test's already-dropped table,
  since that cache is not scoped to the DB prefix (found the hard way: `idByNameEn()` returned a
  stale id belonging to a table already recreated — see Findings for the general lesson).

INTERFACES: `BuildingUpgradeValidator::validate(array|CharacterEntity $character, int $buildingId, array $upgradeRequirements, ?int $baseId = null): array` —
new trailing optional param, non-breaking. `BuildingUpgradeMessageFormatter::askPrompt()` (NOT in
this story's `## Files`) builds the `confirm_upgrade_building_{id}` button WITHOUT the `_b<baseId>`
suffix — `UpgradeBuildingAction::confirmUpgrade()` and the validator are wired to accept and use the
suffix when present, but in production today the confirm click loses the selected base and falls
back to legacy `resolve()`. Acceptance criterion "апгрейд по ней меняет level строки базы-2"
is proven at the `BuildingUpgradeValidator`+`BuildingUpgradeApplier` layer (same layer the
reference `BuildingUpgradeBaseScopeTest` uses) and via `askForUpgrade()`'s own suffix parsing —
not via a full `askForUpgrade→confirmUpgrade` click-through, because the confirm button's owner
file is out of `## Files`. Follow-up: thread `?int $baseId` through
`BuildingUpgradeMessageFormatter::askPrompt()`'s confirm button in a story that owns that file.

## Findings

- `App\Models\BuildingModel::$byNameEnCache` is a process-wide static cache (not request-scoped,
  not DB-prefix-aware) — any test suite that reuses a `name_en` across multiple test methods with
  freshly-recreated tables will get a stale id from an earlier method unless it resets this static
  via reflection (done in `BuildingCardBaseChoiceTest::resetBuildingModelStaticCache()`). Not a
  regression from this story — pre-existing model behavior, worth a memory note for future test
  authors reusing building names (`feedback_test_schema_must_come_from_migration` neighbours).
- `phpstan-baseline.neon`: not touched, per plan.md's "one edit after wave 2" decision. The 8
  `CommunicationTowerCoverageService` `ignore.unmatched` entries are the expected pre-existing ones
  (plan.md, 2026-09-15 delta) — no new ones from this story's files.
