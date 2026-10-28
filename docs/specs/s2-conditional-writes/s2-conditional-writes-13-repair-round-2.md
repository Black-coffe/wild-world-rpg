---
story: s2-conditional-writes-13
status: todo
returned:
worker: worker-code
model: opus
wave: 5
blocked_by: []
---

# Repair round 2

## Goal
Make the asks and findings council round 2 left RED pass, and change nothing else.

## Requirements
> 14. Вердикты: `/guide` — нет, совет — нет (новой игровой поверхности нет, меняются только тексты отказа гонок);

## Findings
ASK 14: RED - verdicts + tech-writing + ROADMAP - run: grep -n S2 ROADMAP.md saw: S2 still present at line 57 (§2 queue item 1) and line 164 (§4 Трек S); `sed '/^## §6/,$p' | grep S2` → 0 lines. Tech-writing notes: 14/15 touched files carry s2-conditional-writes/ADR-181 notes (ExplorationHandler has a F24 note at :77). ROADMAP half of the ask not done at this commit.
1. tests/unit/TaskHandlers/CompleteRobotGatheringBaseTest.php:191 [regression] the full suite `vendor/bin/phpunit --no-coverage --no-progress` must be green (ask 15): it exits 2 (Tests: 5062, Errors: 4, Failures: 1) and the deterministic red is `testCompletionDigsAroundSavedLaunchBase` asserting `completed` but reading `in_work` (same failure standalone: `vendor/bin/phpunit --no-coverage --no-progress tests/unit/TaskHandlers/CompleteRobotGatheringBaseTest.php` -> Tests: 5, Failures: 1); on the base `git show 8200f8c5:app/TaskHandlers/CompleteRobotGatheringHandler.php` line 99 writes `['status' => 'completed']` and the test (unchanged on the branch) passes there, while the head handler at app/TaskHandlers/CompleteRobotGatheringHandler.php:100 stops writing the status per ask 10 and calls the handler directly without the Worker claim (app/Controllers/Worker.php:103-104) - the ask-10 change must leave this pre-existing test green (seed the task already claimed `completed` as Worker does, or assert through Worker), not leave it asserting the removed write; this red was masked in round 1 only by the suite TIMEOUT.

Worker (round 2 repair), NEEDS_CONTEXT, no edits made:
- ASK 14 needs `ROADMAP.md` (drop S2 from §2 queue line 57 and §4 Трек S line 164, add it under §6): not in `## Files`.
- Finding 1 needs `tests/unit/TaskHandlers/CompleteRobotGatheringBaseTest.php` (seed the task `status => 'completed'` as Worker claims it, per the finding): not in `## Files`. The only in-Files alternative, restoring the status write in `CompleteRobotGatheringHandler.php`, reverts ask 10, so it is not an option.
- `red: required` cannot be met: no `## Verification` line names CompleteRobotGatheringBaseTest, and every listed test was already green, so `stamp-red` would refuse with `not red`. Story shape: add both paths to `## Files`, add `vendor/bin/phpunit --no-coverage --no-progress tests/unit/TaskHandlers/CompleteRobotGatheringBaseTest.php` to `## Verification`, and either drop `red: required` (it is a test fix plus a doc edit, so no new red exists) or keep it with the test as the sealed red (it already fails on head).

Queen decisions (2026-10-29, ответ на NEEDS_CONTEXT):
- `## Files` + `## Verification`: `tests/unit/TaskHandlers/CompleteRobotGatheringBaseTest.php` — засевать задачу уже `completed` (как клеймит `Worker`) или проверять через `Worker`; обработчик НЕ возвращать к записи статуса (ask 10).
- Ask 14 / ROADMAP — сделан Королевой отдельным коммитом в ветке до этого перезапуска; tech-writing — drone-docs (готово). Не задача воркера.
- `red: required` снят: починка существующего теста, новой логики записи нет.

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
