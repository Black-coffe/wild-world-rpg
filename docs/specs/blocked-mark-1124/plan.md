# Cron marks players who blocked the bot instead of logging ERROR (plan)
**Brief:** [brief.md](brief.md) · **Branch:** `vulyk/blocked-mark-1124` · **Approved:** «Одобрить с добавками Fable (Рекомендую)», 2026-11-24

## Goal
Close B16. The common cron senders (`BaseTaskHandler::safeSendMessage` / `safeSendPhoto` / `degradeToText` and the
copies in `BaseObjectHandler`) learn to tell "the player blocked the bot" from other failures, using the existing
`BroadcastDeliveryClassifier::isBlocked`. On such a failure they mark the player (`TelegramUserModel::markBlocked`,
conditional, keyed on `telegram_id`), log one WARNING and stop (no photo-to-text retry). Everything else stays as
today. One small shared helper does the decision so both base classes behave the same. Separately, the update
pipeline sets the mark when the player's private chat sends `my_chat_member` with status `kicked`, next to where
`blocked-clear-1123` clears it. And the Follow-up test from `blocked-clear-1123` lands first.

## Approval additions (owner, 2026-11-24: «Одобрить с добавками Fable (Рекомендую)»)
Relayed by the orchestrator from its cross-check with Fable (not the owner's words); folded into S2/S3:
1. Other refusals (403 "bot can't initiate conversation", 400 "chat not found", generic "Forbidden") do not mark;
   a test shows it. **Exactly what counts as blocked here:** the Telegram description contains
   `bot was blocked by the user` or `user is deactivated` (case-insensitive). The helper does not reuse
   `BroadcastDeliveryClassifier::isBlocked` (its list is wider: generic "forbidden", "chat not found", …) and does
   not change it (ask 9).
2. An already-marked player's mark date is not rewritten on every cron run (test).
3. A call-site test that both `BaseTaskHandler` and `BaseObjectHandler` really go through the helper (driven through
   each base's own senders, not only a helper unit test).
4. A photo failure that is not a block still falls back to text (test).
5. A caught mark-write failure is logged (WARNING/ERROR with the cause), not swallowed silently.
- S3 on the servers stays **unverified** until the owner checks `getWebhookInfo` → `allowed_updates` himself; this
  session makes no live Telegram call.

## Assumptions
- The recipient of every `safeSend*` call is a `telegram_users.telegram_id` (recon: all traced callers pass it,
  directly or through `TelegramChatResolver::chatIdForCharacter`); no `safeSend*` call targets a group.
- Only a positive chat id is ever marked; a negative id (group) and `VirtualChat::is()` ids are skipped even on a
  "Forbidden" answer (the classifier matches generic "forbidden"). Virtual ids normally get a synthetic ok from
  `WebDelivery::route` and never fail; the guard is a second belt.
- Blocked → `log_message('warning', …)` (prod does not log INFO; the siblings ComebackNudge/Day2Nudge/PvE sender
  use warning). Non-blocked not-ok keeps today's level per class: ERROR in `BaseTaskHandler`, WARNING in
  `BaseObjectHandler`; exceptions stay ERROR. A failed `markBlocked` (DB error) is caught and logged, never breaks
  the handler.
- The senders stay `void`; handlers keep their own "notified" bookkeeping after a blocked send (as today).
- A successful send does not clear the mark here (only the pipeline and the existing senders that already do it).
- `my_chat_member` reaches `UpdatePipeline` through the webhook (`blocked-clear-1123` tests route it end-to-end
  locally). **Unverified:** that the live webhooks' `allowed_updates` include `my_chat_member`; if not, the
  `kicked` mark simply never fires and the first failed send marks instead. Marking is in the `finally` block next
  to the clear, guarded by `! $isWeb`, private chat, `chat.id` = sender, new status `kicked`.
- No new table/column (`blocked_at` exists and is in `WipeManifest`), no balance number, no player-visible text.
  Verdicts: `/guide` — no (no player mechanic); tips — no (invisible plumbing).
- Not part of this work: the ~18 direct-send handlers, `BroadcastService` (still logs ERROR for blocked), the
  classifier word list, skipping marked players in the senders.
- Goes live: merge to `develop` deploys nothing; deploy starts with a push of `develop`, a separate question.

## Stories
- [ ] S1 Follow-up test: the group check, directly - asks: 1
  - files: `tests/database/UpdatePipelineTest.php`
  - test first: none: it pins existing behaviour (owner's condition from `blocked-clear-1123`); a direct
    `assertFalse(UpdatePipeline::isPrivateChatSignOfLife($groupMessageUpdate, self::REAL_TG))` plus the private
    message `assertTrue` control.
  - check: `vendor/bin/phpunit --no-coverage --no-progress tests/database/UpdatePipelineTest.php`
- [ ] S2 Common cron senders mark blocked players - asks: 2, 3, 4, 5, 6, 9, 10, 7
  - files: new helper (e.g. `app/Services/Notifications/BlockedRecipient.php`: given chat id + Telegram
    description, decides blocked-and-markable, marks, returns true), `app/TaskHandlers/BaseTaskHandler.php`,
    `app/TaskHandlers/Objects/BaseObjectHandler.php`, new `tests/database/CronSenderBlockedMarkTest.php` (schema from
    the `telegram_users` migrations, Telegram faked with `LongmanRequest::setClient` + Guzzle handler as in
    `UpdatePipelineTest`, a small test subclass of each base calling the senders, log captured), vault notes
    `tech-writing/tasks/BaseTaskHandler.md`, `tasks/objects/BaseObjectHandler.md`, `models/TelegramUserModel.md`
    and a new note for the helper.
  - test first: (a) "Forbidden: bot was blocked by the user" on sendMessage → player marked, one WARNING, no ERROR;
    (b) the same on sendPhoto → marked, exactly one Telegram call (no text fallback), one WARNING; (c) a 429 / "Bad
    Request: chat not found", "Forbidden: bot can't initiate conversation with a user" and a plain "Forbidden" answer and a
    thrown exception → not marked, logged as today; (b2) a non-block photo failure still falls back to text;
    (g) the mark write failing (e.g. table missing) → logged, the handler goes on; (d) a "Forbidden" answer for a virtual chat
    id and for a negative group id → nobody marked; (e) an already-marked player → `blocked_at` keeps its date and
    the send is still attempted (ask 10); (f) the `BaseObjectHandler` pair, (a) and (c), through its own senders. (a) fails before the code
    because nothing marks.
  - check: `vendor/bin/phpunit --no-coverage --no-progress tests/database/CronSenderBlockedMarkTest.php` and
    `vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
- [ ] S3 `kicked` in the private chat sets the mark - asks: 8, 6, 7
  - files: `app/Services/Telegram/UpdatePipeline.php` (a pure `isPrivateChatBlock($update, $telegramId)` next to
    `isPrivateChatSignOfLife`, conditional mark in the `finally` block, failure caught), `tests/database/UpdatePipelineTest.php`,
    vault notes `tech-writing/services/UpdatePipeline.md`, `models/TelegramUserModel.md`
  - test first: through the webhook: unmarked + private `kicked` → marked; marked + `kicked` → date unchanged; group
    `my_chat_member` `kicked` → not marked; web source → not marked; `kicked` then a private message → cleared, and
    `kicked` then `member` → cleared. The first fails before the code because nothing marks on `kicked`.
  - check: `vendor/bin/phpunit --no-coverage --no-progress tests/database/UpdatePipelineTest.php` and phpstan

## Test command
`vendor/bin/phpunit --no-coverage --no-progress tests/database/UpdatePipelineTest.php` (and the new test file); before
the review also the parts that hold the touched tests and the safe-sender stubs: unit1, unit2, db5
(`tests/database/P[!v-z]*Test.php tests/database/U*Test.php`), db7 (`WebOnlyCharacterWorkerTest`), db6
(`RobotGatheringRelativeAwardTest`), db1 (`CronSenderBlockedMarkTest`), plus phpstan. Local MySQL must be started
first. Last run today (2026-11-24): `UpdatePipelineTest.php` — `Tests: 18, Assertions: 561` OK.

## Descoped
(none)
