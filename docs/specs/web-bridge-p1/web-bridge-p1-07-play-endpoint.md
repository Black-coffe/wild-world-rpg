---
story: web-bridge-p1-07
spec: web-bridge-p1
status: done
returned: DONE
tier: 3
worker: worker-code
model: opus
tracer: false
wave: 3
blocked_by: [web-bridge-p1-01, web-bridge-p1-04, web-bridge-p1-05, web-bridge-p1-06]
---

# `/play` endpoint: session-bound acts through the bridge, inbox API, flag, CSRF, throttle

## Goal
The `Play` controller serves the four routes in plan `## Contracts`. It declares them in
`Routes.php` and adds the `accountThrottle:play` and `accountThrottle:inbox` arguments
(`Config\WebPlay` budgets, per account). Every route checks `web.play_enabled` server-side
(ADR-189 invariant 8) and takes the character **only** from `AccountSession`. It is logged out →
`/account/login`, flag off → `site/play_stub` `flag_off`, no character → `no_character`.

`WebActService::act()` runs one act:
1. **Identity.** `VirtualIdentityService::identityForCharacter()` reads the identity from the
   session character, never from the request body.
2. **Validate the intent.** `kind ∈ {callback, text, command}`. Text is at most
   `textMaxLength`. A command starts with `/`. A callback's `data` must pass
   `WebScreenStore::callbackAllowed()` (plan A3), and its `message_id` must resolve through the
   store or the inbox.
3. **Dedup.** Insert into `web_play_intents` through `ConditionalWriteService::insertUnique()`.
   A duplicate returns the current state without dispatch. `update_id = −id`.
4. **Build.** Create the update with `SyntheticUpdateFactory`.
5. **Run under the bridge.** Use the plan's transport order: Probe install → `client()` →
   `beginCapture` → `setClient(new BridgeClient(...))` → `UpdatePipeline::run($u, 'web')` →
   `finally` restore the Probe client and `endCapture()`. Then `WebScreenStore::applyCapture()`.
   Return the state, the alert and the unread count.

`Play::index` loads the stored state. On the first visit with no state it dispatches the
bootstrap command (plan A13: `/start`, or `/menu` per Q7) through the same `act()`.

## Requirements
> Должна дальше по roadmap двигаться к полному переносу и дублированию возможности играть в игру на веб-сайте, и чтобы полностью разделить Telegram как связующее и зависящее звено.
> Вошедший на сайт игрок играет на `/play` через те же маршруты бота (кнопки, текстовые ответы, команды).
> Действия с сайта защищены (CSRF, лимит частоты), и игрок может управлять только своим персонажем.
> Игра на сайте спрятана за флагом `web.play_enabled` в админке (по умолчанию выключен).
> Персонаж без Telegram получает виртуальный id и играет наравне со всеми.
> На preprod персонаж, карта, сбор, крафт и входящие проходятся через `/play` — и привязанным игроком, и игроком без Telegram.

## Files
- app/Controllers/Play.php
- app/Services/Web/WebActService.php
- app/Filters/AccountThrottleFilter.php
- app/Config/Routes.php
- app/Config/Filters.php
- tests/database/WebActServiceTest.php
- tests/database/PlayControllerTest.php

## Non-goals
- No change to `/account/*` routes or to the P0 `accountThrottle` limits for existing POSTs. The
  new arguments are additive.
- No capture, store or inbox logic beyond calling the story-04 API. If that API is short, report
  it on INTERFACES and do not patch 04's files.
- No views or JS (story 06). No automated preprod smoke tooling (plan A14). Ask 12 is the Queen's
  Tier-3 walk, and this story makes it possible.
- Never accept `telegram_id`, `chat_id`, `character_id` or `account_id` from the request.

## Map slice
- `memory/map/website.md`: the `/account` group, `accountThrottle`, CSRF global except the
  webhook, `AccountSession::current()`.
- `memory/map/telegram.md`: the ADR-181 dedup pattern, `insertUnique`.
- recon.md §B (`Routes.php:237-258`, `Filters.php:46,88`).
- ADR-189 §1, §2, §6 and invariants 2, 3, 4, 8.
- Q6/Q7 answers in plan.md.

## Acceptance criteria
- [ ] The worker runs its own new test files singly while iterating. The close-story gate is the
      full suite, phpstan and the migrations lint.
- [ ] Ask 1: for a linked fixture character and a virtual fixture character, a callback from its
      stored screen, a dock text and a `/command` each dispatch through the pipeline and change
      the stored screen. An edit replaces it and a send pushes history. Nothing of the actor's is
      sent: the test asserts `parent::send` was not reached for the actor chat.
- [ ] Ask 6: a callback whose `data` is not on the character's screens or inbox → 4xx, no dispatch,
      no firehose row. The same `intent_id` twice → one dispatch. Over `actsPerMinute` → 429. A
      POST without CSRF is rejected by the global filter (test or Q6 evidence). The request body
      cannot select another character.
- [ ] R4: after `act()`, including when the pipeline throws, `Request`'s client is the Probe client,
      `WebDelivery::isCapturing()` is false, and `DeliveryContext::actor()` is null.
- [ ] Ask 7: with the flag off, every `/play*` route returns the stub or 403 and dispatches
      nothing. With it on, `GET /play` renders `site/play`.
- [ ] Ask 4: `GET /play/inbox` returns the unread count and the fragment, throttled by
      `accountThrottle:inbox`. `POST /play/inbox/read` zeroes the count. A JSON act response
      carries `unread`.
- [ ] Ask 2: no response body (HTML or JSON) contains the virtual or real `telegram_id` (test).
- [ ] The first `GET /play` for a character with no state dispatches the bootstrap once and shows
      a non-empty screen and dock.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
`git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`

## Implementation notes
- Files: new `app/Controllers/Play.php`, `app/Services/Web/WebActService.php`, `tests/database/{WebActServiceTest,PlayControllerTest}.php`; `AccountThrottleFilter` (arguments `play`/`inbox` -> per-account bucket from `AccountSession`, any method, JSON 429; no argument = P0 unchanged), `Routes.php` (4 routes before the catch-all), `Filters.php` (alias comment only).
- `WebActService`: `act()`, plus `current($characterId)` (state, no dispatch) and `bootstrap()` (`/start` once, intent key `bootstrap-<characterId>`, only when screen and history are empty). Rejection = `InvalidArgumentException` -> controller 400; checked before the intent row is written, so a reject never burns the intent. `act()` also re-checks `characters.account_id` = session account. Pipeline failure never escapes: the transport is restored in `finally`, the partial capture is still applied, and alert = `FAILED_ALERT`. `runPipeline()` is a protected seam used by the tests.
- Order: `UpdatePipeline::telegram()` is built BEFORE `Probe::install()`, because the Longman constructor sets a default client when none is set. If `Probe::client()` is null, nothing is dispatched.
- Tests prove "parent::send not reached": a test subclass wraps the installed `BridgeClient` in a recorder, so anything reaching Longman's `$client->post()` is logged before BridgeClient can swallow it. I disabled the actor-capture branch in `WebDelivery::route` (temporarily, reverted), and the recorder then logged `sendMessage` to the actor chat. Other mutations, each run separately and each turned the two files red: whitelist check off, `setClient($client)` restore off, `endCapture` off, flag check off, dedup off, throttle argument off, bootstrap off.
- Surprise for story 06's JS: on any non-2xx (400 reject, 403, 429) `wildworld-play.js` falls back to `form.submit()`. With CSRF `regenerate=true` the form's token is already rotated, so that resubmit hits the CSRF error page. The 400 JSON carries `html`/`csrf`/`alert`, but the JS ignores bodies of non-OK responses.
- Inbox panel page size is a controller constant `INBOX_PAGE = 50` (infra; `Config\WebPlay` is outside this story's files).
- Verification: own files singly green (7 + 12 tests); full suite 4479 tests OK (10 skipped); phpstan full clean; migrations lint clean.

## Findings
