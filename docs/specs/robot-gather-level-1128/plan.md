# Robot gathering ignores character level — hotfix (plan)
**Shipped:** 2026-11-28, f11f4393
**Brief:** [brief.md](brief.md) · **Branch:** `vulyk/robot-gather-level-1128` · **Approved:** «Approve (Recommended)», 2026-11-28

## Goal
`CompleteRobotGatheringHandler` picks resources by rarity window and biome only (`:232-236`), so a level-10
workshop brings rarity-1 relics to any character. Add a shared `ResourceModel` scope for
`level_required <= :level`, use it in `eventGrantCandidates` and in the robot query with the character's level
(`$character` is loaded at `:104`).

## Assumptions
- Prod (SELECT 2026-11-28): 14 workshops, 4 at level 10 (owners 289/659/208/59); char 1280 (L59) holds no 70/71.
  Nothing to neutralize.
- Empty `$available` already yields an empty map → existing all-zero path (`:249+`); the test pins it.
- Gather's own query (`GatherCellResourceQuery`) is left as is (tied to cell/belt context); not a third copy of the
  shared scope's purpose, already correct.
- Guide: нет — backend fix. Tips: нет — nothing new to learn.
- Goes live: merge does nothing; push to `develop` → preprod; tag → prod, on the owner's word (standing: green
  preprod smoke = prod tag).

## Stories
- [x] S1 Robot gathering respects required level via a shared scope - asks: 1, 2, 3, 4, 5
  - files: `app/Models/ResourceModel.php`, `app/TaskHandlers/CompleteRobotGatheringHandler.php`, a DB test under
    `tests/database/` (e.g. extend `RobotGatheringRelativeAwardTest.php` or a new `RobotGatheringLevelGateTest.php`),
    vault notes `tech-writing/models/ResourceModel.md`, `tech-writing/tasks/**/CompleteRobotGatheringHandler.md`
  - test first: level-59 character, workshop 10, biome with a level-1 and a level-100 rarity-1 resource → only the
    level-1 one is awarded; only a level-100 resource → completion finishes with nothing awarded and no exception;
    level-100 character → both as before. Fails today: no level filter in the robot query.
  - check: `vendor/bin/phpunit --no-coverage --no-progress tests/database/RobotGatheringRelativeAwardTest.php tests/database/IntentApplierRelativeDeltasTest.php tests/unit/Services/Events` (+ the new test file) and phpstan
  - outside: `tests/unit/TaskHandlers/CompleteRobotGatheringBaseTest.php` (`resources.level_required`, `characters.level`) and
    `tests/database/WebOnlyCharacterWorkerTest.php` (`resources.level_required`): their hand-made schemas lacked the
    columns the robot query now filters on (`Unknown column 'level_required'`).

## Test command
`vendor/bin/phpunit --no-coverage --no-progress tests/database/RobotGatheringRelativeAwardTest.php tests/database/IntentApplierRelativeDeltasTest.php tests/unit/Services/Events`

## Descoped
(none)
