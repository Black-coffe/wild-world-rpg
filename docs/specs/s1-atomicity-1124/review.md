# S1 atomicity: finish the parked spec on thin VULYK (review)

Review split by area (owner's order: «ревью по находкам RED, по частям»): five `vulyk:reviewer` passes on Fable,
run in parallel on the same base/head, each judging only its asks. The round verdict is BLOCK if any pass blocks.

## Round 1 - 2026-11-24 - head a2649ae0d1630dcc8e568487196d8060dbba8633

Base `e9a70a5127bc4d6e0e01226068294ea44c912dc9`. Round verdict: **BLOCK** (passes b and e).

### Pass a — 🔒 slot (asks 2, 3, 10): PASS

```
VERDICT: PASS
TESTS: vendor/bin/phpunit --no-coverage --no-progress <file>, 22 single files run one at a time -> pass, 138 tests, 0 failures (ExclusiveTaskSlotConstraintTest 10, S1ExclusiveSlotOnRealSchemaTest 4, RobotGatheringStartSlotTest 3, StartRobotGatheringBaseTest 11, FieldTaskExclusiveSlotTest 5, CraftExclusiveSlotRefusalTest 3, ExclusiveTaskLockTest 10, CraftQueueCoreTest 9, MarchServiceTest 8, MarchingTaskHandlerTest 12, BuildOrderServiceTest 9, CraftOrderServiceTest 12, PoolAdoptionRepairUpgradeTest 14, PlantCropSeedConditionalWriteTest 1, MarchMiniEventServiceTest 7, BaseRelocationCompletionHandlerTest 2, GatherActionFastStartTest 5, GatherActionStaminaCostTest 3, WebCraftStartTailsTest 5, BuildBotParityTest 2, RelocationConfirmOutcomeTest 3). No full-suite part run, per dispatch. php -l on every changed file under app/ is clean.

## Asks
1. not in this pass
2. delivered: generated column `exclusive_slot` + `UNIQUE uq_ct_exclusive_slot`, app/Database/Migrations/2026-12-25-100000_S1CharacterTasksExclusiveSlot.php:65-71; two concurrent starts on two connections let exactly one through, tests/database/ExclusiveTaskSlotConstraintTest.php:104; parallel and paused/queued/completed rows covered at :137 and :148, and on the real FK schema in tests/database/S1ExclusiveSlotOnRealSchemaTest.php:96-123
3. delivered: no insert/save of a `character_tasks` row left in app/ outside app/Services/Tasks/ActiveTasksService.php:70 (grep over every file naming the table or model); moves into `in_work` go through `moveToInWork()` :113 (MarchService.php:318, MarchMiniInvestigateAction.php:128, CraftQueueService.php:261); every caller answers `Refused` with `slotTakenText()` :151 and writes the task row before spending or inside a rolled-back transaction
4. not in this pass
5. not in this pass
6. not in this pass
7. not in this pass
8. not in this pass
9. not in this pass
10. delivered: app/Controllers/Telegram/Commands/Actions/Camp/Buildings/Robots/StartRobotGatheringAction.php:220 inserts through `insertExclusiveTaskRow()` before the durability write at :243; refusal sends the ADR-167 text at :233-240; RobotGatheringStartSlotTest (3) and StartRobotGatheringBaseTest (11) green
11. not in this pass
12. not in this pass
13. not in this pass

## Findings
- [minor] [ask 3] A gather start racing a 🔒 craft start by the same character deadlocks instead of refusing, app/Controllers/Telegram/Commands/Actions/GatherAction.php:187-217 against app/Services/Craft/CraftOrderService.php:296. Gather now inserts the task row and then locks the `characters` row (`deductTiredness` -> `CharacterStatsService::mutate`, FOR UPDATE); craft locks `characters` first and inserts last. Reproduced with two sessions on a throwaway schema with the same column and index: the craft-order session got ERROR 1213, one `in_work` row remained. In the app the loser gets «Ошибка при создании задачи крафта. Попробуйте ещё раз.» (TX_FAILED) or an uncaught DatabaseException in gather, not the ADR-167 text; nothing is spent and the invariant holds. Smallest fix: in GatherAction run `SELECT id FROM characters WHERE id = ? FOR UPDATE` right after `transBegin()`, before `createGatherTask()`. Test: two connections, craft holds the `characters` lock, gather starts, craft inserts; assert the loser's outcome is BUSY/`slotTakenText`, not TX_FAILED or an exception.
- [minor] [correctness] A queued 🔒 craft can be stranded silently, app/Services/Craft/CraftQueueService.php:266-268. `promoteNext()` runs only from the same recipe's completion (app/TaskHandlers/Craft/GenericCraftCompletionHandler.php:276, after the Telegram notify at :273). If the player starts another 🔒 task between the Worker's claim and the promotion, `moveToInWork()` returns `Refused`, the row stays `queued`, no message is sent and nothing promotes it later unless the same recipe completes again or the player cancels (refund works). Smallest fix: on `Refused` tell the player the queue is waiting, or retry promotion when a 🔒 task of that character completes. Test: gather `in_work`, craft X `queued`; after gather completes, assert X is `in_work` or the player was notified.
- [minor] [correctness] Mini-event reward is still paid when the resume lost a race, app/Controllers/Telegram/Commands/Actions/MarchMiniInvestigateAction.php:128 (`Missing` is treated as success, then `investigate()` awards at :65). Two concurrent taps on «Осмотреть» both award. Behaviour predates this branch (the old update was unconditional); the new call already knows the answer. Smallest fix: award only on `WriteOutcome::Applied`. Test: call resume twice for one paused row, assert the award is granted once.

## Past findings (round 2 and later)
(round 1 of this pass, none)
```

Reviewer's note: checkout verified at `a2649ae0` clean before and after; MySQL started twice for single-file runs
and shut down both times; the scratch schema for the lock-order reproduction was dropped; the brief has no changes
after `plan(s1-atomicity-1124): approved`.

### Pass b — resources (asks 4, 5, 13): BLOCK

```
VERDICT: BLOCK
TESTS: not run (dispatch forbids starting MySQL or running tests in this pass; tests were read, not executed)

## Asks
1. not in this pass
2. not in this pass
3. not in this pass
4. delivered: UNIQUE `uq_cr_char_resource (id_characters, id_resources)` in C:\laragon\www\mmorpg\app\Database\Migrations\2026-12-25-100020_S1CharacterResourcesUnique.php:48-49; `increaseResources` and `addOrIncreaseResource` both go through one `INSERT ... ON DUPLICATE KEY UPDATE quantity = quantity + ?` in C:\laragon\www\mmorpg\app\Models\CharacterResourceModel.php:45-48,103-106,115-130; proved by C:\laragon\www\mmorpg\tests\database\CharacterResourceAtomicWriteTest.php:146-199 (second connection interleaved on the first query; fails on read-then-write).
5. delivered: `deductResource`, `deductResourceForCraft`, `deductResourceByName`, `decreaseQtyById` all end in `UPDATE ... SET quantity = quantity - ? WHERE id = ? AND quantity >= ?` (CharacterResourceModel.php:152-228 -> C:\laragon\www\mmorpg\app\Services\Db\ConditionalWriteService.php:143-146); signatures and bool returns unchanged; the one `decreaseQtyById` caller (C:\laragon\www\mmorpg\app\Services\Player\Death\LootProcessor.php:166-206) now takes min(plan, stock) and passes on what was really deducted; tests CharacterResourceAtomicWriteTest.php:212-265 and C:\laragon\www\mmorpg\tests\database\LootProcessorNoDupeTest.php.
6. not in this pass
7. not in this pass
8. not in this pass
9. not in this pass
10. not in this pass
11. not in this pass
12. not in this pass
13. delivered: both named writers credit through `CharacterResourceModel::addOrIncreaseResource()` — C:\laragon\www\mmorpg\app\Services\Craft\CraftQueueService.php:382-385 and C:\laragon\www\mmorpg\app\Services\Player\Gather\GatherResultPersister.php:246-253; proved by C:\laragon\www\mmorpg\tests\database\GatherCreditUpsertTest.php:101-141 and C:\laragon\www\mmorpg\tests\database\CraftQueueCoreTest.php:557-596. Six more writers of the same class that recon did not name remain; see the finding.

## Findings
- [major] [correctness; the outcome ask 13 states] Six untouched credit paths still do "SELECT the pair, then plain INSERT if absent, else UPDATE quantity = read + n" on `character_resources`:
  - C:\laragon\www\mmorpg\app\Models\ResourceModel.php:165-190 — `ResourceModel::addOrIncreaseResource`, the name-based twin, called from GenericCraftCompletionHandler:498, PlantCropCompletionHandler:101, GatherTaskHandler:391, StrategicLootHandler:137, ToolkitHandler:66/81, RuinLootService:168, MarchMiniEventService:167, ObjectCloseWarehouseAction:336
  - C:\laragon\www\mmorpg\app\Controllers\Telegram\Commands\Actions\Games\FortuneWheelAction.php:225-242
  - C:\laragon\www\mmorpg\app\Controllers\Telegram\Commands\Actions\StartGame\GatherTips.php:263-283
  - C:\laragon\www\mmorpg\app\Services\PVE\TributeService.php:942-962 (behind killswitch `tribute.enabled`)
  - C:\laragon\www\mmorpg\app\TaskHandlers\Built\HandPumpProductionHandler.php:192-212
  - C:\laragon\www\mmorpg\app\TaskHandlers\GreenhouseProductionHandler.php:326-352

  How it fails: before this branch, a credit landing between the SELECT and the INSERT produced a second row and nothing was lost. With the new UNIQUE the same interleave makes the INSERT fail with 1062, which throws `DatabaseException` (`DBDebug = true`), so the credit is lost. The window reopens every time a stack is emptied, because the debit paths delete the row. A cron handler crediting while the player's webhook credits the same resource through the new upsert (`BaseStorageService:163/206`, `CaravanBuyAction:121`, `ResourceTradeService:429`) is enough. In `HandPumpProductionHandler::handle()` the loop at :102-155 has no try/catch, so one such collision aborts water production for every remaining pump in that tick. The UPDATE branch of all six also still overwrites a concurrent upsert credit with a stale sum; that part predates the branch and the same fix closes it.

  Smallest fix: each site calls `CharacterResourceModel::addOrIncreaseResource($charId, $resourceId, $amount)`; `ResourceModel::addOrIncreaseResource` keeps its name lookup and its `false` on unknown name, then delegates.

  Test: in the style of `GatherCreditUpsertTest`, a `DBQuery` listener on the first `character_resources` query of `(new ResourceModel())->addOrIncreaseResource($charId, 'X', 4)` inserts the same pair with quantity 5 from a second connection. Assert no exception and exactly one row with quantity 9. Today it throws the duplicate-key error (or leaves 4 when the row pre-exists); after the fix it gives 9. Same assertion for `HandPumpProductionHandler::addWaterToCharacter` and `GreenhouseProductionHandler::addResourceToCharacter`.
```

### Pass c — quests (ask 1): PASS

```
VERDICT: PASS
TESTS: vendor/bin/phpunit --no-coverage --no-progress <files> -> not run (dispatch forbids running tests or starting MySQL in this pass: parallel passes share the test DB); verdict rests on reading the code and the tests

## Asks
1. delivered: UNIQUE (quest_id, character_id) is added by C:\laragon\www\mmorpg\app\Database\Migrations\2026-12-25-100010_S1QuestStepsUnique.php:45 (fails with a message on duplicates, deletes nothing); QuestStartService::claimFirstStep() writes through insertUnique() and maps Refused to "already started" (C:\laragon\www\mmorpg\app\Services\Quest\QuestStartService.php:144-158); NpcInteractionService::acceptOfferedQuest() goes through claimFirstStep() (C:\laragon\www\mmorpg\app\Services\NPC\NpcInteractionService.php:290); the onboarding root step also goes through insertUnique() (C:\laragon\www\mmorpg\app\Services\Onboarding\OnboardingChainService.php:156). The proof is C:\laragon\www\mmorpg\tests\database\QuestStepsUniqueStartTest.php:122, NpcQuestAcceptUniqueTest.php:113 and OnboardingChainInsertStepUniqueTest.php:110: each wedges a second-connection insert after the read gate and asserts one row, no exception and a refusal, on a schema built by the migration's up().
2. not in this pass
3. not in this pass
4. not in this pass
5. not in this pass
6. not in this pass
7. not in this pass
8. not in this pass
9. not in this pass
10. not in this pass
11. not in this pass
12. not in this pass
13. not in this pass

## Findings
- [minor] [ask 1] Three quest_steps writers in app/ still use plain QuestStepsModel::insert(), not insertUnique(): QuestChainService::advanceChain() (C:\laragon\www\mmorpg\app\Services\Quest\QuestChainService.php:212), QuestChainService::chooseBranch() (same file:414) and StrategicLootHandler::checkStrategicCaptureQuest() (C:\laragon\www\mmorpg\app\TaskHandlers\Objects\StrategicLootHandler.php:275). With DBDebug=true a duplicate pair there would now throw DatabaseException instead of being refused. I found no realistic path to it today: chooseBranch() checks and inserts under the same `SELECT ... FOR UPDATE` character lock as claimFirstStep(); the other two run only from cron task handlers, which the Worker flock (C:\laragon\www\mmorpg\app\Controllers\Worker.php:42-58) serialises; and player-startable roots have no prerequisite, so they never overlap advanceChain()'s targets. The one theoretical overlap is a LEGACY_STARTS quest whose prerequisite is completed by QuestObjectiveHandler, tapped in the gap between advanceChain()'s read and insert. Smallest fix: route the three inserts through ConditionalWriteService::insertUnique() and treat Refused as "already there". Test: wedge a second-connection insert of the next-chain pair after advanceChain()'s `FROM quest_steps` read and assert no exception and one row (throws 1062 now).
- [minor] [correctness] Unverified, MySQL not run: S1QuestStepsUnique::down() (C:\laragon\www\mmorpg\app\Database\Migrations\2026-12-25-100010_S1QuestStepsUnique.php:51) may fail on rollback. quest_steps has a foreign key on quest_id whose index MySQL created implicitly, and MySQL may silently drop that index once the new UNIQUE (quest_id, ...) can serve the key; `DROP INDEX uq_qs_quest_character` would then be refused as "needed in a foreign key constraint". No test calls down(). Smallest fix: in down(), add a plain index on quest_id before dropping the UNIQUE. Test: run up() then down() on the migration-built schema and assert no exception and the index gone.

## Past findings (round 2 and later)
(round 1: none)
```

### Pass d — repair (ask 9): PASS

```
VERDICT: PASS
TESTS: vendor/bin/phpunit --no-coverage --no-progress tests/unit/Player/PoolAdoptionRepairUpgradeTest.php -> pass, 14 tests, 22 assertions (MySQL reachable on the second run). Not run: the pair with tests/unit/TaskHandlers/GreenhouseProductionWaterTest.php and the unit2 part (Greenhouse is a DB test that drops and creates tables in the shared test DB; the dispatch forbids DB tests in this pass).

## Asks
1. not in this pass
2. not in this pass
3. not in this pass
4. not in this pass
5. not in this pass
6. not in this pass
7. not in this pass
8. not in this pass
9. delivered: deduction runs first, then the slot insert through `insertExclusiveTaskRow()`, inside one transaction; a non-`Applied` insert rolls back and returns `false`, and the caller answers with the ADR-167 text, C:\laragon\www\mmorpg\app\Controllers\Telegram\Commands\Actions\Craft\Repair\RepairCraftedItemAction.php:468-491 and :264-273. The test listens to `DBQuery` instead of spying on the model, C:\laragon\www\mmorpg\tests\unit\Player\PoolAdoptionRepairUpgradeTest.php:220-252. The "unit2 green with the pair" clause is confirmed by mechanism only, not by running the pair.
10. not in this pass
11. not in this pass
12. not in this pass
13. not in this pass

## Findings
- [minor] [correctness] The lost-race test accepts any `\RuntimeException`, C:\laragon\www\mmorpg\tests\unit\Player\PoolAdoptionRepairUpgradeTest.php:244. `transStart()` sits outside the method's `try`, and a connect failure is a `DatabaseException`, which extends `\RuntimeException`; so with MySQL down the test would go green without reaching the race (by reading, not reproduced). Smallest fix: assert the caught message is the pool's ("Недостаточно ресурса"). Test: with the DB unreachable, `testPayAndScheduleRepairDoesNotInsertTaskWhenResourceRaceFails` fails instead of passing.
```

Reviewer's note: ask 9 was checked through a scratch test outside the repo (deleted) driving the real
`payAndScheduleRepair()` through a fake DB driver: at head a lost race sends zero SQL and leaves `transStatus` true;
with the order swapped the listener captures the `INSERT INTO character_tasks`. A second scratch probe on the real
shared connection sent one `INSERT` into `character_tasks` in `wildworld_tests`, which failed (table absent); nothing
was written. Checkout still at `a2649ae0`, clean.

### Pass e — migrations, import, docs (asks 6, 7, 8, 11, 12): BLOCK

```
VERDICT: BLOCK
TESTS: vendor/bin/phpstan analyse --memory-limit=512M --no-progress -> pass, no errors; php -l over all migrations -> pass, 0 syntax errors; ten phpunit parts -> not run (dispatch forbids DB tests in this pass, parallel passes share the test DB)

## Asks
1. not in this pass
2. not in this pass
3. not in this pass
4. not in this pass
5. not in this pass
6. partly: all three migrations count violations and throw with table, count and examples without deleting rows (C:/laragon/www/mmorpg/app/Database/Migrations/2026-12-25-100000_S1CharacterTasksExclusiveSlot.php:55, 2026-12-25-100010_S1QuestStepsUnique.php:38, 2026-12-25-100020_S1CharacterResourcesUnique.php:40); `php -l` clean; every fixture whose test writes through the core carries `is_exclusive`/`exclusive_slot`/UNIQUE; no `app/Config` file in the diff, so `WipeManifest` is unchanged. Not safe for prod on the refusal path, see the major finding.
7. delivered: `app/Config/Database.php` is not in the diff; C:/laragon/www/mmorpg/ROADMAP.md:293 journal line closes S1, S1b stands in track S (:161) and in the §2 queue (:59).
8. delivered: no guide or tip file in the diff, matching the "no" verdicts; ADR-199 is written and indexed (C:/Projects/mmorpg-vault/decisions/ADR-199-Task-exclusivity-and-resource-pairs-held-by-database.md); 29 tech-writing notes including the three `db/` tables are updated with `last_reviewed: 2026-11-24`. ADR line 133 states a false fact, see the major finding.
9. not in this pass
10. not in this pass
11. delivered: all 108 changed paths outside `docs/specs/s1-atomicity-1124/` are `app/`, `tests/`, `phpstan-baseline.neon` or `ROADMAP.md`; none is under `docs/specs/s1-atomicity/` or `memory/`. The 8 paths absent from the old branch are the robot-gathering fix, the NPC and onboarding quest writers, `GatherResultPersister` and their tests, all named by asks 1, 10, 13 or the S4/S6 commit subjects. The 106 files develop changed since `f9b3bf3a` and the branch's files do not overlap, so nothing is reverted. `vulyk/s1-atomicity` is still at `72d6e107`.
12. delivered for the static half only: phpstan and `php -l` are green; the develop work since the fork is byte-identical to develop (no file overlap). The ten suite parts and the named develop tests were not run in this pass.
13. not in this pass

## Findings
- [major] [ask 6] A migration that refuses on duplicates does not stop the deploy, C:/laragon/www/mmorpg/deploy/post-deploy.sh:60. `php spark migrate --all -n` exits 0 when `up()` throws: CI4's `Migrate::run()` catches the exception, prints it and returns nothing. I observed `migrate_exit=0` after an exception by running it with the DB pointed at a closed port. So `set -e` does not fire and the script goes on to switch the symlink to the new release on the old schema. If the `character_tasks` migration refuses, it drops `is_exclusive` and the two later migrations never run. Every `insertExclusiveTaskRow()` then fails with "Unknown column 'is_exclusive'" (C:/laragon/www/mmorpg/app/Services/Tasks/ActiveTasksService.php:78), so no task start works. Without `uq_cr_char_resource`, the upsert at C:/laragon/www/mmorpg/app/Models/CharacterResourceModel.php:122 inserts a new row on every credit to an existing pair, creating the duplicates that then block the migration for good. ADR-199 line 133 ("Деплой упадёт на миграции") relies on a stop that does not happen. Smallest fix: in `post-deploy.sh`, capture the migrate output and `exit 1` before the switch when it contains an exception block (or when `php spark migrate:status` still shows a pending migration), and correct the ADR line. Test: the migrate step of `post-deploy.sh`, run with `database.default.port=1`, exits non-zero; today it exits 0.
- [minor] [ask 6] 11 fixtures declare `exclusive_slot` as `STORED` while the migration and ADR-199 say `VIRTUAL`, e.g. C:/laragon/www/mmorpg/tests/database/ExclusiveTaskLockTest.php:55 and C:/laragon/www/mmorpg/tests/database/MarchingTaskHandlerTest.php:94. The UNIQUE behaves the same on tables without the cascade FK, so no test result changes; replace `STORED` with `VIRTUAL` to match the migration.
- [minor] [ask 6] The duplicate-refusal branch of `S1QuestStepsUnique` and `S1CharacterResourcesUnique` has no test; only the `character_tasks` one does (C:/laragon/www/mmorpg/tests/database/ExclusiveTaskSlotConstraintTest.php:257). Test: seed two rows of one pair, call `up()`, expect `RuntimeException` naming the table, both rows still present and no `uq_*` index.
- [minor] [ask 6] `S1CharacterTasksExclusiveSlot::up()` guards only `is_exclusive` with `fieldExists`. If `ADD UNIQUE` fails after `exclusive_slot` was added, a rerun stops on "Duplicate column name". Guard the `exclusive_slot` add the same way.
```
