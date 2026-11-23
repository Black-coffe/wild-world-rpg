# Review: blocked-mark-1124

## Round 1 - 2026-11-24 - head cc3f0ad78f93b9c36bba06d9d8b7a10d1be757f4

```
VERDICT: PASS
TESTS: vendor/bin/phpunit --no-coverage --no-progress tests/database/UpdatePipelineTest.php tests/database/CronSenderBlockedMarkTest.php -> pass, 51 tests, 1216 assertions (1 PHPUnit deprecation)

## Asks
1. delivered: direct test of `isPrivateChatSignOfLife` on a group message from a real id, with a private-message control; file green, C:\laragon\www\mmorpg\tests\database\UpdatePipelineTest.php:468
2. delivered: all four common senders plus the text fallback call `BlockedRecipient::handle`, which marks through `TelegramUserModel::markBlocked` as `BroadcastService` does, C:\laragon\www\mmorpg\app\Services\Notifications\BlockedRecipient.php:77, C:\laragon\www\mmorpg\app\TaskHandlers\BaseTaskHandler.php:111,168,254, C:\laragon\www\mmorpg\app\TaskHandlers\Objects\BaseObjectHandler.php:53,78
3. delivered: a block logs one WARNING and the photo sender returns before the text fallback, C:\laragon\www\mmorpg\app\TaskHandlers\BaseTaskHandler.php:168-170
4. delivered: only the two block phrases match, so other refusals keep the old log line and level; non-block photo failure still falls back to text; conditional UPDATE keeps the old date; a failed write logs ERROR with the cause, C:\laragon\www\mmorpg\app\Services\Notifications\BlockedRecipient.php:32-35,82-88
5. delivered: `isMarkable` accepts only a positive id outside the virtual range, C:\laragon\www\mmorpg\app\Services\Notifications\BlockedRecipient.php:55-65
6. delivered: both test files build their schema from the migration files; asks 2-5 are covered in C:\laragon\www\mmorpg\tests\database\CronSenderBlockedMarkTest.php:186-407, ask 8 in C:\laragon\www\mmorpg\tests\database\UpdatePipelineTest.php:523-627
7. delivered: notes for `BlockedRecipient`, `UpdatePipeline`, `BaseTaskHandler`, `BaseObjectHandler` and `TelegramUserModel` are updated on disk under C:\Projects\mmorpg-vault\tech-writing\ (`last_reviewed: 2026-11-24`); the diff has no balance number, migration, table, column or player surface
8. delivered: private `kicked` sets the mark, webhook only and positive id only; group, foreign chat and `web` source do not, C:\laragon\www\mmorpg\app\Services\Telegram\UpdatePipeline.php:186-189,244-258; all three required test cases are present
9. delivered: app diff is limited to the two base senders, the new `BlockedRecipient` and `UpdatePipeline` (ask 8); `BroadcastService`, the classifier and other handlers are untouched
10. delivered: no skip was added before the send; proven by `testAlreadyMarkedPlayerIsStillSentAndKeepsMarkDate`, C:\laragon\www\mmorpg\tests\database\CronSenderBlockedMarkTest.php:289

## Findings
- [minor] [ask 7] The five tech-writing notes are uncommitted in the vault repo (`git -C C:\Projects\mmorpg-vault status`: 4 modified, `tech-writing/services/BlockedRecipient.md` untracked). The ask says "updated" and they are, but nothing protects them until they are committed. Commit them in the vault.
- [minor] [correctness] `BlockedRecipient::handle` logs "marked" even when the UPDATE touched no row (no `telegram_users` row for that id), C:\laragon\www\mmorpg\app\Services\Notifications\BlockedRecipient.php:84-85. The log line overstates; no data effect. Leave as is or word it "mark attempted".
```

Two things I checked that are not findings:
- Ask 4 was widened after `plan(blocked-mark-1124): approved`, and the same commit adds an owner quote under `## Answers` («Одобрить с добавками Fable (Рекомендую)»), so the change is the owner's.
- `phpstan` on the four changed app files reports no errors.
