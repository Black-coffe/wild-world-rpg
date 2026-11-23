# A returning player gets messages again (plan)
**Brief:** [brief.md](brief.md) · **Branch:** `vulyk/blocked-clear-1123` · **Approved:** «Одобрить с проверками Fable (Рекомендую)», 2026-11-23

## Goal
The "blocked the bot" mark is `telegram_users.blocked_at`. Today only a successful outgoing send clears it, but the
cron senders skip marked players and never send, so a player who unblocked the bot stays silenced forever. Clear the
mark in the one place that knows which channel an incoming update came from: `UpdatePipeline::process()`, next to the
`last_seen` stamp. The clear runs only for the Telegram webhook (`$isWeb === false`) and never for a
`my_chat_member` update whose new status is `kicked` (the player just blocked the bot). It reuses the existing
conditional `TelegramUserModel::clearBlocked()`, which writes nothing when the player is not marked.

## Assumptions
- The channel cannot be told from the user id: a linked web account sends its real Telegram id through `/play`
  (`VirtualIdentityService.php:64-83`). Only `UpdatePipeline::run($update, $source)` knows it
  (`UpdatePipeline.php:78`), so the clear goes there, not into `LastSeenService::stampByTelegramId`.
- The webhook reaches the pipeline after the ADR-181 dedup and the community-chat gate (`BotController.php:78-97`),
  so group/community updates do not clear the mark.
- When the player unblocks, Telegram sends `my_chat_member` with status `member`. That clears the mark (it is a real
  Telegram update). Only `kicked` is excluded. Not verified that the webhook receives `my_chat_member` (no
  `allowed_updates` filter found in the repo); either way the player's next message or tap clears it.
- A failure in the clear is caught and logged and never breaks the update, like the stamp.
- No new column (`blocked_at` already exists and is in `WipeManifest`), no balance number, no player-visible text.
- Not part of this work: cron senders, their ERROR logging, the "Forbidden" classification, the `last_seen` stamp
  on a `kicked` update (stays as today).
- Goes live: merge to `develop` deploys nothing by itself. Deploy is a tag/push, which this spec does not do.

## Stories
- [ ] S1 Clear the blocked mark on a real Telegram update - asks: 1, 2, 3, 4, 5, 6, 7, 8, 9
  - files: `app/Services/Telegram/UpdatePipeline.php`, `app/Models/TelegramUserModel.php` (docblock only),
    `tests/database/UpdatePipelineTest.php` (add migration `2026-06-16-130000_AddBlockedAtToTelegramUsers` to its
    list and new tests), vault notes `mmorpg-vault/tech-writing/services/UpdatePipeline.md` and
    `models/TelegramUserModel.md`
  - test first: database tests in `UpdatePipelineTest` (schema from migrations): (a) a marked player sends a
    webhook message → `blocked_at` is NULL; (b) a marked linked player (real Telegram id) acts through the web
    pipeline → `blocked_at` unchanged; (c) a marked player's `my_chat_member` `kicked` webhook update →
    `blocked_at` unchanged; (d) a never-marked player sends a webhook message → `blocked_at` stays NULL and the
    clear affects 0 rows; (e) a marked player's message in a group chat / an inline query → `blocked_at`
    unchanged, unless the code shows such updates never reach the pipeline (then that is written here with
    `file:line`); (f) the web case (b) runs through `WebActService`, and every caller of `UpdatePipeline::run()`
    is listed in the commit message with its `$source`; (g) only if the pipeline itself marks on `kicked`: an
    unmarked player plus `kicked` ends up marked (recon: nothing marks on `kicked` today, so expect "not needed").
    (a) fails before the code because nothing clears the mark on incoming updates.
  - check: `vendor/bin/phpunit --no-coverage --no-progress tests/database/UpdatePipelineTest.php` and
    `vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Test command
`vendor/bin/phpunit --no-coverage --no-progress tests/database/UpdatePipelineTest.php`; before the review also the
unit parts and the db part that holds `UpdatePipelineTest` (`tests/database/P[!v-z]*Test.php tests/database/U*Test.php`, db5)
plus phpstan. Last run today (2026-11-23, after starting local MySQL): `Tests: 9, Assertions: 285` OK.

## Descoped
(none)
