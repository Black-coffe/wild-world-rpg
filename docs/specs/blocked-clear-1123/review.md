# Review (blocked-clear-1123)

## Round 1 - 2026-11-23 - head 90b00c36c27a160aa751a1ab310e3828a043ccad

```
VERDICT: PASS
TESTS: vendor/bin/phpunit --no-coverage --no-progress tests/database/UpdatePipelineTest.php -> pass, 18 tests, 561 assertions (1 PHPUnit deprecation)

## Asks
1. delivered: a webhook update from the player's private chat clears `blocked_at` in the pipeline's `finally`, so the senders filtering on `blocked_at IS NULL` pick the player up again, C:\laragon\www\mmorpg\app\Services\Telegram\UpdatePipeline.php:176
2. delivered: the clear is guarded by `! $isWeb`, and `/play` always runs as `SOURCE_WEB`, C:\laragon\www\mmorpg\app\Services\Telegram\UpdatePipeline.php:176, C:\laragon\www\mmorpg\app\Services\Web\WebActService.php:312
3. delivered: one call site for the incoming-update clear and no command touched; the senders' existing `clearBlocked` calls are unchanged, C:\laragon\www\mmorpg\app\Services\Telegram\UpdatePipeline.php:176-179
4. delivered: schema is built from migrations including `AddBlockedAtToTelegramUsers`; tests cover clear, web keeps, and never-marked (exactly one conditional UPDATE, 0 rows affected), C:\laragon\www\mmorpg\tests\database\UpdatePipelineTest.php:117, :431, :454, :478
5. delivered: the diff touches only `UpdatePipeline.php`, `TelegramUserModel.php` (docblock only) and the test; no sender, ERROR logging or Forbidden classifier.
6. delivered: `my_chat_member` with new status `kicked` returns false from the predicate, and the "block (my_chat_member kicked)" case reaches the pipeline and keeps the mark, C:\laragon\www\mmorpg\app\Services\Telegram\UpdatePipeline.php:217-222
7. delivered: both notes carry the change and `last_reviewed: 2026-11-23`, but are uncommitted in the vault repo (C:\Projects\mmorpg-vault\tech-writing\services\UpdatePipeline.md, C:\Projects\mmorpg-vault\tech-writing\models\TelegramUserModel.md); no balance number, no table or column, no player surface; guide/tips verdicts "no" are in the brief.
8. delivered: the predicate requires `chat.type === 'private'` and `chat.id` equal to the sender, so group message, group `my_chat_member`, inline query, inline-message callback and `chat_member` all leave the mark, C:\laragon\www\mmorpg\app\Services\Telegram\UpdatePipeline.php:197-225
9. delivered: `run()` has two callers, `BotController::webhook` (Telegram route behind the secret) and `WebActService::runPipeline` (hard-coded `SOURCE_WEB`); the web test goes through `WebActService::act` with a linked real-id player, whose synthetic chat is private with the real id, so it fails if the `! $isWeb` guard is removed, C:\laragon\www\mmorpg\app\Controllers\Telegram\BotController.php:96, C:\laragon\www\mmorpg\tests\database\UpdatePipelineTest.php:478

## Findings
- [minor] [ask 8] The "group message" case never reaches the pipeline predicate, C:\laragon\www\mmorpg\tests\database\UpdatePipelineTest.php:410. `BotController::isCommunityChat` returns before `UpdatePipeline::run()`, so the predicate's `message` branch for a non-private chat is covered only by reading; the code is correct today. Smallest fix: one direct assertion on the public static predicate. Test: `assertFalse(UpdatePipeline::isPrivateChatSignOfLife($groupMessageUpdate, self::REAL_TG))`, which passes now and would fail if the chat-type check were dropped from the `message` branch.
```

Other checks (reviewer): phpstan on the two touched app files reports no errors, and the brief is unchanged since `plan(blocked-clear-1123): approved`.

Orchestrator, before the round: db5 (147 tests), unit1 (1943), unit2 (1603) and phpstan — all OK.
