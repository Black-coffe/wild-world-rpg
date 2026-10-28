---
story: s2-conditional-writes-13
status: todo
returned:
worker: worker-code
model: opus
wave: 5
blocked_by: []
red: required
red_reason:
---

# Repair round 2

## Goal
Make the asks and findings council round 2 left RED pass, and change nothing else.

## Requirements
> 14. Вердикты: `/guide` — нет, совет — нет (новой игровой поверхности нет, меняются только тексты отказа гонок);

## Findings
ASK 14: RED - verdicts + tech-writing + ROADMAP - run: grep -n S2 ROADMAP.md saw: S2 still present at line 57 (§2 queue item 1) and line 164 (§4 Трек S); `sed '/^## §6/,$p' | grep S2` → 0 lines. Tech-writing notes: 14/15 touched files carry s2-conditional-writes/ADR-181 notes (ExplorationHandler has a F24 note at :77). ROADMAP half of the ask not done at this commit.
1. tests/unit/TaskHandlers/CompleteRobotGatheringBaseTest.php:191 [regression] the full suite `vendor/bin/phpunit --no-coverage --no-progress` must be green (ask 15): it exits 2 (Tests: 5062, Errors: 4, Failures: 1) and the deterministic red is `testCompletionDigsAroundSavedLaunchBase` asserting `completed` but reading `in_work` (same failure standalone: `vendor/bin/phpunit --no-coverage --no-progress tests/unit/TaskHandlers/CompleteRobotGatheringBaseTest.php` -> Tests: 5, Failures: 1); on the base `git show 8200f8c5:app/TaskHandlers/CompleteRobotGatheringHandler.php` line 99 writes `['status' => 'completed']` and the test (unchanged on the branch) passes there, while the head handler at app/TaskHandlers/CompleteRobotGatheringHandler.php:100 stops writing the status per ask 10 and calls the handler directly without the Worker claim (app/Controllers/Worker.php:103-104) - the ask-10 change must leave this pre-existing test green (seed the task already claimed `completed` as Worker does, or assert through Worker), not leave it asserting the removed write; this red was masked in round 1 only by the suite TIMEOUT.

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
