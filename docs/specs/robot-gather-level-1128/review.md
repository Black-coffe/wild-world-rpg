# Review

## Round 1 - 2026-11-28 - head 3257aaac3155a936918ee5685039edb56eeede43

VERDICT: PASS
TESTS: `vendor/bin/phpunit --no-coverage --no-progress tests/database/RobotGatheringRelativeAwardTest.php tests/database/IntentApplierRelativeDeltasTest.php tests/unit/Services/Events` -> pass, 145 tests, 1920 assertions, 2 skipped, 1 PHPUnit deprecation

Also run: the other robot-handler tests (`CompleteRobotGatheringBaseTest`, `CompleteRobotGatheringNameTest`, `WebOnlyCharacterWorkerTest`, `RobotGatheringStartSlotTest`) -> pass, 36 tests, 850 assertions. phpstan on the two changed `app/` files -> no errors.

## Asks
1. delivered: the robot query is filtered by the character's level, `app/TaskHandlers/CompleteRobotGatheringHandler.php:232-237`. `testUnderLevelCharacterGetsOnlyResourcesOfItsLevel` covers it, and it can fail.
2. delivered: the filter only adds a `WHERE`; the amount calculation and award path are untouched (`CompleteRobotGatheringHandler.php:243-277`). `testCharacterAtRequiredLevelGetsBothAsBefore` checks the rows exist, not the amounts.
3. delivered: `ResourceModel::withinCharacterLevel()` at `app/Models/ResourceModel.php:213-218` is used by `eventGrantCandidates()` (`ResourceModel.php:229`) and by the robot query (`CompleteRobotGatheringHandler.php:237`).
4. delivered: an empty selection falls into the existing "ничего не собрано" branch (`CompleteRobotGatheringHandler.php:253-268`); `testOnlyAboveLevelResourcesFinishWithNothingGathered`.
5. partly: no new balance number; tech-writing notes updated (vault `d00fd1a`). The guide and tips verdicts are not stated anywhere I am allowed to read.

## Findings
- [minor] [ask 5] Guide and tips verdicts are not visible in the brief, the code, or the two tech-writing notes. Smallest fix: one line each. Test: none; paper check.
