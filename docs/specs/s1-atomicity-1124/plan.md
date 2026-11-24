# S1 atomicity: finish the parked spec on thin VULYK (plan)
**Brief:** [brief.md](brief.md) · **Branch:** `vulyk/s1-atomicity-1124` · **Approved:** «Одобрить (Рекомендую)», 2026-11-24

## Goal
Bring the atomicity package built on the old branch `vulyk/s1-atomicity` (DB-held 🔒 slot, UNIQUE on
`quest_steps` and `character_resources`, atomic credits and conditional debits) onto a thin spec branch from
`develop` without any old-framework files, then finish what council round 1 left RED: the two code fixes whose
tests exist but whose code never landed (repair order, robot-gathering start), plus three writers recon found
that the new UNIQUE keys would turn into duplicate-key errors. Then the ADR and tech-writing notes the old brief
asked for, and a green full suite on the merged result. Review is code-only and split by area.

## Assumptions
- Recon 2026-11-24 (read-only, branch `72d6e107`): asks 1, 2, 4, 5, 6, 7 have code and tests on the old branch;
  asks 9 and 10 have tests but no code fix (`RepairCraftedItemAction.php:472` inserts before `:477` deducts;
  `StartRobotGatheringAction.php:239` still `save()`s after spending durability); ask 8 has nothing in the vault.
- The old branch's code merges into develop cleanly: the only conflict is `memory/stats/anomalies.jsonl`; develop's
  changed files since the fork (`UpdatePipeline`, `BaseTaskHandler`, `BaseObjectHandler`, `TelegramUserModel`,
  `BlockedRecipient`, two tests) do not overlap the old branch's code files.
- Import by `git merge --squash vulyk/s1-atomicity`, then unstage and remove every old-framework path
  (`docs/specs/s1-atomicity/`, `memory/`, `.claude/`, `.vulyk/`), one commit. Not `git checkout <paths>`, which
  would silently revert develop changes (Fable cross-check). The old branch is kept unchanged as the archive; no
  tag is created (tags on develop deploy).
- `decreaseQtyById` now returns `false` instead of deleting the row and returning `true` when the amount exceeds
  the stock (intended by ask 5); its callers are checked in S1 and by review.
- Six unit fixtures write `character_tasks` DDL by hand without the S1 columns (`BasePickerTest`,
  `BaseScreenBotParityTest`, `BuildingCardBaseChoiceTest`, `HangarBaseScopeTest`, `UpgradeConfirmBaseSuffixTest`,
  `CompleteRobotGatheringBaseTest`); recon could not tell whether they reach the slot core. S6 finds out by running
  the suite; any that break get the S1 columns verbatim.
- DB tests need local MySQL (Laragon) and the `wildworld_tests` base; the builder starts MySQL for its run and
  every turn ends with it stopped.
- Not part of this work: `strictOn` (S1b), any prod/preprod data check or migration run, a Tier-3 smoke (refusal
  texts are the existing ADR-167 texts; a webhook smoke on preprod belongs to the push step, owner's word), push,
  tag, deploy.
- Goes live: nothing on merge. A later push of `develop` deploys to preprod-testbot and runs the three migrations
  there (`post-deploy.sh`); each migration refuses on existing duplicates instead of deleting (prod had 0 on
  2026-11-11; re-check before the push is a Follow-up item for the owner).

## Stories
- [x] S1 Import the old branch's code, code only - asks: 11, 6, 7, 2, 4, 5
  - files: everything in `git diff f9b3bf3a..vulyk/s1-atomicity -- app tests phpstan-baseline.neon ROADMAP.md`
  - test first: none: the import carries the old branch's own tests; this story checks they run on develop's base
  - check: path check of ask 11; `git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`;
    `vendor/bin/phpstan analyse --memory-limit=512M --no-progress`; `vendor/bin/phpunit --no-coverage --no-progress
    tests/database/ExclusiveTaskSlotConstraintTest.php tests/database/S1ExclusiveSlotOnRealSchemaTest.php
    tests/database/CharacterResourceAtomicWriteTest.php tests/database/QuestStepsUniqueStartTest.php`; list callers
    of `decreaseQtyById` and what they do with `false`
  - result: `decreaseQtyById` has one caller in `app/`, `LootProcessor::deductUpTo()`: on `false` it re-reads the
    row and retries with `min(amount, stock)` up to 3 times, then counts 0 taken. `ResourceTradeService` no longer
    calls it (own conditional write); `DeathService::confirmedResourceLoss()` only reads rows afterwards.
- [x] S2 RED finding 1: repair deducts before it takes the slot - asks: 9, 3
  - files: `app/Controllers/Telegram/Commands/Actions/Craft/Repair/RepairCraftedItemAction.php`,
    `tests/unit/Player/PoolAdoptionRepairUpgradeTest.php`
  - test first: the pair below fails today (the task INSERT runs before the pool race throws); after the fix the
    `DBQuery` listener sees no `character_tasks` query on the race path; prove the listener is not vacuous (it does
    record the query when the order is wrong)
  - check: `vendor/bin/phpunit --no-coverage --no-progress tests/unit/Player/PoolAdoptionRepairUpgradeTest.php
    tests/unit/TaskHandlers/GreenhouseProductionWaterTest.php`, then the unit2 part
- [x] S3 RED finding 2: robot gathering takes the 🔒 slot through the core - asks: 10, 3
  - files: `app/Controllers/Telegram/Commands/Actions/Camp/Buildings/Robots/StartRobotGatheringAction.php`,
    `tests/database/RobotGatheringStartSlotTest.php`, `tests/unit/Camp/StartRobotGatheringBaseTest.php`
  - test first: `RobotGatheringStartSlotTest` fails today (durability spent, no refusal); fix mirrors
    `StartRobotExplorationAction`: slot insert before durability, `Refused` → ADR-167 `slotTakenText()` with
    Markdown, nothing spent
  - check: `vendor/bin/phpunit --no-coverage --no-progress tests/database/RobotGatheringStartSlotTest.php
    tests/unit/Camp/StartRobotGatheringBaseTest.php`; the grep of ask 3 returns nothing
- [x] S4 Writers the new UNIQUE keys would break - asks: 1, 13
  - files: `app/Services/NPC/NpcInteractionService.php` (`acceptOfferedQuest`), `app/Services/Craft/CraftQueueService.php`,
    app/Services/Player/Gather/GatherResultPersister.php, their tests (new or existing)
  - test first: a quest step already present for the pair → accept is refused as "already taken", no exception;
    a row inserted between read and write → the credit lands as a sum, no duplicate-key error. Both fail today
  - check: the new/changed test files, then the db part(s) holding them
  - outside: `phpstan-baseline.neon` — six `GatherResultPersister` entries (nullable promoted property,
    read-add-write offsets) no longer match after the rewrite and phpstan fails on unmatched ignores
- [ ] S5 ADR and tech-writing - asks: 8
  - files (vault, left uncommitted): `mmorpg-vault/decisions/ADR-NNN-*.md` + `decisions/index.md` line (or ADR-167
    addendum), tech-writing notes of every touched model/service/handler (`CharacterResourceModel`,
    `CharacterTaskModel`, `QuestStepsModel`, `ActiveTasksService`, `QuestStartService`, `CraftOrderService`,
    `BuildOrderService`, `CraftQueueService`, `NpcInteractionService`, `GatherResultPersister`, the touched actions)
  - test first: none: documentation
  - check: `grep -l -E "exclusive_slot|uq_cr_char_resource|uq_qs_quest" C:/Projects/mmorpg-vault/decisions C:/Projects/mmorpg-vault/tech-writing -r`
    names the ADR and the notes; `CharacterResourceModel.md` no longer says "пара без UNIQUE"
- [ ] S6 Full suite on the merged result - asks: 12, 6
  - files: test fixtures that break on the S1 schema (the six named above, if they do), nothing else
  - test first: none: verification story
  - check: the ten full-suite parts of `CLAUDE.md` `## Команды`, one after another, all green; phpstan; `php -l`
    of migrations; `tests/database/UpdatePipelineTest.php tests/database/CronSenderBlockedMarkTest.php` green

## Review split
Code only, not spec files; one reviewer pass per area so no pass reads the whole 3.4k-line import:
(a) 🔒 slot — asks 2, 3, 10 (+ S3); (b) resources — asks 4, 5, 13; (c) quests — ask 1; (d) repair — ask 9;
(e) migrations and import hygiene — asks 6, 7, 11, 12.

## Test command
`vendor/bin/phpunit --no-coverage --no-progress <files>`; full suite = the ten parts in `CLAUDE.md` `## Команды`
(not run during planning: MySQL was not running; the develop base ran green at `blocked-mark-1124` merge).

## Descoped
(none)
