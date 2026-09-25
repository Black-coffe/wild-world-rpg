---
story: web-bridge-p1-16
spec: web-bridge-p1
status: done
returned: DONE
tier: 2
worker: worker-code
tracer: false
wave: 8
blocked_by: [web-bridge-p1-01, web-bridge-p1-04, web-bridge-p1-05]
---

# A background notice to a web-only player reaches the inbox, not a sign check

## Goal
Fix for council round 5, Ask 4 (seat opus, RED). Two background notice paths drop the send before it
reaches the `Request::send()` seam, because they test the recipient's Telegram id by **sign**:
- `app/Services/Player/TeleportBeacon/BeaconCaptureService.php:106` returns
  `$tgId > 0 ? $tgId : null`, so `TeleportBeaconSetAction.php:252-254` (`if ($oldOwnerTgId)
  $this->send(...)`) skips the capture alert for a web-only (virtual-id) beacon owner.
- `ReferralService.php:158` (`if ($chatId > 0)`) drops the referral-reward notice for a web-only
  referrer.

A web-only player therefore gets nothing, not even an inbox item. After this story both guards accept
a positive id **or** `VirtualChat::is($id)`. This is the same rule story 05 applied to
`LastSeenService`, `LoginStreakService`, `ReturnDigestService` and `DailyTaskService` (plan delta
2026-09-24). The send then reaches the seam, which puts it in the web inbox when the flag is on and
drops it when the flag is off (A6). Nothing changes for a positive id, and a negative id outside the
virtual range (a group) is still refused.

## Requirements
> Фоновые сообщения: привязанному игроку уходят в Telegram и копией во входящие на сайте, игроку без Telegram — только во входящие. На сайте есть колокольчик со счётчиком.

## Files
- app/Services/Player/TeleportBeacon/BeaconCaptureService.php
- app/Services/**/ReferralService.php
- tests/database/WebOnlyBackgroundNoticeTest.php

## Non-goals
- Do not edit `TeleportBeaconSetAction.php`. Its `if ($oldOwnerTgId)` is fine once the service
  returns the virtual id.
- Do not touch `WebDelivery`, `WebInboxService`, `BridgeClient` or the guard middleware. The seam
  already routes a virtual send to the inbox. This story only lets the send reach it.
- Do not sweep and patch other sign checks. If the grep in the acceptance criteria finds another
  guard that drops a player notice for a virtual id, list it in Implementation notes (file:line,
  what it drops) and return `NEEDS_CONTEXT`. The Queen then widens `## Files` by plan delta.
  `SilentNotificationPolicy.php:58` and `TelegramChatResolver.php:78` are known "valid positive id"
  guards (recon Q2). Note whether each one drops a send, and do not edit them.
- No change to the flag-off behaviour (A6), to A5 (linked = `last_login_at`), or to the referral or
  beacon game rules and numbers.

## Map slice
- `memory/map/player.md`: teleports and the beacon (`Services/Player/TeleportBeacon`).
- `memory/map/telegram.md`: action handlers and the send path.
- Plan `## Contracts`: `VirtualChat::is()`, and `WebDelivery` (virtual chat → inbox). Also A1, A4,
  A6, and the plan delta of 2026-09-24 (the story-05 guard pattern). Story 05 Implementation notes.
- `docs/specs/web-bridge-p1/council/round-5/opus.md` ASK 4.

## Acceptance criteria
- [ ] Both guards read "positive OR `VirtualChat::is()`". A zero or null id is still skipped, and a
      negative id outside the virtual range (e.g. `-1001234567890`) is still refused.
- [ ] DB test, flag on: another player captures a beacon owned by a web-only character. The old
      owner gets one `web_inbox` row (`source='virtual'`), and no transport call carries the
      virtual id.
- [ ] DB test, flag on: the referral-reward notice for a web-only referrer produces one `web_inbox`
      row for that character.
- [ ] DB test, flag off: the same two sends write no inbox row and reach no transport (A6, Ask 2).
- [ ] DB test: for a Telegram player (positive id), both notices still reach the transport as before
      (Ask 5).
- [ ] Implementation notes list the hits of
      `git grep -nE "(telegram_id|tgId|chatId|chat_id)[^;]*(> 0|<= 0|< 1)" app/` that sit on a
      notice path, with a one-line verdict per hit (fixed here / not a send / needs context).
- [ ] The worker runs its own test file singly while iterating. The close-story gate is the three
      commands below, run sequentially on the shared `wildworld_tests`.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
`git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`

## Implementation notes
- `BeaconCaptureService::lookupOwnerTelegramId()` returns the id when `> 0 || VirtualChat::is()`, and `ReferralService::qualifyAndReward()` keeps a notice under the same rule. Zero, null and `-1001234567890` are still skipped (asserted).
- New `tests/database/WebOnlyBackgroundNoticeTest.php` (6 tests). The schema comes from the real migrations. `teleport_beacons` has an FK to `map`, so the test creates it and inserts beacon rows with `FOREIGN_KEY_CHECKS=0`. The beacon capture is real (`captureBeacon`). The send copies the caller's lines (`TeleportBeaconSetAction` `if ($oldOwnerTgId) send`, `ReferralQualifyCron` `sendMessage` per notice) through a transport spy. The test does not run the action or the cron themselves, because both would reach the live Longman client.
- The referral test uses a `ReferralService` subclass: enabled, title id 1, one qualified row, and `markRewarded` does nothing. `TitleService` is stubbed. `referrerCharacterId` and `referrerChatId` read the real tables.
- I reverted each guard separately and each time 2 tests went red (the beacon guard: lookup and inbox; the referral guard: notice list and inbox).
- Grep hits on the story regex:
  - `ReferralService.php:159`: fixed here.
  - `BeaconCaptureService.php:109`: fixed here.
  - `TelegramLogin.php:53`: a login-widget id check, not a send.
  - `SettlementTeleportService.php:219`: the fog reveal keyed on the `telegram_users.id` row id, which is positive for virtual rows. Not a send.
  - `MarchingTaskHandler.php:757`: row id, not a chat id. `resolveChatId` uses `!== 0`, so a virtual chat passes. Does not drop.
  - `OnboardingHintService.php:528`, `PvpStandoffService.php:350`, `TowerAlertService.php:221`, `NavMenuRefreshService.php:135`: `'chat_id' => 0` log inserts, not a send.
- Known guards:
  - `SilentNotificationPolicy.php:58` (`<= 0` → false) only decides whether the send is silent. It never drops one.
  - `TelegramChatResolver.php:78` is `telegramUserIdForCharacter`, a row id and not a send. The chat lookup `chatIdForCharacter` uses `!== 0` and passes a virtual id.
  - Neither was edited.
- Surprise: the story regex does not match the `$telegramId <= 0` spelling (story 05's guards). A wider sweep would need a different pattern. Not done, out of scope.

## Findings
