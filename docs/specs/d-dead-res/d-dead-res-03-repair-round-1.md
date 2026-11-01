---
story: d-dead-res-03
status: done
returned: DONE
worker: worker-code
model: opus
wave: 3
blocked_by: []
red: required
red_reason: Antivenom не на полке 💊 Аптечка
red_verified_at: 2026-11-01T21:02:30Z 85792db8c803b4908d9fc25c56d1f3f4b343c8bf70057237a0d200020d0f0643
---

# Repair round 1

## Goal
Make the asks and findings council round 1 left RED pass, and change nothing else.

## Requirements
> 2. Новые предметы дают уже работающий эффект: это лекарства `type='drug'`, их применяют Аптечка и Провизия, лечение и выносливость читаются из `GameSettings` (`medical.<snake>.heal_health` / `.heal_tired`, с rationale и границами); старые рецепты и постройки не меняются — существующие ключи `Config\CraftRecipes` и `buildings.required_resources` в диффе не тронуты.

## Findings
1. tests/database/PlayViewControllerTest.php:812 [regression] the full suite must be green: `testCraftWalksBenchCategoryCardAndShowsTheQueue` expects the /play bench to read `💊 Лекарства · 8`, but the head renders 12 because app/Config/CraftCatalog.php:50 now lists four more keys; the base catalog lists 8 (`git show c449b5b1:app/Config/CraftCatalog.php`, line 50), so the test passes on the base and fails on the head - repro: `vendor/bin/phpunit --no-coverage --no-progress --filter testCraftWalksBenchCategoryCardAndShowsTheQueue tests/database/PlayViewControllerTest.php`
1. app/Config/Consumables.php:29 [ask 2] the four new drugs (Antivenom, ChitinPatch, SulfurOintment, MineralTablets) must be on the 💊 Аптечка shelf, which means listing them in `Consumables::MEDICINE`. They are in neither catalog, so `shelfOf()` sends them to Провизия: PharmacyAction.php:51 does not list them, and a player who holds only these drugs sees "у тебя нет медицинских предметов". The tip (2026-12-23-100020_SeedFieldMedicineTip.php:763) tells players to take them from the 💊 Аптечка screen. `ConsumablesCatalogMatchesDbTest::testEveryDrugRowIsClassifiedInExactlyOneCatalog` fails on every DB where the migration has run (testbot/CI). It only skips on the empty local test DB; the dev DB `mmorpg` already holds all four `type='drug'` rows - repro: `grep -c "Antivenom\|ChitinPatch\|SulfurOintment\|MineralTablets" app/Config/Consumables.php` gives 0

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
- app/Config/ImageRegistry.php
- public/uploads/telegram/craft/antivenom.jpg
- public/uploads/telegram/craft/chitin_patch.jpg
- public/uploads/telegram/craft/sulfur_ointment.jpg
- public/uploads/telegram/craft/mineral_tablets.jpg
- app/Database/Migrations/2026-12-23-100020_SeedFieldMedicineTip.php
- GAME_DESCRIPTION.md
- ROADMAP.md
- app/Config/Consumables.php
- tests/database/PlayViewControllerTest.php
- tests/unit/Player/ConsumableShelfTest.php

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Config/CraftRecipesDeadResourcesTest.php`
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Config/CraftRecipesTest.php`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
`git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`

## Red receipt
- command: vendor/bin/phpunit --no-coverage --no-progress tests/unit/Config/CraftRecipesDeadResourcesTest.php
- exit: 1
- tests: 85792db8c803b4908d9fc25c56d1f3f4b343c8bf70057237a0d200020d0f0643 (tests/unit/Config/CraftRecipesDeadResourcesTest.php)
- impl: 1da9666038b7f5f9db12e959e629601c5d591db54f9ad561333840ba21afa0aa
- stamped: 2026-11-01T21:02:30Z

```
There was 1 failure:

1) Tests\Unit\Config\CraftRecipesDeadResourcesTest::testEachNewDrugStandsOnThePharmacyShelf
Antivenom не на полке 💊 Аптечка
Failed asserting that an array contains 'Antivenom'.

C:\laragon\www\mmorpg\tests\unit\Config\CraftRecipesDeadResourcesTest.php:196

[37;41mFAILURES![0m
[37;41mTests: 8[0m[37;41m, Assertions: 200[0m[37;41m, Failures: 1[0m[37;41m, PHPUnit Deprecations: 1[0m[37;41m.[0m
```

## Implementation notes
- Finding 1 (регрессия `/play`): `PlayViewControllerTest` ждёт `💊 Лекарства · 12` — 8 прежних + 4 новых в `CraftCatalog`.
- Finding 2 (ask 2, полка): `Config\Consumables::MEDICINE` += `Antivenom`, `ChitinPatch`, `SulfurOintment`,
  `MineralTablets`. Красный тест — `CraftRecipesDeadResourcesTest::testEachNewDrugStandsOnThePharmacyShelf`.
- Та же правка уронила `ConsumableShelfTest::testFullCatalogScreenFitsTelegramTextLimit` (каталог фикстуры не знал
  новых имён). Добавил 4 строки с прод-формой; полный экран полки из 19 лекарств укладывается в 4096. Файл внесён
  в `## Files`, как и `Consumables.php` и `PlayViewControllerTest.php`: Findings называют их прямо.
- Minor с ревью: строка Plan deltas про время в минутах добавлена в `plan.md`. Числа лечения в итоге крафта
  (`GenericCraftCompletionHandler`) не трогал: ask 6 про карточку рецепта закрыт, итог несёт имя, количество и зону, как
  у всех рецептов. Это общий handler всех крафтов, вне файлов спеки.
- Полный набор локально: 5083 теста, 0 падений, 10 skipped (08:44). phpstan чист.
