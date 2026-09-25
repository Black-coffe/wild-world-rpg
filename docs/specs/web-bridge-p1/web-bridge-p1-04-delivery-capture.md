---
story: web-bridge-p1-04
spec: web-bridge-p1
status: done
returned: DONE
tier: 3
worker: worker-code
model: opus
tracer: false
wave: 2
blocked_by: [web-bridge-p1-01]
---

# Delivery layer: capture seam, virtual → inbox, linked mirror, two guard layers

## Goal
Every Bot API call made by app code passes one decision point.

**Layer (a): the `App\Services\Telegram\Request::send()` seam.** It runs after `KeyboardNormalizer`
and before `parent::send()`, and it is reachable under PHPUnit. It asks `WebDelivery` what to do:
- **Actor chat while capturing:** record the call as a `Msg` (send, or edit of a `message_id`,
  or delete, or `answerCallbackQuery` text as the alert, or reply keyboard / `remove_keyboard` /
  `force_reply`). Take a synthetic `message_id` from `WebScreenStore::nextMessageId()` and
  return a synthetic `ServerResponse` `{ok:true, result:{message_id, chat, date, text|caption}}`.
  Nothing goes to Telegram.
- **Virtual chat (`VirtualChat::is`):** `send*` becomes an inbox row (source `virtual`).
  `edit*`/`delete*` are dropped. Return a synthetic ok (ADR-189 §4a).
- **Otherwise:** `parent::send()` as today. Then, if the recipient is a linked site user (plan
  A5), is not `DeliveryContext::actor()`, and the flag is on, `send*` is copied to the inbox
  (source `mirror`).

**Photos.** Capture the file path from the stream URI **before** Longman closes it. An http(s)
URL is kept as is. A file under `public/` becomes a site URL. Anything else sets
`photo_url=null` and keeps the caption (plan A12).

**Layer (b): the base client.** `TelegramDeliveryProbe::install()` now always installs a client
whose stack carries a virtual-range guard middleware. The probe middleware is added only when the
firehose is on. The guard drops a virtual-range request and logs `error` (a layer-(a) bypass is
a defect). `TelegramDeliveryProbe::client()` is the getter. `TelegramBridge::ensure()` calls
`install()` so that cron and spark are guarded too.

**`BridgeClient($delegate, $actorChat)`** is used by story 07. It turns bypassing actor-chat or
chat-less requests into caption-only captures and forwards everything else to the delegate.

`WebScreenStore` and `WebInboxService` implement their plan contracts:
- `applyCapture`: new sends become the current screen, and the previous one moves to history
  (capped). An edit replaces its message in place, wherever it is.
- `callbackAllowed` checks screens and inbox buttons.
- The inbox is pruned on append (plan A7).

## Requirements
> На виртуальный id в Telegram ничего не уходит.
> Фоновые сообщения: привязанному игроку уходят в Telegram и копией во входящие на сайте, игроку без Telegram — только во входящие.
> Для игроков в Telegram бот работает как раньше.
> Ответ бота показывается как экран, а исправление сообщения заменяет экран.

## Files
- app/Services/Telegram/Request.php
- app/Services/Web/WebDelivery.php
- app/Services/Web/WebScreenStore.php
- app/Services/Web/WebInboxService.php
- app/Services/Web/BridgeClient.php
- app/Services/Telegram/VirtualChatGuardMiddleware.php
- app/Services/Logging/TelegramDeliveryProbe.php
- app/Services/Telegram/TelegramBridge.php
- app/Services/Notifications/MediaSender.php
- tests/database/WebDeliveryTest.php
- tests/database/WebInboxServiceTest.php
- tests/unit/Services/Web/BridgeClientTest.php
- tests/unit/Services/Telegram/VirtualChatGuardMiddlewareTest.php
- tests/unit/Services/Logging/TelegramDeliveryProbeTest.php

## Non-goals
- No edits to the 53 TaskHandlers, `BaseTaskHandler`, broadcast services or the 62 bypass senders.
  The seam and the guard cover them. If the import count below finds a class of callsite that
  neither layer covers, report it; do not widen.
- Touch `MediaSender` **only** if Q4 shows `file_id` caching hides the path. The only change
  allowed there is exposing the source path to the seam while capturing.
- No controller, pipeline, `BridgeClient` installation or `setClient` ordering. That is story
  07. Worker/cron never install `BridgeClient`.
- No filtering of virtual rows in broadcasts or stats (plan A14).

## Map slice
- `memory/map/telegram.md`: MediaSender the only photo path, editOrSend, TelegramBridge.
- `memory/map/tasks-worker.md`: `safeSend*`, `TelegramChatResolver`.
- Notes `mmorpg-vault/tech-writing/services/TelegramDeliveryProbe.md` and `PlayerActionLogger.md`
  (§ delivery signal).
- recon.md §A (R4 statics, `Request.php:149-191,593-617,691-719`).
- ADR-189 §2, §4, §5 and invariants 1, 4, 7.

## Acceptance criteria
- [ ] The worker runs its own new test files singly while iterating. The close-story gate is the
      full suite, phpstan and the migrations lint.
- [ ] **Counted and written in Implementation notes:**
      - how many app files import `Longman\TelegramBot\Request` directly and how many import
        `App\Services\Telegram\Request`;
      - which one `MediaSender`, `BaseTaskHandler` and the broadcast services use.
- [ ] Ask 2: under PHPUnit, a `sendMessage`/`sendPhoto` to a virtual chat never reaches
      `parent::send()`, writes exactly one inbox row with `source='virtual'` (flag on) and
      returns ok. `editMessageText`/`deleteMessage` to a virtual chat write nothing and return ok.
      With the flag off, nothing is written and still nothing is sent.
- [ ] Ask 2: `VirtualChatGuardMiddlewareTest` shows that a virtual-range `chat_id` in form or
      multipart options is never passed to the inner handler and logs `error`. A group-shaped
      negative id (`-100…`) passes through. The probe still installs its middleware when the
      firehose is on, and the guard is present when it is off.
- [ ] Ask 3: while capturing, a `sendMessage` + `editMessageText` of the same id yields one
      screen with the edited text. Two sends yield one screen of two messages, and the previous
      screen moves to history, capped at `historySize`. `sendPhoto` yields `photo_url` for a file
      under `public/` and `null` plus the full caption otherwise. The reply keyboard becomes the
      dock. `force_reply` becomes the `input` placeholder. `answerCallbackQuery` text becomes the
      alert. Synthetic `message_id`s start at the `web_play_state` base and grow monotonically
      per character.
- [ ] Ask 4: for a linked site user (A5) who is not the actor, with the flag on, one Telegram
      send leads to `parent::send()` **and** exactly one `mirror` inbox row. It is never
      mirrored for the actor, and never for `edit*`/`delete*`. A bot-only player (no site login)
      gets no inbox row.
- [ ] Ask 5: with no capture, no virtual chat and no linked recipient, `Request::send()` data and
      return are identical to before, and the existing Telegram and MediaSender tests stay green.
- [ ] `BridgeClientTest`: actor-chat and chat-less requests are captured, not delegated; other
      chats are delegated unchanged.
- [ ] R5 is written in the notes: each `editOrSend` / stored-`message_id` path (e.g.
      `last_map_message_id`) falls back to send when an edit fails, or the paths that do not are
      named. This goes to the Queen's Tier-3.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
`git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`

## Implementation notes
- Files: `app/Services/Telegram/Request.php` (seam: `WebDelivery::route()` after normalizer; `protected static transport()/transportSendMessage()` = the only path to `parent::send()`, a test spy overrides them), new `app/Services/Web/{WebDelivery,WebScreenStore,WebInboxService,BridgeClient}.php`, new `app/Services/Telegram/VirtualChatGuardMiddleware.php`, `TelegramDeliveryProbe` (always installs a client; guard always, probe only with firehose; `client()` getter, `PROBE_NAME`), `TelegramBridge::ensure()` calls `install()`. Tests: `tests/database/{WebDeliveryTest,WebInboxServiceTest}.php`, `tests/unit/Services/{Web/BridgeClientTest,Telegram/VirtualChatGuardMiddlewareTest}.php`, `TelegramDeliveryProbeTest` +2. `MediaSender` NOT touched: Q4 holds (no `file_id` cache; the seam sees the open stream and reads its URI via `stream_get_meta_data`).
- Import count (`git grep`, app/): `use Longman\TelegramBot\Request` directly — 2 files, both aliased and both infra (`App\Services\Telegram\Request` itself extends it; `TelegramDeliveryProbe` only for `setClient`). `use App\Services\Telegram\Request` — 346 files. No FQCN `Longman\…\Request::` calls, no `replyToChat/replyToUser`. `MediaSender`, `BaseTaskHandler` and `BroadcastService` all import the app `Request`. No uncovered callsite class found; Longman internals are left to layer (b)/`BridgeClient`.
- Decisions: (1) mirror row is written before `parent::send()` (inside `route()`), so a Telegram failure still leaves the web copy. (2) Inbox rows take `message_id` from `WebScreenStore::nextMessageId()` (not Telegram's id) - one id space per character, UNIQUE-safe. (3) While capturing, an edit of an id that is in none of capture/screen/history/inbox returns `ok:false "message to edit not found"` so callers' edit->send fallback fires (R5); an edit of an inbox message is promoted to the new screen with its id. (4) Virtual chat with flag off: `message_id=0` in the synthetic ok. (5) Guard answers HTTP 200 `ok:false` (403 description), pushed after the probe so it is closest to the network. (6) `photo_url` = `base_url(<path under public>)`. (7) Flag read via `GameSettingsService` only for `send*` to a non-actor/virtual chat, so edits/answers add no lookup.
- R5 (edit by stored/foreign `message_id`): fallback to send exists in `MediaSender::editOrSend/editTextOrSend`, `MoveCharacterToDirectionAction:442` (clicked id, then `last_map_message_id`, then send), `CancelMarchAction:77`, `MarchAction:789`, `BaseService:496`, `MarchingTaskHandler:775` (stored `msg_id`), `TeleportBeaconSetAction:261`. No fallback: `MapLegendAction:76`, `MoveSurfaceService:324` - both edit the clicked message id, which is real in Telegram and a captured id on web, so they are safe. The only persisted id is `telegram_users.last_map_message_id` - covered by the fallback above. For the Queen's Tier-3.
- Surprising: `MarchingTaskHandler:775` (Worker) edits the stored march message; for a web-only character that edit is dropped with synthetic ok (per ADR §4a), so the march progress update does NOT reach the inbox. Also the E6/E8 hooks skip non-positive telegram ids (`LastSeenService:125`, `LoginStreakService:46`, `ReturnDigestService:40`, `DailyTaskService:50`) - web-only players would get no streak/digest/daily; that is story 05/07 territory, not delivery. Recon claim "`TelegramChatResolver:78` drops non-positive ids" is stale: `chatIdForCharacter` returns any non-zero id.
- Verification: new test files run singly, all green (WebDeliveryTest 13, WebInboxServiceTest 3, BridgeClientTest 3, VirtualChatGuardMiddlewareTest 4, TelegramDeliveryProbeTest 19+TelegramBridgeTest); unit dirs Notifications/Telegram/Logging/Web green (268). Mutations, each separately: virtual branch off -> WebDeliveryTest red; mirror call off -> linked test red; guard push off -> probe test red; guard check off -> guard test red. Full suite NOT run here (parallel wave-2 workers share `wildworld_tests`); left to close-story. phpstan full: clean; migrations lint: clean. `StandoffAlertRateTest`/`PvpStandoffServiceTest` error in setUp on an FK type mismatch (`character_buildings.map_cell_id`) from the shared test DB state - unrelated to this story.

## Findings
