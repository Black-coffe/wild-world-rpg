---
story: s2-conditional-writes-14
status: done
returned: DONE
worker: worker-code
model: opus
wave: 6
blocked_by: []
---

# Repair round 3

## Goal
Make the asks and findings council round 3 left RED pass, and change nothing else.

## Requirements
No brief ask is RED in round 3 - the findings below are the whole task.

## Findings
1. tests/database/PlayControllerTest.php [regression] the full suite `vendor/bin/phpunit --no-coverage --no-progress` must exit 0 on a `wildworld_tests` no other process touches (ask 15): this run exits 2 (Tests: 5062, Errors: 6, Failures: 0) on 5 PlayControllerTest and 1 PlayInboxBannerTest errors that are all schema-state (`Table 'game_settings' already exists` at 00:48+ then `wildworld_tests.game_settings doesn't exist` in the same class, `telegram_users doesn't exist`, `Unknown column 'default_value_text'`, `biomes already exists`), none in a file the diff touches, both files green standalone right after (`vendor/bin/phpunit --no-coverage --no-progress tests/database/PlayControllerTest.php` -> OK 23 tests; `... tests/database/PlayInboxBannerTest.php` -> OK 9 tests); the diff 5f60495e..1880a060 changes one code line (a seed value in tests/unit/TaskHandlers/CompleteRobotGatheringBaseTest.php:160), so no base-side `file:line` makes this a true regression; the measured cause is the round-3 opus seat running 27 per-file PHPUnit invocations against the same shared `wildworld_tests` inside this suite's window (its report .vulyk/reports/s2-conditional-writes/round-3/opus.attempt-1.md written 00:56:01, suite ran 00:48:15-00:56:40) - the round-2 review already required the repair's full-suite proof to come from a clean, un-shared `wildworld_tests`; reported as the rules require (non-zero suite = FAIL/BLOCK), and `scripts/human-check.sh` is the owner's override if a solo clean run is green.

## Files
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/Greenhouse/PlantCropActionStart.php
- tests/database/PlantCropSeedConditionalWriteTest.php
- app/Services/NPC/NpcInteractionService.php
- tests/database/NpcSpawnNamedLockTest.php
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/Robots/RobotRepairConfirmAction.php
- app/Controllers/Telegram/Commands/Actions/Drone/RepairDroneRunAction.php
- app/Services/Player/FactionProjectService.php
- tests/database/GoldDecrementIfAtLeastTest.php
- app/Services/Settlement/RuinLootService.php
- app/Models/CharacterRuinLootModel.php
- app/Controllers/Telegram/Commands/Actions/AnomalyLootAction.php
- tests/database/RuinLootCooldownReserveTest.php
- app/Controllers/Telegram/Commands/Actions/UsePharmacyAction.php
- tests/database/PharmacyDoseConditionalWriteTest.php
- app/Services/Events/IntentApplier.php
- tests/database/IntentApplierRelativeDeltasTest.php
- tests/unit/Services/Events/IntentApplierTest.php
- app/Services/Player/VehicleActivationService.php
- tests/database/VehicleActivationNonTransportTest.php
- app/TaskHandlers/DeathRouletteHandler.php
- tests/database/DeathRouletteNamedLockTest.php
- app/Controllers/Telegram/Commands/Actions/FinishTaskAction.php
- app/TaskHandlers/CompleteRobotGatheringHandler.php
- app/TaskHandlers/CompleteRobotExplorationHandler.php
- tests/database/FinishTaskInterruptOnceTest.php
- tests/database/RobotGatheringRelativeAwardTest.php
- app/Services/Player/Trade/ResourceTradeService.php
- tests/database/BulkSellIncreaseGoldRefusalTest.php
- tests/database/BaseStorageWithdrawAllOnceTest.php
- app/Controllers/Telegram/Commands/Actions/Camp/TeleportUseAction.php
- app/Services/Player/TeleportUse/TeleportItemConsumer.php
- app/Services/Player/TeleportUse/TeleportExecutor.php
- tests/database/TeleportUseTransactionTest.php
- tests/unit/Transport/VehicleActivationServiceTest.php
- tests/unit/Transport/DeathBreaksVehicleTest.php
- app/Controllers/Telegram/Commands/Actions/NPC/NpcActionChoiceAction.php
- app/Controllers/Telegram/Commands/Actions/NPC/NpcDialogueAction.php
- tests/unit/TaskHandlers/CompleteRobotGatheringBaseTest.php

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/database/PlantCropSeedConditionalWriteTest.php && vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
`vendor/bin/phpunit --no-coverage --no-progress tests/database/NpcSpawnNamedLockTest.php && vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
`vendor/bin/phpunit --no-coverage --no-progress tests/database/GoldDecrementIfAtLeastTest.php && vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
`vendor/bin/phpunit --no-coverage --no-progress tests/database/RuinLootCooldownReserveTest.php && vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
`vendor/bin/phpunit --no-coverage --no-progress tests/database/PharmacyDoseConditionalWriteTest.php && vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
`vendor/bin/phpunit --no-coverage --no-progress tests/database/IntentApplierRelativeDeltasTest.php && vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
`vendor/bin/phpunit --no-coverage --no-progress tests/database/VehicleActivationNonTransportTest.php && vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
`vendor/bin/phpunit --no-coverage --no-progress tests/database/DeathRouletteNamedLockTest.php && vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
`vendor/bin/phpunit --no-coverage --no-progress tests/database/FinishTaskInterruptOnceTest.php && vendor/bin/phpunit --no-coverage --no-progress tests/database/RobotGatheringRelativeAwardTest.php && vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
`vendor/bin/phpunit --no-coverage --no-progress tests/database/BulkSellIncreaseGoldRefusalTest.php && vendor/bin/phpunit --no-coverage --no-progress tests/database/BaseStorageWithdrawAllOnceTest.php && vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
`vendor/bin/phpunit --no-coverage --no-progress tests/database/TeleportUseTransactionTest.php && vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Transport/VehicleActivationServiceTest.php && vendor/bin/phpunit --no-coverage --no-progress tests/unit/Transport/DeathBreaksVehicleTest.php && vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/TaskHandlers/CompleteRobotGatheringBaseTest.php && vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes
- No app code changed: finding 1 is the shared `wildworld_tests` being used by two processes at once, not a code defect.
- tests/database/PlantCropSeedConditionalWriteTest.php: the character and telegram user now get explicit random ids. Surprise: on the DB state the suite leaves behind, `characters` had been recreated (id restarts at 1) while an orphan `character_resources` row (char 1, BerrySeeds, qty 4) let the conditional decrement succeed, so the first close-story run failed; it was a non-hermetic test, not the action.
- tests/database/GoldDecrementIfAtLeastTest.php: the character_factions insert no longer writes `joined_at` (the service never reads it). A narrower `character_factions` left by another test lacked the column, so close-story run 2 errored.
- Solo full suite `vendor/bin/phpunit --no-coverage --no-progress` re-run 2026-10-29 with no other PHPUnit process started by this worker: exit 0, Tests: 5062, Assertions: 47302, Skipped: 10, 0 errors, 0 failures, 08:13.
- PlayControllerTest / PlayInboxBannerTest green inside that solo run, which confirms the round-3 errors were schema-state collisions.
