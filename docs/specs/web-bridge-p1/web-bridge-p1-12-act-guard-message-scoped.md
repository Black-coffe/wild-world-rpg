---
story: web-bridge-p1-12
spec: web-bridge-p1
status: done
returned: DONE
tier: 3
worker: worker-code
model: opus
tracer: false
wave: 6
blocked_by: [web-bridge-p1-07]
---

# Act guard: a callback belongs to its message, the whitelist test bites, intents are bounded

## Goal
Fix for manual review (round 3), minors #5, #7 and #11. All three live in `WebActService` and its
test.

- **#5.** Today `WebActService` (`:208-211`) checks `data` (through `callbackAllowed`) and
  `message_id` (through `findMessage`) separately. A client can pair button D of message A with
  message B, and the handler then edits B with D's result. After this story a callback is
  accepted only when its `data` is the `callback_data` of a button in the `inline_keyboard` of the
  very `Msg` that its `message_id` resolves to for the session character (store first, then
  inbox). A rejection stays as today: `InvalidArgumentException` → 400, no dispatch, and the
  intent is not consumed.
- **#11.** `WebActServiceTest` (`:266`) rejects only strings that exist nowhere. After this story
  it has cases in which the data sits on **another character's** message, and these cases fail
  if the lookup stops scoping by character.
- **#7.** `web_play_intents` gets one row per act and has no retention. After this story, each new
  intent insert also deletes intent rows older than `Config\WebPlay::intentRetentionHours` (24 h,
  plan A17). The delete is measured on the DB clock (`NOW() - INTERVAL`) and bounded per call
  (a `LIMIT`). A duplicate inside the window still returns the current state without dispatch.
  This mirrors the prune-on-append of A7, so there is no new cron entry.

## Requirements
> Действия с сайта защищены (CSRF, лимит частоты), и игрок может управлять только своим персонажем.
> minor #5,#6,#7,#9,#10,#11

## Files
- app/Services/Web/WebActService.php
- app/Config/WebPlay.php
- tests/database/WebActServiceTest.php

## Non-goals
- Do not change `WebScreenStore` or `WebInboxService` (story 11 owns them this wave). Use their
  existing `findMessage` / `callbackAllowed` API.
- No change to text or command intents. They stay free-form (A3).
- No `Config\Tasks` cron and no spark command for the prune. No WipeManifest change:
  `web_play_intents` stays TRANSIENT.
- No change to `Play.php`, the throttle or the JSON shapes (story 13).

## Map slice
- Plan `## Contracts` → Schema (`web_play_intents`, `Msg`), `WebActService`, `Config\WebPlay`
  (round-3 line), A3, A7, A8, A17.
- Story 07 Implementation notes (`act()` order, rejection before the intent write,
  `runPipeline()` seam, test fixtures).
- `docs/specs/web-bridge-p1/council/manual-review.md` findings 5, 7 and 11.
- Memory hint: time-window tests seed times on the DB clock, not PHP time.

## Acceptance criteria
- [ ] The worker runs its own test file singly while iterating. The close-story gate is the three
      commands below, run sequentially on the shared `wildworld_tests`.
- [ ] #5 (Ask 6): the character's screen holds M1 (button D1) and M2 (button D2). Then
      `(D1, M1)` dispatches, and `(D1, M2)` → 400, nothing dispatched, the intent is not
      consumed. The same rule holds for an inbox message.
- [ ] #11 (Ask 6): characters A and B both hold a message with the same synthetic id (both start
      at `firstMessageId`). Only B's copy carries button D. A's `(D, that id)` is rejected. So is
      A's `(D, id)` when D is only on B's inbox item. Implementation notes record the mutation
      used to prove the cases bite: the lookup is given B's character id or loses its character
      scope, and the tests turn red.
- [ ] #7: an intent row whose `created_at` is older than the window (seeded on the DB clock) is
      gone after the next act. A row inside the window survives, and resending its `intent_id`
      dispatches nothing.
- [ ] The existing `WebActServiceTest` cases stay green, unmodified in intent.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
`git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`

## Implementation notes
- `WebActService::validate()`: callback now resolves `message_id` first (store → inbox, both
  character-scoped) and accepts `data` only if `WebScreenStore::hasCallback($thatMsg, $data)`;
  the separate `callbackAllowed()` call is gone (subsumed). Unknown id and foreign data both stay
  `InvalidArgumentException` before `claimIntent`, so nothing dispatches and no intent is taken.
- `claimIntent()` calls `pruneIntents()` first: `DELETE ... WHERE created_at < NOW() - INTERVAL ?
  HOUR LIMIT 500` (private const `INTENT_PRUNE_LIMIT`). Prune before insert, so an expired
  `intent_id` is a fresh one again. `created_at` is still written with PHP `date()` (untouched);
  a PHP/DB TZ skew only shifts the 24 h window by the offset.
- `Config\WebPlay::$intentRetentionHours = 24` (A17).
- Tests: 3 new cases (#5 screen + inbox, #11 same synthetic id on A/B screen + inbox, #7 prune on
  DB clock + dedup inside the window). Mutations, each applied alone and reverted, each red:
  lookup given `$characterId + 1` (B) in both lookups; the same only on the inbox lookup; inbox
  lookup replaced with an unscoped `WHERE message_id = ?` query (in `WebActService`, to avoid
  touching story 11's `WebInboxService`); old separate `callbackAllowed` check restored (#5 red);
  `pruneIntents()` call removed (#7 red).
- Surprise: the shared `wildworld_tests` was being dropped by parallel sessions (FK "Failed to
  open the referenced table" across unrelated tests), so iteration and the full suite ran on a
  private DB `wildworld_tests_s12` via env `database.tests.database=...` (additive; left in place).
  Full suite there: 4505 tests, 65 errors, none in `WebActServiceTest`. All are env: the private
  DB lacks `site_categories` (PlayController/PlayViews), and Account* hit the "referenced table
  `characters`" error when run in suite order (`AccountAuthTest` alone is green). On the shared
  DB, `WebActServiceTest` and `PlayControllerTest` pass when run singly (each needed a rerun
  because of the concurrent drops). The sequential shared-DB suite gate is still owed to close-story.

## Findings
