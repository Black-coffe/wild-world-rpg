# Review

## Round 1 - 2026-11-25 - head ae1e9da22f88a53c0b8920a48a2a07302a71f41d

Note: recorded after the fix dispatch (the first attempt to write this file was blocked by a shell hook); content verbatim.

```
VERDICT: BLOCK
TESTS: vendor/bin/phpunit --no-coverage --no-progress tests/database/CraftQueuePromoteEligibleTest.php tests/database/CraftQueuePromoteWiringTest.php tests/database/CraftQueueCoreTest.php tests/database/CraftExclusiveSlotRefusalTest.php -> pass, 29 tests, 719 assertions
  Also run: unit2 (tests/unit/[!S]* tests/unit/S[!e]*) -> FAIL, 1636 tests, 1 failure (see finding 1); unit1 (tests/unit/Se*) -> pass, 1943 tests; 16 neighbouring tests/database files (Worker, March, FinishTask, Web*, CraftQueueService, Standoff, Demolish) -> pass; phpstan -> no errors.

## Asks
1. delivered: every exit from `in_work` now offers the slot — Worker after any completed or failed task (C:\laragon\www\mmorpg\app\Controllers\Worker.php:156), interrupt (app\Controllers\Telegram\Commands\Actions\FinishTaskAction.php:106), gather cancel (CancelGatherAction.php:78), march stop in bot (CancelMarchAction.php:51) and on /play (app\Services\Web\WebNativeScreenService.php:739), relocation interrupted by base demolition (Camp\DeleteBaseAction.php:245); a per-tick sweep covers unknown exits (Worker.php:187, app\Services\Craft\CraftQueueSweep.php:36).
2. delivered: `promoteEligible()` goes only through `promoteNext()` -> `ActiveTasksService::moveToInWork` (C:\laragon\www\mmorpg\app\Services\Craft\CraftQueueService.php:300, :262); a refused row stays `queued`, and `cancel()` is untouched. Covered by tests\database\CraftQueuePromoteEligibleTest.php:139 and :178.
3. delivered: one shared text, byte-identical to the old `notifyQueuedActivated`, text-only (C:\laragon\www\mmorpg\app\Services\Craft\CraftQueueActivationNotice.php:52); the craft completion handler uses it too (app\TaskHandlers\Craft\GenericCraftCompletionHandler.php:296).
4. delivered: C:\laragon\www\mmorpg\tests\database\CraftQueuePromoteWiringTest.php:167 sets up the stranded state and asserts `in_work` at :175. Base `Worker.php` has no promotion call, so that assertion fails there; I confirmed this by reading base code, not by running the test on base.
5. delivered: out of scope by the brief; the diff contains nothing for it.
6. not judged: the verdicts live in plan.md, which this review does not read. The diff adds no guide section or tip migration, which is consistent with a "no" verdict; someone with the plan should confirm.

## Findings
- [major] [correctness] The branch turns an existing project gate red: `Tests\Unit\Config\TelegramSenderBridgeCoverageTest::testEverySenderRaisesBridgeOrIsExcepted`, C:\laragon\www\mmorpg\tests\unit\Config\TelegramSenderBridgeCoverageTest.php:78. The new C:\laragon\www\mmorpg\app\Services\Craft\CraftQueueActivationNotice.php:105 calls `Request::sendMessage` without `TelegramBridge::ensure()` and is not in `EXCEPTIONS`, so unit2 and the single-process CI run fail. No message is lost in practice: the callers are webhook actions or /play under `WebDelivery` capture, and cron goes through `BaseTaskHandler::safeSendMessage`. Smallest fix: add `'CraftQueueActivationNotice.php'` to `EXCEPTIONS` with that reason, or call `TelegramBridge::ensure()` in `send()` and log on false. Test: `vendor/bin/phpunit --no-coverage --no-progress tests/unit/Config/TelegramSenderBridgeCoverageTest.php` fails now and passes once fixed.
- [minor] [correctness] Two concurrent promoters can start two rows of the same parallel (⏳) recipe, C:\laragon\www\mmorpg\app\Services\Craft\CraftQueueService.php:280 (acknowledged in the docblock at :295). Until now only the Worker promoted, under its flock; now a player's interrupt or cancel can race a Worker tick. The loser gets `Missing` on the head row, advances to the next queued row of that recipe and starts it, so the per-recipe queue runs two at once. The 🔒 slot is not affected and nothing is duplicated (each row is paid). Smallest fix: on `Missing`, stop if the recipe now has an `in_work` row. Test: ⏳ recipe with two queued rows, a `DBQuery` listener moves the head to `in_work` after the snapshot, assert the second row stays `queued`.
```

## Round 2 - 2026-11-25 - head b5ac986f74be9b2a71b2e1c476250322af58c789

```
VERDICT: PASS
TESTS: vendor/bin/phpunit --no-coverage --no-progress tests/database/CraftQueuePromoteEligibleTest.php tests/database/CraftQueuePromoteWiringTest.php tests/database/CraftQueueCoreTest.php tests/database/CraftExclusiveSlotRefusalTest.php tests/unit/Config/TelegramSenderBridgeCoverageTest.php -> pass, 33 tests, 793 assertions (1 PHPUnit deprecation notice)

## Asks
1. delivered: every exit from `in_work` now calls `promoteEligible()` — Worker after each task (C:\laragon\www\mmorpg\app\Controllers\Worker.php:156), player interrupt/cancel (FinishTaskAction.php:106, CancelGatherAction.php:78, CancelMarchAction.php:51, DeleteBaseAction.php:245), /play march stop (C:\laragon\www\mmorpg\app\Services\Web\WebNativeScreenService.php:739), plus a per-tick backstop sweep (Worker.php:187).
2. delivered: promotion still goes only through `ActiveTasksService::moveToInWork`, and `Refused` leaves the row `queued`, C:\laragon\www\mmorpg\app\Services\Craft\CraftQueueService.php:262-269.
3. delivered: one shared text-only message for all promoters, C:\laragon\www\mmorpg\app\Services\Craft\CraftQueueActivationNotice.php:52; the old completion path uses it too (GenericCraftCompletionHandler.php:296).
4. delivered: C:\laragon\www\mmorpg\tests\database\CraftQueuePromoteEligibleTest.php and CraftQueuePromoteWiringTest.php exercise `promoteEligible()` and the wiring, neither of which exists at base.
5. delivered: nothing to build; the diff contains no CI, branch or docs-push work.
6. not judged: the verdicts live in the plan, which I do not read; the diff adds no guide section or tip seed, and no player-facing mechanic that would need one.

## Findings
(none)

## Past findings (round 2 and later)
F1: fixed - `'CraftQueueActivationNotice.php'` is in `EXCEPTIONS` at C:\laragon\www\mmorpg\tests\unit\Config\TelegramSenderBridgeCoverageTest.php:51. The stated reason matches the callers: `send()`/`promoteAndSend()` are reached only from the four Telegram actions and `WebNativeScreenService`, and cron uses `message()` with `safeSendMessage`. The gate test passes.
F2: fixed - on `Missing`, `promoteNext()` re-reads whether the recipe has an `in_work` row and stops, C:\laragon\www\mmorpg\app\Services\Craft\CraftQueueService.php:283 and :351. The new test `testConcurrentPromoterDoesNotStartASecondRowOfARunningParallelRecipe` (CraftQueuePromoteEligibleTest.php:214) can fail: the recipe is seeded parallel, so without the check the tail row would start, and it asserts the interleaving actually fired. The same-recipe completion path is unaffected, because the finished row is already `completed` before `promoteNext()` runs (GenericCraftCompletionHandler.php:127, :288).
```
