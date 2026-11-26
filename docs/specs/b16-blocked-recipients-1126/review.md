# B16 — review

## Round 1 - 2026-11-26 - head dcea844b326accacb737b0d9b1ea893408739406

```
VERDICT: PASS
TESTS: vendor/bin/phpunit --no-coverage --no-progress tests/database/BlockedRecipientGateTest.php tests/database/BroadcastServiceBlockedTest.php tests/database/CronSenderBlockedMarkTest.php tests/database/UpdatePipelineTest.php tests/database/WebDeliveryTest.php -> pass, 91 tests, 1813 assertions (phpstan: no errors; 9 neighbouring blocked-mark test files: pass, 67 tests; 31 query-counting test files: pass, 260 tests; full suite not run)

## Asks
1. delivered: every background send to a marked private chat stops before transport, C:\laragon\www\mmorpg\app\Services\Telegram\Request.php:45-47 and :58-60, C:\laragon\www\mmorpg\app\Services\Notifications\BlockedRecipientGate.php:56. All six crons send only through `safeSendMessage`/`safeSendPhoto`; the broadcast counts the skip as blocked, C:\laragon\www\mmorpg\app\Services\Notifications\BroadcastService.php:94.
2. delivered: a private-chat message, edit or button tap clears the mark, C:\laragon\www\mmorpg\app\Services\Telegram\UpdatePipeline.php:180, and the in-process set forgets the player, C:\laragon\www\mmorpg\app\Models\TelegramUserModel.php:90. Test "blocked → /start → background send reaches transport": C:\laragon\www\mmorpg\tests\database\UpdatePipelineTest.php:637.
3. delivered: the gate runs after `WebDelivery::route()`, so virtual chats still answer ok into the inbox and a linked player's mirror copy is written before the skip, C:\laragon\www\mmorpg\app\Services\Web\WebDelivery.php:144. Tests: C:\laragon\www\mmorpg\tests\database\BlockedRecipientGateTest.php:252 and :265.
4. delivered: none of the six handlers was changed and none reads the send result; only the send helpers learned the skip, C:\laragon\www\mmorpg\app\TaskHandlers\BaseTaskHandler.php:140, :197, :288. Test (game write happens, no send, no ERROR): C:\laragon\www\mmorpg\tests\database\BlockedRecipientGateTest.php:295.
5. delivered in code, not measurable at head: no Bot API call is made for a marked recipient from a background process, so Telegram cannot return "bot was blocked by the user" on these paths. The 24-hour prod count is a post-tag measurement.
6. delivered: no balance numbers, no pause or throttle added; the diff has no `GameSettings` keys and no migrations.
7. partly: no guide section, tip, table or column added, matching the verdicts. Vault notes are updated for BlockedRecipient (carries the gate), BroadcastService, BaseTaskHandler, TelegramUserModel, UpdatePipeline and WebDelivery, but not for BaseObjectHandler (see finding).

## Findings
- [minor] [ask 7] The note C:\Projects\mmorpg-vault\tech-writing\tasks\objects\BaseObjectHandler.md was not updated (`last_reviewed: 2026-11-24`, no mention of the skip), although C:\laragon\www\mmorpg\app\TaskHandlers\Objects\BaseObjectHandler.php:56 and :82 changed. Fix: add the "skip of a marked recipient, no log" paragraph as in the BaseTaskHandler note and bump `last_reviewed`. Test: none (documentation).
- [minor] [correctness] Three background senders outside the six crons still write one ERROR per skipped send, now with the skip text instead of Telegram's: C:\laragon\www\mmorpg\app\Services\Events\PassiveHealthWarning.php:138, C:\laragon\www\mmorpg\app\Services\Events\GatherFloorInterrupt.php:267, C:\laragon\www\mmorpg\app\Services\Player\PlayerDetectionService.php:252. The ask-5 grep for "bot was blocked by the user" will still drop, but total ERROR lines from these paths will not. Fix: guard each with `! BlockedRecipientGate::isSkipped($response)`. Test: background `PassiveHealthWarning` send to a player with `blocked_at` set writes no ERROR line.
```

Reviewer note (outside the report block): ad hoc runs left local `wildworld_tests` with 7 tables; `tests/unit/Craft/CampfireCustomQuantityTest.php` failed on missing tables in that state — unconfirmed as environmental, a full-suite run recreates the database.

Owner: fix the minors, round 2 - "Починить мелочи, раунд 2 (Рекомендую)", 2026-11-26
