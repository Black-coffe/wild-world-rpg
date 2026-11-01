---
story: d-dead-res-03
status: todo
returned:
worker: worker-code
model: opus
wave: 3
blocked_by: []
red: required
red_reason:
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

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Config/CraftRecipesDeadResourcesTest.php`
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Config/CraftRecipesTest.php`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
`git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`
