---
story: d-dead-res-w2-01
spec: d-dead-res-w2
status: done
returned: DONE
tier: 2
worker: worker-code
wave: 1
blocked_by: []
red: required
red_reason: Рецепта SealBalm нет в Config\CraftRecipes
red_verified_at: 2026-11-11T15:34:36Z 7f64d6ba6d72d1b2306a72d339a4917e7a90f5eb745837117ab0425ca9611b1b
---

# Данные и рецепты пяти лекарств хвоста

## Goal
В игре есть пять новых лекарств первого верстака (`SealBalm`, `ThermalCulture`, `CaveTallow`, `DesertSalt`,
`PainkillerShot`, состав — таблица `plan.md`): строки `crafted_items` (`type='drug'`) и `tasks` (минуты), лечение в
`GameSettings`, рецепты в `Config\CraftRecipes`, ключи в `Config\CraftCatalog` general/medicine, кнопки в
«⚕️ Медикаменты» (последний ряд из трёх), обе карты `Worker`, полка `Config\Consumables::MEDICINE`.

## Requirements
> Бери задачу из роудмепа далее
> Волна 2 целиком (Рекомендую)
> 1. Все 14 ресурсов хвоста — Лед, Фоссилии, Пустынный агат, Миражные кристаллы, Масло тюленя, Арктические цветы, Обсидиан, Термофильные микроорганизмы, Природный газ, Сталактиты и сталагмиты, Драгоценные камни, Подземные животные, Яд животных, Медицинское сырьё — входят каждый хотя бы в один из пяти новых рецептов первого верстака (Тюлений бальзам, Термальная закваска, Пещерный жир, Пустынная соль, Обезболивающий укол), и рецепт виден и в боте, и в `/play`.
> 2. Новые предметы — лекарства `type='drug'` на уже работающем пути Аптечки (полка «Медикаменты», `Config\Consumables::MEDICINE`); лечение и выносливость читаются из `GameSettings` `medical.<snake>.heal_health` / `.heal_tired` с rationale и границами, Обезболивающий укол — 40 / 15, не выше потолка первого верстака; существующие ключи `Config\CraftRecipes`, рецепты волны 1 и `buildings.required_resources` в диффе не тронуты.
> 3. Пул добычи и запасы не трогаем: ни у одного ресурса не меняются `biome_id`, `level_required`, `rarity`, `sell_price`; ни одна миграция не пишет в `character_resources`, склад или `crafted_items_log`.
> 4. Крафт не печатает золото: для каждого из пяти рецептов цена предмета × 1.10 ≤ золото рецепта + Σ количество × `sell_price` сырья (прод-снимок 2026-11-11) — проверяется тестом.
> 5. Меню «⚕️ Медикаменты» в боте: пять новых кнопок ведут в генерик-карточку `craftPreviewT3Medical_<Key>`, ни одна кнопка не стоит одна в ряду (последний ряд — из трёх); карточка рецепта и итог крафта читаются без фото — caption несёт имя, лечение с числами из `GameSettings`, сырьё с количеством и наличием, время.

## Files
- app/Database/Migrations/2026-12-24-100000_DeadResW2AddTailMedicineItems.php
- app/Database/Migrations/2026-12-24-100010_DeadResW2SeedTailMedicineGameSettings.php
- app/Config/CraftRecipes.php
- app/Config/CraftCatalog.php
- app/Config/Consumables.php
- app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchGeneral/Medical/MedicalCraft1Action.php
- app/Controllers/Worker.php
- tests/unit/Config/CraftRecipesDeadResourcesWave2Test.php

## Non-goals
- Не трогать рецепты волны 1 и прочие существующие ключи `CraftRecipes`, `buildings`, `resources`.
- Не править `UsePharmacyAction` / `MedicalRecipePreviewT3Action` — они уже data-driven; не вписывать лекарства в лечение ран.
- Не переносить количества рецептов в `GameSettings` (D8).

## Map slice
`memory/map/craft.md` — Entry points, Gotchas («не печатает золото», `CraftCardHelper::available()`, ADR-182 `craft_again_callback`).

## Acceptance criteria
- [ ] Миграции идемпотентны (skip по `name_eng` / `tasks.name` / ключу), `php -l` чист, не пишут `character_resources`.
- [ ] Тест: 5 ключей в `CraftRecipes` и `CraftCatalog`; все 14 ресурсов хвоста во входах; `price × 1.10 ≤ Σ qty × sell_price` по `ITEMS` миграции; кнопки `craftPreviewT3Medical_<Key>` в меню, ни одного ряда из одной кнопки.
- [ ] Лечение читается из `medical.<snake>.heal_*` (rationale, soft/hard, default); укол 40/15.
- [ ] `CraftRecipesTest`, `CraftRecipeReachabilityTest` зелёные; `php spark migrate` локально применён.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Config/CraftRecipesDeadResourcesWave2Test.php`
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Config/CraftRecipesTest.php`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
`git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`

## Implementation notes
- Миграции `…100000` (5 `crafted_items` drug + 5 `tasks` `craft<Key>`, минуты: бальзам/закваска 6–10, жир 4–7, соль/укол 10–16) и `…100010` (10 ключей `medical.<snake>.heal_*`, границы `SHARED` волны 1: rec 0–40, hard 0–100) — по образцу волны 1, идемпотентны. Локально `php spark migrate` применил обе: 5 предметов, 10 ключей, 5 задач.
- Цены: бальзам 110 (вход 126.35), закваска 75 (84.55), жир 65 (74.10), соль 115 (128.25), укол 245 (275.50); `gold_required` нет.
- `GenericCraftCompletionHandler::healLine()` и `UsePharmacyAction` data-driven по `medical.<snake>` — не тронуты; `MedicalRecipePreviewT3Action` нейтральна к верстаку с волны 1.
- Меню: два новых ряда — 2 и 3 кнопки (17 всего); у соли эмодзи 🧂 вместо 🏜 из превью гриля — соль читается сразу.
- Арт `uploads/telegram/craft/<snake>.jpg` прописан в рецептах, файлы делает story 02; до них карточка падает на заглушку через `is_file`.

## Findings

## Red receipt
- command: vendor/bin/phpunit --no-coverage --no-progress tests/unit/Config/CraftRecipesDeadResourcesWave2Test.php
- exit: 1
- tests: 7f64d6ba6d72d1b2306a72d339a4917e7a90f5eb745837117ab0425ca9611b1b (tests/unit/Config/CraftRecipesDeadResourcesWave2Test.php)
- impl: 7aab3df23740433af28283669bb5694d2749ae75e9a2ba1eb74de1bba1c52a8a
- stamped: 2026-11-11T15:34:36Z

```
Рецепта SealBalm нет в Config\CraftRecipes
Рецепта SealBalm нет в Config\CraftRecipes
Рецепта SealBalm нет в Config\CraftRecipes
        $logEntry = $this->craftedItemsLogModel->getItemByNameEngAndCharacterId($itemNameEng, $characterId);\n
        return $logEntry ? (int) $logEntry['quantity'] : 0;\n
    }\n
}\n
' [UTF-8](length: 6640) contains "'craftPreviewT3Medical_SealBalm'" [ASCII](length: 32).

C:\laragon\www\mmorpg\tests\unit\Config\CraftRecipesDeadResourcesWave2Test.php:198

[37;41mFAILURES![0m
[37;41mTests: 7[0m[37;41m, Assertions: 7[0m[37;41m, Failures: 7[0m[37;41m, PHPUnit Deprecations: 1[0m[37;41m.[0m
```
