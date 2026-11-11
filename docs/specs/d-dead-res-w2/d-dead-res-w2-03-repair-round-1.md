---
story: d-dead-res-w2-03
status: todo
returned:
worker: worker-code
model: opus
wave: 3
blocked_by: []
red: required
red_reason: Undefined array key "SealBalm"
---

# Repair round 1

## Goal
Make the asks and findings council round 1 left RED pass, and change nothing else.

## Requirements
No brief ask is RED in round 1 - the findings below are the whole task.

## Findings
1. tests/unit/Player/ConsumableShelfTest.php:204 [regression] the 4096-unit length gate on the pharmacy shelf must cover every name in `Config\Consumables::MEDICINE`, and the five new drugs (app/Config/Consumables.php:51-56) are missing from its `productionCatalog()`, so `testFullCatalogScreenFitsTelegramTextLimit` errors with `Undefined array key "SealBalm"` (base 630a2332 has no SealBalm in MEDICINE, so the test passes there; when the five drugs are added to a scratch copy of the catalog the shelf measures 2526 units, so only the fixture is behind) - repro: `vendor/bin/phpunit --no-coverage --no-progress --filter testFullCatalogScreenFitsTelegramTextLimit tests/unit/Player/ConsumableShelfTest.php`
2. tests/database/PlayViewControllerTest.php:812 [regression] the `/play` general-bench assertion must match the medicine count the diff ships (`💊 Лекарства · 17`, from app/Config/CraftCatalog.php:50), but it still expects `· 12`, so `testCraftWalksBenchCategoryCardAndShowsTheQueue` fails (base 630a2332 CraftCatalog has 12 medicine recipes, so the test passes there) - repro: `vendor/bin/phpunit --no-coverage --no-progress --filter testCraftWalksBenchCategoryCardAndShowsTheQueue tests/database/PlayViewControllerTest.php`
- [regression] PART full-suite-unit2-round-only FAIL 62s cap 300s
- [regression] PART full-suite-db5-round-only FAIL 114s cap 300s

## Files
- app/Database/Migrations/2026-12-24-100000_DeadResW2AddTailMedicineItems.php
- app/Database/Migrations/2026-12-24-100010_DeadResW2SeedTailMedicineGameSettings.php
- app/Config/CraftRecipes.php
- app/Config/CraftCatalog.php
- app/Config/Consumables.php
- app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchGeneral/Medical/MedicalCraft1Action.php
- app/Controllers/Worker.php
- tests/unit/Config/CraftRecipesDeadResourcesWave2Test.php
- app/Config/ImageRegistry.php
- public/uploads/telegram/craft/seal_balm.jpg
- public/uploads/telegram/craft/thermal_culture.jpg
- public/uploads/telegram/craft/cave_tallow.jpg
- public/uploads/telegram/craft/desert_salt.jpg
- public/uploads/telegram/craft/painkiller_shot.jpg
- app/Database/Migrations/2026-12-24-100020_SeedTailMedicineTip.php
- GAME_DESCRIPTION.md
- ROADMAP.md
- tests/unit/Player/ConsumableShelfTest.php
- tests/database/PlayViewControllerTest.php

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Config/CraftRecipesDeadResourcesWave2Test.php`
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Player/ConsumableShelfTest.php`
`vendor/bin/phpunit --no-coverage --no-progress tests/database/PlayViewControllerTest.php`
`git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`
