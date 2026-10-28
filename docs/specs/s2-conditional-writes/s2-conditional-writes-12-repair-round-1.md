---
story: s2-conditional-writes-12
status: todo
returned:
worker: worker-code
model: opus
wave: 4
blocked_by: []
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

Worker (NEEDS_CONTEXT, no edits made):
- ASK 15 needs paths outside ## Files: tests/unit/Transport/VehicleActivationServiceTest.php, tests/unit/Transport/DeathBreaksVehicleTest.php. Root cause: fixtures seed name_eng 'cart' (a vehicle KEY), while real name_eng is 'LightCart' (VehicleEffectsService::NAME_ENG_TO_KEY); F22 correctly rejects it. Fix is fixture-side ('cart' -> 'LightCart'; the MedKit test at line 480 already expects rejection - verify). Loosening VehicleActivationService to accept keys would reopen F22, so the in-scope service must not change.
- ASK 14 needs ROADMAP.md (remove S2 from §2 line 57 and §4 line 164, add §6 Журнал row) and tech-writing notes in mmorpg-vault/tech-writing/ - outside ## Files, and vault/wiki edits are not a worker's (Queen / drone-docs per bindings).
- Finding 1 (require_once in tests/database/PlantCropSeedConditionalWriteTest.php) is a test-only fix: under `red: required` the gate demands an implementation change after the red receipt, and none exists for this finding. Story shape question: drop `red: required` for this repair (or seal a different test), or confirm a test-only change is acceptable.
- Re-dispatch (stamp 052591b9771f1366): story shape unchanged (## Files, red: required), the three questions above are unanswered; re-checked on disk: the 'cart' fixtures (VehicleActivationServiceTest.php:194..464, DeathBreaksVehicleTest.php:97) and the missing require_once (PlantCropSeedConditionalWriteTest.php:8,193) are still there. No edits made.

Queen decisions (2026-10-29, ответ на NEEDS_CONTEXT):
- `## Files` расширен: фикстуры `tests/unit/Transport/VehicleActivationServiceTest.php`, `DeathBreaksVehicleTest.php` ('cart' → 'LightCart', MedKit-тест — ожидание отказа; сервис F22 НЕ ослаблять) и экраны NPC `NPC/NpcActionChoiceAction.php`, `NPC/NpcDialogueAction.php` — решение владельца (3) в брифе: при отказе `fight()`/`rob()` игрок видит «NPC уже исчез.», а не «ты отступил».
- Решение владельца (4): `TeleportUseAction.php` — откат перемещения не отвечает `baseNotFound()`; честный текст сбоя перемещения, заряд на месте.
- Ask 14 НЕ задача воркера: ROADMAP — Королева на шипе, tech-writing — drone-docs (идёт). Игнорировать.
- `red: required` снят Королевой: ремонт — фикстуры, `require_once`, тексты отказа; новой логики записи нет, запечатывать нечего. Проверка — `## Verification`.

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
