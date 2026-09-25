<!-- seat: review · model: unknown · round: 4 · head: 60adf3aa · pack: be09bd1085fa · attempt: 1 · recorded: 2026-09-24T21:24:56Z · verdict: PASS -->
VERDICT: PASS

# Adversarial review: web-bridge-p1, round 4 (`884bb558..60adf3aa`; focus on wave 6: stories 11, 12, 13)

Method: I read the diff, the plan (A4, A16, A17, Contracts, Plan deltas, Integration gate), stories 11–13 and the round-3 manual review. I checked the vendor CI 4.7.2 claims the stories rely on: the query path in `BaseConnection` with `transException=false`, the non-HTML branch of `ExceptionHandler`, and `Factories::get` / `injectMock`. I also checked `Config\Security` (`cookie`, `regenerate=true`, `redirect=false`). I ran no tests: no line in the diff raised a question that one test run could settle, and the close-story outcome is on record. I ran `scope-check.sh` for 11, 12 and 13: the only hit is uncommitted hive `memory/stats/skills.json`.

Checked and sound, no finding:
- The round-3 manual-review findings #1, #2, #5, #6, #7, #9, #10 and #11 are each addressed. #3, #4, #8, #12 and #13 are recorded in Plan deltas.
- The A16 branch sits after the capture case and before the virtual case. Real-range ids fall through unchanged, and the test pins the boundary at 999 999 999.
- `upsertEdit` works through the existing `UNIQUE(character_id, message_id)`.
- The `guarded()` lock-timeout claim holds: CI4 4.7 does not throw inside a transaction by default and clears `transStatus` instead (`BaseConnection.php:836-845`).
- The #5 and #11 tests bite: the same synthetic id is used for A and B, and the inbox row of B is inserted first. The #7 test seeds the DB clock.
- Intent prune uses the existing `created_at` index.
- Wave-6 scope: each commit touches only the `## Files` of its story plus the paperwork.

## Critical
None.

## Major

1. `docs/specs/web-bridge-p1/plan.md:85-92` (A16), carried out at `app/Services/Web/WebDelivery.php:125` · **plan**
   - **What happens.** A16 narrows Ask 4 («привязанному игроку уходят в Telegram») for linked players: progress edits of a march started on `/play` now reach only the site.
   - **The record.** A16 is marked *owner to confirm*, and the Plan delta says "A16 and A17 go to the owner". The only `**Approved:**` line covers A0–A15. No confirmation of A16/A17 is recorded anywhere.
   - **Condition to satisfy:** before ship, the confirmation or veto of A16 and A17 by the owner must be on the record in plan.md, or the narrowing must be listed under `## Descoped`.

## Minor

2. `app/Services/Web/WebDelivery.php:125,298-301` · **plan**
   - **What happens.** The A16 branch ignores `DeliveryContext::actor()`. On the Telegram webhook, an edit by the interactive actor of their own stored synthetic id is absorbed into the web. It returns `ok:true`, so the Telegram fallback send of the handler never fires, and an unread `mirror` inbox row is written for the own action of the actor. A4 says replies to the actor are never copied.
   - **Reachable today** through the second candidate `last_map_message_id` in `MoveCharacterToDirectionAction.php:436-439`, which is tried only when the edit of the clicked id fails. This happens after a `/play` map visit stored a synthetic id.
   - **Condition to satisfy:** an edit of a synthetic id aimed at the chat of the current interactive actor, outside capture, must keep the pre-A16 behaviour. That means an edit failure the caller can see, so its fallback reaches Telegram, and no inbox copy.

3. `app/Services/Web/WebDelivery.php:125` (A8 / A16) · **plan**
   - **What happens.** After story 11, every edit or delete with `message_id >= 1 000 000 000` to the chat of any character is withheld from Telegram. This covers bot-only players too, because `characterForChat(..., false)` has no login filter. The whole premise is that real Telegram message ids never reach 1e9, and nothing in the plan or the stories verifies that.
   - **Blast radius.** Before this story, the range only mattered inside the web tables. Now it silently drops real Telegram edits.
   - **Condition to satisfy:** the claim that real message ids stay below `firstMessageId` must be verified and recorded, or the branch must also require that the id is known to the web copy of that character (screen, history or inbox).

4. `app/Controllers/Play.php:83-99` with `app/Services/Web/WebScreenStore.php` `guarded()` · **plan**
   - **What happens.** Story 11 added a new `RuntimeException` path: a lock-wait timeout or DB error under `applyCapture`, which fires after dispatch. `Play::act` still catches only `InvalidArgumentException`, so the response is a 500.
   - **In JS mode.** Production renders an empty non-HTML body. That is a parse failure, so `fallbackToPrg` resubmits the same `intent_id`. Dedup then returns the old state with no alert, and the tap looks like it did nothing, although it did.
   - **In no-JS mode.** It is a 500 page.
   - **Condition to satisfy:** an act whose dispatch ran but whose screen write failed must answer with the failure alert, both in JSON and in PRG, not with a 500.

5. `app/Services/Web/WebActService.php:126,138,248` · **plan**
   - **What happens.** A17 prunes intent rows after 24 h. That turns the permanent per-character key `bootstrap-<id>` into a 24 h key. The docblock claims "второй раз не уйдёт", which is now false.
   - **Failed bootstrap.** A player whose first bootstrap claimed the intent but stored an empty screen sees a blank `/play` with no alert on every visit within the window. After that, `/start` is re-dispatched.
   - **Condition to satisfy:** the lifetime of the bootstrap key under A17 must be stated and intended. A bootstrap that left no screen must be retryable, or it must show the failure alert, on later visits.

6. `docs/specs/web-bridge-p1/web-bridge-p1-13-play-client-error-path.md` (AC #2 Tier-2) and `plan.md` Integration gate · **plan**
   - **What happens.** The second-tab tap does not reach the designed 400. `Config\Security` uses cookie CSRF with `regenerate=true`, so an act in tab A rotates the token, and the hidden field in tab B is stale. With `redirect=false` and `display_errors` off, CI returns an empty 403 body to `Accept: application/json` (`ExceptionHandler.php:83-95`). JS treats that as a parse failure and PRG-resubmits with a fresh token.
   - **Result.** The planned check "stale button from a second tab shows the alert in place" will see a full-page PRG instead. With `display_errors` on (dev), JS sees a JSON 403 with no alert, and `applyAct` reloads silently.
   - **Condition to satisfy:** the Tier-2 scenario for #2 must name a path that actually reaches the 400. For example, one tab with a message gone from the store. Otherwise the stale-token behaviour must be stated as the intended outcome.

7. `app/Views/site/play.php:55` · **plan**
   - **What happens.** Story 13 changed `wildworld-play.js` but not its `?v=1` cache-bust. The worker flagged this, but no story owns the file. A browser that cached v1, such as the one used for the earlier Tier-2 of the Queen on preprod, keeps the pre-#2 JS. The post-wave-6 Tier-2 can then validate the old code.
   - **Condition to satisfy:** the JS version token must change with the JS content before the Tier-2 walk and the ship.

8. `app/Filters/AccountThrottleFilter.php:102` · **plan**
   - **What happens.** The `play`/`inbox` 429 is JSON regardless of `Accept`. A no-JS player who hits the act throttle still sees a raw JSON page. That is the same symptom manual review #2 described, now only for no-JS. The site rule is "every view works without JS".
   - **Condition to satisfy:** a throttled non-JSON `/play` POST must land on `/play` with the throttle alert.

9. `public/assets/js/wildworld-play.js:145-160` · **plan**
   - **What happens.** `markRead` is a POST that rotates the token, and it does not set `busy`. A screen tap made while it is in flight carries the old token. That tap fails CSRF and degrades to PRG, and in the other order the mark-read silently fails.
   - **Condition to satisfy:** POSTs from one page must be serialized, so that none of them sends a token that a concurrent POST has already rotated.

10. `tests/database/PlayControllerTest.php` (new 429, 400 and inbox tests) · **worker**
    - **What happens.** The tests assert only that `csrf` is a non-empty string. They would stay green if the filter or controller returned the pre-rotation token, and that is the exact failure #2 is about.
    - **Condition to satisfy:** at least one test must prove that the returned `csrf` is accepted by a follow-up POST, or that it differs from the submitted token.

11. `app/Views/site/_play/state.php:76` · **worker**
    - **What happens.** The negative lookahead after the leading slash (it rejects a second `/` or a backslash) still accepts `/<TAB>/host` and `/<LF>/host`. The WHATWG URL parser strips ASCII tab and newline, so the browser resolves these as protocol-relative `//host`. The value is server-produced today.
    - **Condition to satisfy:** a photo `src` must never resolve protocol-relative, including after the browser strips tab and newline characters.

12. `app/Services/Web/WebActService.php:248` (`pruneIntents`) · **worker**
    - **What happens.** The delete is measured on `NOW()`, but `created_at` is still written with PHP `date()`. Story 12 asked for the DB clock. A PHP/DB timezone skew shifts the dedup window by the offset. The worker disclosed this.
    - **Condition to satisfy:** the intent insert and the prune must use the same clock.

13. `app/Services/Web/WebScreenStore.php:266` and `WebInboxService::hasCallback` · **plan**
    - **What happens.** After story 12, `callbackAllowed` and `WebInboxService::hasCallback(int,string)` have no production caller. Only tests call them, and the Contracts still list `callbackAllowed` as the whitelist API. A future fix could land in the unused copy.
    - **Condition to satisfy:** the unused whitelist API must be removed, or be marked test-only, and the Contracts must name the current check.

14. `mmorpg-vault/tech-writing/` · **plan**
    - **What happens.** There is no tech-writing note for any of the new services and controller: `WebDelivery`, `WebScreenStore`, `WebInboxService`, `WebActService`, `UpdatePipeline`, `BridgeClient`, `Play`, `AccountThrottleFilter` changes and the others. Only `tech-writing/web/design-system.md` mentions the web area. Constitutional rule 1 (ADR-009) requires the notes, and no story or ask carries them.
    - **Condition to satisfy:** before ship, each new or changed service, controller and filter must have its tech-writing note. The note must include A16 (background edits of synthetic ids) and A17 (intent retention).

15. Local environment (story 12 Implementation notes) · **worker**
    - **What happens.** Story 12 left a private test DB `wildworld_tests_s12` in place on the shared local MySQL.
    - **Condition to satisfy:** scratch databases created for a story must be removed, or listed for cleanup, when the story closes.

## Not verified
- Whether the prod Telegram private-chat `message_id`s can reach 1e9 (finding 3).
- Whether any other webhook-actor handler edits a stored synthetic id besides `MoveCharacterToDirectionAction`. I grepped `last_map_message_id` and the R5 list from story 04 only.
- Exact production body of a CSRF 403 to a JSON request. I read it from `ExceptionHandler.php:83-95` and did not observe it.
