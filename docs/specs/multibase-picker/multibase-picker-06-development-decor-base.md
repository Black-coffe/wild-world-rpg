---
story: multibase-picker-06
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

# «Развитие базы» и «Декор базы» — по выбранной базе

## Goal
`BaseDevelopmentAction` показывает уровни построек выбранной базы (строки `character_buildings` с её `map_cell_id`) вместо `MAX(level)` по всем базам. «Декор базы» правит выбранную базу: action декора передаёт в `BaseCampDecorService` клетку, полученную из суффикса `_b<id>` через `resolveForBase()`. Без суффикса — прежнее правило `BaseScopeResolver::resolve()`; `unavailable` → его текст.

## Requirements
> «Развитие базы» показывает уровни построек выбранной базы, а не `MAX(level)` по всем базам; «Декор базы» правит выбранную базу.
> Развитие и декор — да, маяки — в хвост: «Развитие базы» и «Декор базы» работают с той же базой, что и пикер; правило «Центр телепортации на любой базе» у маяков записывается как намеренное, не чинится.

## Files
- app/Controllers/Telegram/Commands/Actions/Camp/BaseDevelopmentAction.php
- app/Services/Housing/BaseCampDecorService.php
- app/Controllers/Telegram/Commands/Actions/Camp/Decor/BaseCampDecorAction.php
- tests/unit/Camp/BaseDevelopmentBaseScopeTest.php
- tests/unit/Camp/BaseCampDecorBaseChoiceTest.php

## Non-goals
- Не трогать `TeleportBeacon*` — правило маяков намеренное (фиксируется в ADR-187 Queen'ом).
- Не менять кнопки на экране базы (история 02) и маршрутизацию (история 01).
- Не менять содержимое/каталог декора и его цены.

## Map slice
`recon.md` — «Твины» (`BaseCampDecorService.php:124-134 resolveCell`, `BaseDevelopmentAction.php:70-75`). Контракты plan.md: суффикс, `resolveForBase`.

## Acceptance criteria
- [ ] Постройка ур. 7 на базе-1 и ур. 1 на базе-2; «Развитие базы» с `_b<id2>` показывает 1, с `_b<id1>` — 7. `BaseDevelopmentBaseScopeTest`.
- [ ] «Декор базы» с `_b<id2>` меняет декор базы-2, база-1 не тронута. `BaseCampDecorBaseChoiceTest`.
- [ ] Чужая/неактивная/недоступная база в суффиксе → текст `unavailable` из plan.md, запись не происходит.
- [ ] Без суффикса — база по `BaseScopeResolver::resolve()`; `resolveCell()` для активной переданной клетки ведёт себя как прежде.
- [ ] Экраны полны без картинки (имя базы и уровни — в тексте).
- [ ] Тесты строят свою схему сами.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`

## Implementation notes

- `BaseDevelopmentAction.php`: parses `_b<id>` suffix via `BaseCallbackSuffix::split()`; with suffix → `BaseScopeResolver::resolveForBase()`, without → `resolve()`; query now filters `cb.map_cell_id = $cell` (was `MAX(level)` grouped only by `character_id`). Null `cell` (unavailable/no_bases/ambiguous) → new `refusal()` helper shows the reason text, no query runs.
- `BaseCampDecorAction.php`: same resolve-first pattern before any regex dispatch — an `unavailable`/`no_bases`/`ambiguous` outcome returns immediately, so no `setCamp*()` write ever fires for a base the check rejected. Every button the screen emits (`campDecorName/Flag/Hearth/Furniture/Pet`, `campSetX_<idx>`, `campDecor` back, `Base` back) carries the suffix forward via new `withSuffix()` helper — no multistep text-input state exists here (all flows are inline-keyboard clicks), so suffix survival is purely button-chaining, not conversation-state.
- `BaseCampDecorService.php`: no functional change — `resolveCell(cellNumber)` was already cell-aware (ADR-095 1b); only doc comment updated to note the caller now feeds it a vetted cell from `BaseScopeResolver`. Left in `## Files` per story but touched non-functionally.
- INTERFACES: suffix survives decor's multi-screen flow purely through `BaseCallbackSuffix::append()` on every generated `callback_data`, not through stored state — matches contract (no conversation state to protect).
- Tests seed their own private-prefixed schema (`bdbs_*`, `bcdc_*`), same DDL pattern as `BuildingCardBaseScopeTest`. Decor test also needs a `game_settings` table + `housing.decoration.enabled=1` row (killswitch), since `GameSettingsService` degrades to `false` when the table is missing.
- Suffix-base access still requires `on_base` or tower coverage per `resolveForBase()` contract — tests simulate a player moving between bases (like `BuildingCardBaseScopeTest`) rather than building towers/map rows, to keep schema minimal.

## Findings

phpstan: 8 known `ignore.unmatched` for `CommunicationTowerCoverageService` occurred as expected. 10 additional errors reported in `app/Services/BaseService.php` (missing iterable value types, `Cannot cast mixed to int`, `basePicker()` undefined) — that file is not in this story's `## Files` (owned by story 02) and was not touched here; reporting per instructions, not fixed.
