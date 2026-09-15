---
story: multibase-picker-06
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

## Findings
