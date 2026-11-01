---
story: d-dead-res-01
spec: d-dead-res
status: todo
returned:
tier: 2
worker: worker-code
wave: 1
blocked_by: []
red: required
red_reason: CraftRecipesDeadResourcesTest падает — рецепта Antivenom нет / цена продажи × 1.10 больше стоимости сырья
---

# Данные и рецепты четырёх лекарств из мёртвого сырья

## Goal
В игре есть четыре новых лекарства первого верстака (`Antivenom`, `ChitinPatch`, `SulfurOintment`,
`MineralTablets`, состав — таблица `plan.md`): строки `crafted_items` (`type='drug'`) и `tasks`, лечение и время
в `GameSettings`, рецепты в `Config\CraftRecipes`, категория «medicine» в `Config\CraftCatalog`, кнопки в меню
«⚕️ Медикаменты» бота; Аптечка и Провизия их применяют. Тест держит контракт и правило «не печатает золото».

## Requirements
> дать потребителя самым накопленным
> 1. Семь самых накопленных мёртвых ресурсов — Змеиный яд, Тропические насекомые, Редкие руды, Снег, Капельники, Сера, Вулканический пепел — получают потребителя: каждый входит хотя бы в один новый рецепт на существующем верстаке, и рецепт виден и в боте, и в `/play`.
> 5. Крафт не печатает золото: для каждого нового рецепта цена продажи предмета × 1.10 ≤ золото рецепта + стоимость сырья по `sell_price` — проверяется тестом.
> 2. Новые предметы дают уже работающий эффект: это лекарства `type='drug'`, их применяют Аптечка и Провизия, лечение и выносливость читаются из `GameSettings` (`medical.<snake>.heal_health` / `.heal_tired`, с rationale и границами); старые рецепты и постройки не меняются — существующие ключи `Config\CraftRecipes` и `buildings.required_resources` в диффе не тронуты.
> 6. Media-off: карточка нового рецепта и итог крафта читаются без фото — caption несёт имя, эффект с числами из `GameSettings`, сырьё с количеством и время.
> 4. Запасы игроков не трогаем: ни одна миграция спеки не пишет в `character_resources`, склад или `crafted_items_log`.

## Files
- app/Database/Migrations/2026-12-23-100000_DeadResAddFieldMedicineItems.php
- app/Database/Migrations/2026-12-23-100010_DeadResSeedFieldMedicineGameSettings.php
- app/Config/CraftRecipes.php
- app/Config/CraftCatalog.php
- app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchGeneral/Medical/MedicalCraft1Action.php
- app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchProfessional/MedicalRecipePreviewT3Action.php
- app/Controllers/Telegram/Commands/Actions/UsePharmacyAction.php
- app/Controllers/Worker.php
- tests/unit/Config/CraftRecipesDeadResourcesTest.php

## Non-goals
- Не трогать существующие ключи `CraftRecipes`, `buildings`, `resources` (биомы, уровни, редкость), босс-лут, коллекции.
- Не заводить legacy-экраны `*Craft1Action` под каждый рецепт; не вписывать новые лекарства в лечение ран.
- Не переносить количества рецептов в `GameSettings` (долг D8).

## Map slice
`memory/map/craft.md` — Entry points, Gotchas (ADR-182 `craft_again_callback`, `CraftCardHelper::available()`, «не печатает золото»).

## Acceptance criteria
- [ ] Миграции идемпотентны (skip по `name_eng` / `tasks.name` / ключу GameSettings), `php -l` чист, не пишут `character_resources`.
- [ ] Каждая из семи — во входах хотя бы одного нового рецепта; `CraftRecipesTest`, `CraftRecipeReachabilityTest` зелёные.
- [ ] `CraftRecipesDeadResourcesTest`: 4 ключа есть в `CraftRecipes` и `CraftCatalog`; `price × 1.10 ≤ gold + Σ qty × sell_price` по сиду миграции.
- [ ] Лечение читается из `medical.<snake>.heal_*` (rationale, soft/hard границы, default) — путь `UsePharmacyAction::gsHeal`.
- [ ] Карточка рецепта в боте: имя, лечение с числами, сырьё с количеством и наличием, время — без фото; «Назад» ведёт в «⚕️ Медикаменты».

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Config/CraftRecipesDeadResourcesTest.php`
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Config/CraftRecipesTest.php`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
`git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`

## Implementation notes

## Findings
