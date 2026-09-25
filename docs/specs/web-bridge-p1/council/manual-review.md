VERDICT: PASS

# Manual adversarial review: web-bridge-p1 (`develop...vulyk/web-bridge-p1`, app/ + play JS)

Scope of this pass: security of `/play*`, the transport bridge, XSS, the Ask 5 pipeline extraction, and migrations. I did not re-run the suite or phpstan. I ran no tests. The evidence is code reading plus one local `information_schema` query (local sizes, which say nothing about prod) and a check of vendor `Security::verify()` (CSRF token regeneration).

Checked and found sound (no finding):
- **Character comes only from the session.** `Play::gate()` checks the flag, then the login, then takes the session character. `WebActService::owns()` checks the character against the account again. The identity comes from the character row. No telegram, chat or character id is read from the request.
- **Callback whitelist.** Callback data must be on a button on the own screen, history or inbox of that character, and its `message_id` must be one of the own messages of that character. A character cannot press a callback sent to another character. The tests assert that nothing is dispatched and no intent is consumed on rejection.
- **Text and commands.** The bot has no admin commands. Referral via `/start ref_*` does not fire, because the virtual `telegram_users` row already exists (`StartCommand:106` requires `!$existingUser`).
- **CSRF.** Global filter; only `telegram/webhook` is excepted.
- **Intent dedup.** `UNIQUE(account_id, intent_id)`, applied through `insertUnique`.
- **Flag.** Checked on the server in every `/play*` route.
- **Transport.** Nothing in `app/` calls `Longman\...\Request` directly, and `replyToChat` / `->answer()` are not used. Layer (a) sees every send. Layer (b), the guard, is installed in the webhook, Worker, cron (`TelegramBridge`) and on `/play`. `finally` restores the client and ends capture. Capture state is static per request, so it cannot leak across requests. A send to another chat during an act is passed to the delegate.
- **XSS.** The renderer escapes everything first and allows only `http(s)` hrefs. The views escape every attribute and label, and JS inserts only server-rendered HTML.
- **Ask 5.** The pipeline keeps the old step order and the old exception behaviour for `telegram` (rethrow `Throwable`, swallow `TelegramException`). `setCustomInput` is kept.
- **Backfill.** Idempotent (`NOT EXISTS`, NULL-only updates). Its `down()` touches only the virtual range. WipeManifest classes are present.

## Critical
None.

## Major

1. `app/Services/Web/WebDelivery.php:123` with `app/TaskHandlers/MarchingTaskHandler.php:775-783` and `app/Controllers/Telegram/Commands/Actions/MarchAction.php:260` · **plan**
   - **What happens.** An act on `/play` stores the synthetic screen `message_id` (>=1e9) as the march `msg_id`. On each march step the Worker first tries `editMessageText`.
   - **Web-only player.** An edit to a virtual chat returns a fake `ok:true`. The "edit failed -> send" fallback of the handler therefore never runs. March progress never reaches the inbox or the screen. The player marches blind, although Ask 4 asks for background messages in the inbox and Ask 12 asks for the map on `/play`.
   - **Linked player who started a march on the web.** The edit reaches real Telegram with a message id that does not exist there and fails. The fallback sends a new message, and the handler does not store its id. The result is a new Telegram message plus an inbox mirror row on every march step.
   - **Condition to satisfy:** a background edit aimed at a web-originated message must reach the player (inbox or screen) and must not leave callers stuck in edit-fails-then-send on every step. Nothing in ADR-189 section 5, plan A4 or the stories looked at edit-with-fallback callers.

2. `public/assets/js/wildworld-play.js:77,101` · **worker**
   - **What happens.** Any non-2xx reply makes JS call `form.submit()` with the old CSRF token of the form. That includes the designed 400 reply «Эта кнопка уже недоступна» (with `html`/`alert`/`csrf` in its JSON body) and the 429 from the throttle. With `$regenerate = true` (`Config/Security.php:74`, vendor `Security.php:263`) the server has already rotated the token while handling the rejected request.
   - **Result.** The fallback POST fails CSRF, and the player gets the 403 framework page ("action not allowed") instead of the friendly alert. For 429 the page is raw JSON. A network hiccup after the server did process the act ends the same way.
   - **Condition to satisfy:** in JS mode, a 400 or 429 reply from `/play/act` must show its alert (and the refreshed state and token) in place. Only a real network or parse failure may fall back to PRG, and that fallback must carry a current token.

3. `app/Database/Migrations/2026-12-11-100005_SignedTelegramIdColumns.php:72` · **plan**
   - **What happens.** Changing `BIGINT UNSIGNED` to signed on `action_log.chat_id` (in use since 2024) and on `player_action_log.telegram_user_id` (the firehose) is a copying `ALTER` on MySQL 8 / MariaDB. It blocks writes to those tables while `post-deploy.sh` runs against the live webhook.
   - **Severity.** Major *if* prod row counts are in the millions (unverified: local DB has 166 / 95 rows). Otherwise minor.
   - **Condition to satisfy:** measure the row counts of both tables on testbot and prod before the tag, and schedule the migration so the writes of the webhook are not blocked for longer than an accepted window.

## Minor

4. `app/Services/Web/BridgeClient.php:103` · **worker**
   - **What happens.** A `chat_id` that is present but not an integer (for example `@channel`) is parsed as `null` and captured as "chatless". It is swallowed instead of going to Telegram. There is no such call today.
   - **Condition to satisfy:** only a request with no `chat_id` at all may be treated as chatless.

5. `app/Services/Web/WebActService.php:208-211` · **worker**
   - **What happens.** The whitelisted `data` and `message_id` are checked separately. A client can pair button D from message A with message B, and the handler then edits B with the result of D. This stays within the own character of the player.
   - **Condition to satisfy:** a callback must be accepted only when its `data` is on a button of the message whose `message_id` it carries.

6. `app/Services/Web/WebScreenStore.php:90-146` · **worker**
   - **What happens.** `applyCapture` reads the state and writes it back outside any lock or transaction. Two concurrent acts of one character (two tabs, a double tap on the no-JS path) lose the screen and history update of one of them.
   - **Condition to satisfy:** concurrent acts of one character must not overwrite the screen updates of each other.

7. `app/Services/Web/WebActService.php:243` / WipeManifest `web_play_intents` · **plan**
   - **What happens.** `web_play_intents` gets one row per act and has no retention. `telegram_updates_seen` (ADR-181) has a cleanup command.
   - **Condition to satisfy:** intent rows must have bounded retention and keep their dedup window.

8. `app/Services/Web/WebScreenStore.php:49` (called from `WebDelivery::toInbox`) · **worker**
   - **What happens.** Every mirror copy for a linked player allocates its id through `ensureRow`. That creates a `web_play_state` row for players who never opened `/play`, and the empty row keeps `bootstrap()` doing its job only because it checks screen and history, not row existence.
   - **Condition to satisfy:** allocating an inbox id must not create play state for players who never opened `/play`, or the dependency must be documented.

9. `app/Controllers/Play.php:56` · **worker**
   - **What happens.** `index()` degrades only on `InvalidArgumentException`. A `RuntimeException` from `claimIntent` or a DB error during bootstrap becomes a 500 page on `/play`.
   - **Condition to satisfy:** a failed first-visit bootstrap must still render `/play` with its alert.

10. `app/Views/site/_play/state.php` `$body` photo guard `~^(https?://|/)~` · **worker**
    - **What happens.** The guard also accepts protocol-relative `//host/...`. The value is server-produced today, so the risk is low.
    - **Condition to satisfy:** a photo `src` must be `http(s)` or a site-relative path, never protocol-relative.

11. `tests/database/WebActServiceTest.php:266` · **worker**
    - **What happens.** The whitelist test rejects only strings that exist nowhere. There is no case where the callback data sits on the screen or inbox of *another character*, which is the threat Ask 6 names.
    - **Condition to satisfy:** the test must fail if `callbackAllowed` stops scoping by character.

12. `tests/database/CharacterProvisioningServiceTest.php` · **plan**
    - **What happens.** This file was touched, but no story names it in `## Files` (Law 3). The change is a test that goes with the provisioning change of story 01.
    - **Condition to satisfy:** record it on story 01 or in `## Plan deltas`.

13. `app/Services/Web/WebDelivery.php` (`photoUrl`) · **plan**
    - **What happens.** A photo sent as a `file_id` string is resolved as a filesystem path (`realpath`) and yields no image. That fits A12 (caption-only), but A12 only mentions "not under `public/`", not `file_id` photos.
    - **Condition to satisfy:** A12 must name `file_id` photos as caption-only, or the renderer must handle them.

## Not verified (left to the Queen)
- Prod row counts for finding 3.
- Whether any other background edit-first handler exists outside `app/TaskHandlers` (grep covered only TaskHandlers and Worker).
- Whether bootstrap `/start` has side effects for existing linked players (plan A13 / Q7).
