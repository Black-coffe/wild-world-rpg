---
story: s2-conditional-writes-12
status: todo
returned:
worker: worker-code
model: opus
wave: 4
blocked_by: []
red: required
red_reason:
---

# Repair round 1

## Goal
Make the asks and findings council round 1 left RED pass, and change nothing else.

## Requirements
> 1. F4 — посадка (`PlantCropActionStart`) списывает семя условием «количество ≥ 1» с проверкой затронутых строк; при отказе
> 14. Вердикты: `/guide` — нет, совет — нет (новой игровой поверхности нет, меняются только тексты отказа гонок);
> 15. Регресс: полный набор PHPUnit зелёный, phpstan L9 зелёный; пофайловый набор `tests/exploit-poc/` даёт тот же список

## Findings
ASK 14: RED - verdicts/tech-notes/ROADMAP (S2 removed from §2/§4, row in §6) - run: grep -n "S2" ROADMAP.md saw: §2 line 57 still «1. **S2** — пакет условной записи…», §4 line 164 still «- **S2** · Tier 2 · Пакет условной записи…», no S2 row under §6 «Журнал» (line 293+). Tech-writing notes and /guide/GameSettings/WipeManifest verdicts not judged (would need source).
ASK 14: RED - verdicts, tech-writing notes, ROADMAP §2/§4 removal + §6 row - run: grep -n S2 ROADMAP.md saw: S2 still at ROADMAP.md:57 (§2 queue item 1) and ROADMAP.md:164-170 (§4 track S), and no S2 row in §6 Журнал; tech-writing notes live outside COURT (vault) and cannot be verified here; guide/GameSettings/WipeManifest «нет» verdicts consistent with the code (no new tables/columns, refusal texts only)
ASK 15: RED - full PHPUnit green, phpstan green, exploit-poc same reds - run: phpstan saw: [OK] No errors; run: phpunit tests/unit/Transport saw: Tests 208, Failures 9: 8 in tests/unit/Transport/VehicleActivationServiceTest.php (fixtures seed name_eng "cart"/"MedKit", which F22 now rejects: activate() returns false, pointer stays null) + tests/unit/Transport/DeathBreaksVehicleTest.php:97 (same "cart" fixture); both files are in the App suite, so the full suite cannot be green; run: exploit-poc per file saw: reds only in GapAuditTest (1, relocation task), MultiAccountTest (3), TaskExclusivityTest (3), zero errors; none is an S2 F-code, but the before-spec baseline is not comparable blind
1. tests/database/PlantCropSeedConditionalWriteTest.php:193 [ask 1] the ask-1 test must build its own schema: it instantiates `App\Database\Migrations\CreateTasksTable` without `require_once APPPATH . 'Database/Migrations/2024-03-22-111828_CreateTasksTable.php'` (migration files are not autoloadable), so on a stand where `tasks` is absent the story's own verification errors `Class "App\Database\Migrations\CreateTasksTable" not found` before any assertion - repro: `vendor/bin/phpunit --no-coverage --no-progress tests/database/PlantCropSeedConditionalWriteTest.php` on `wildworld_tests` without a `tasks` table (current local stand) -> `Tests: 1, Errors: 1`; it only passes inside the full suite because `tests/database/BuildOrderServiceTest.php:40` and siblings load that file first.

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
