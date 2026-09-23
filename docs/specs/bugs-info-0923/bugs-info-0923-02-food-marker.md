---
story: bugs-info-0923-02
spec: bugs-info-0923
status: done
returned: DONE
tier: 3
worker: worker-code
model: opus
tracer: false
wave: 1
blocked_by: []
---

# Честная пометка у food-предметов в «Крафтовых предметах»

## Goal
На экране «Крафтовые предметы» (`CraftedResourcesAction`, блок «🍲 Еда», по разведке `:148`) у каждого предмета `crafted_items.type='food'` рядом с именем стоит пометка «не применяется, выводится из обращения». Под блоком еды — строка с путём к живой еде «🎒 Инвентарь → 🥣 Провизия». Сейчас это Мясо дикого кабана, Рыбный суп, Ягодный джем (id 23/24/25), но признак — `type`, а не имена. Провизия и Аптечка читают только `type='drug'`, поэтому игрок видит еду, которую нигде нельзя применить. Вывод из обращения с компенсацией остаётся за ADR-185 (`vulyk/craft-shelf-coverage`, не влита).

## Requirements
> В крафтовых предметах есть еда, которая не отображается в аптечка-провизия
> Якщо ці баги в грі є, береш їх всі, плануєш через вулик-план і запускаєш починку, ремонт цих багів

## Files
- app/Controllers/Telegram/Commands/Actions/CraftedResourcesAction.php
- tests/unit/Craft/CraftedResourcesFoodMarkerTest.php

## Non-goals
- Без миграции данных, без смены `status`/`type` в `crafted_items`, без скрытия предметов с экрана: это решение ADR-185 и его ветки.
- Не трогать `ProvisionAction`, `PharmacyAction` и не учить их показывать food: еда не применяется, ask просит честность, а не применение.
- Не менять остальные типы и блоки экрана, их порядок и кнопки.
- Не править `CraftedItemTypeApplicationCoverageTest`: он должен остаться зелёным как есть. Покраснеет — WALL и Findings, не правка теста.
- Никаких чисел баланса в пометке.

## Map slice
`memory/map/craft.md` — Entry points (`CraftedItemsModel`), Gotchas. `memory/map/telegram.md` — Gotchas: legacy Markdown без эскейпа = тихий no-send, caption ≤1024, media-off.

## Acceptance criteria
- [ ] Для строки с `type='food'` рядом с её именем на экране есть текст «не применяется, выводится из обращения», а в блоке еды — строка «🎒 Инвентарь → 🥣 Провизия». Проверяет `CraftedResourcesFoodMarkerTest` на схеме, которую тест строит сам (по миграции).
- [ ] Строка другого типа (например, `drug`) в том же тесте выводится без пометки и без строки пути. Блоки других типов код не меняет.
- [ ] Текст полон без картинки. Если экран шлёт фото, caption после добавки ≤1024 символов при всех трёх food-предметах сразу, и это проверено тестом. Markdown-safe: пометка и путь не ломают разметку текущего `parse_mode`.
- [ ] Подписи «🎒 Инвентарь» и «🥣 Провизия» сверены с кнопками в коде. Не совпадают — INTERFACES с фактическими подписями, текст не выдумывается.
- [ ] Одиночный прогон зелёный: `vendor/bin/phpunit --no-coverage --no-progress tests/unit/Craft/CraftedResourcesFoodMarkerTest.php`. `CraftedItemTypeApplicationCoverageTest` зелёный.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes
- `CraftedResourcesAction.php`: consts `FOOD_MARKER`, `FOOD_PATH_LINE`; `line()` appends « — не применяется, выводится из обращения» for `type='food'`; food block in `renderGrouped()` ends with the path line; `renderFlat()` adds it once if any food row. The DB read moved out of `handle()` into private `loadRows()` so the test can exercise the real query; behaviour is unchanged.
- The path label differs from the story: there is no Provision button on «🎒 Инвентарь». The real route is `💊 Аптечка` (character screen, `pharmacy`) → `🍲 Провизия` (PharmacyAction, `provision`). No «🥣» exists in the code. The line reads «↳ Еда и питьё, которые работают: 💊 Аптечка → 🍲 Провизия».
- Caption ≤1024 does not apply: the screen sends plain text via `MediaSender::editTextOrSend`, not a photo. The test asserts Markdown safety instead: even `*` count and no `_`.
- `CraftedResourcesFoodMarkerTest.php` builds its schema from the two 2024-04-16 migrations with FK checks off, and needs `resetDataCache()` after raw DROP (CI4 caches listTables).
- Mutation check: blanking the marker turns the test red with 2 failures.
- `CraftedItemTypeApplicationCoverageTest` does not exist anywhere in the repo, so that AC could not be checked. The adjacent `CraftedItemTypeHeadingCoverageTest` stays green.
- The full phpunit suite was NOT run: other workers have uncommitted edits in the tree and share `wildworld_tests`, and my early runs collided with them ("table already exists"). Full phpstan shows 13 errors, all in other workers' files (`QuestsInfo.php`, `StrategicLootHandler.php`). The action file alone is clean.

## Findings
