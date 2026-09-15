<!-- seat: review · model: claude-opus-5 · round: 1 · head: fa85dd90 · pack: 878408c49bb4 · attempt: 1 · recorded: 2026-09-15T06:45:55Z · verdict: PASS -->
VERDICT: PASS

# lead-review: cron-delivery-integrity, round 1

**What I checked.** The diff is `develop...vulyk/cron-delivery-integrity`, 8 app files and 6 test files.
- **Scope:** every app and test file in the diff is named in one of the story `## Files` sections. The other changed files are cycle paperwork (`docs/specs/**`, `memory/stats/scope.jsonl`).
- **phpstan:** I ran it on only the 8 touched app files, because phpstan is not part of the `close-story` verification. Result: `[OK] No errors`.
- **Suite:** I did not re-run it. Its green result on a fresh database is already on the record.

**Project gates.**
- No new balance number anywhere.
- No new table or column, so WIPE-COVERAGE does not apply.
- No player text changed: the only new strings are log lines, so media-off is untouched.
- Asks 2 and 3 are delivered as worded: a git grep for the old `new Telegram(invalid, invalid)` fallback in `app/` finds only the comment in `StandoffExpiryHandler`.
- Ask 1 (the live cron run on preprod) is still for the Queen to do. It is not a code finding.

**Contract.** `TelegramBridge` matches `## Contracts` plus the delta that records `instance()` and `reset()`.
- It catches `Throwable` and never throws.
- A successful init is cached for the process; a failed one is not.
- On a successful first run it behaves exactly as the removed per-instance copies did: `new Telegram` + `Request::initialize`. So nothing regresses in the webhook process.
- `BaseTaskHandler::telegram(): ?Telegram` stays compatible with the `telegram(): Telegram` overrides in `tests/database/StandoffExpiryHandlerTest.php:355,397`, as the delta says.

## Critical
None.

## Major

1. `tests/unit/Services/Player/PvEServiceNotifyFailureTest.php:584`, routed **worker**.
   - **Condition:** the test must drop `battle_logs` only when it created that table itself in the same run, and must leave a table that already existed untouched.
   - **What is wrong:** setUp runs `CREATE TABLE IF NOT EXISTS`, but tearDown runs `DROP TABLE IF EXISTS battle_logs` every time. On any database where `battle_logs` already exists, such as a local `wildworld_tests` built from a dump or a database left by another test, it deletes a table it does not own.
   - **Existing pattern in the repo:** `StandoffAttackGateTest.php:123-136` and `:214-215` (`tableExists` check, a `$this->created` flag, drop only when the flag is set). `StandoffAlertRateTest.php:106-119` and `:228-229` do the same.
   - **Why the worker could have known:** the brief says "Общие базы не дропать".

## Minor

2. `app/TaskHandlers/BaseTaskHandler.php:40-43`, as seen by `app/TaskHandlers/Onboarding/ComebackNudgeHandler.php:100-101,182` and `Day2NudgeHandler.php:94-95,175`. Routed **plan**.
   - **Condition:** a one-shot nudge must not record its "pinged" marker in `action_log` when the send never happened because the bridge could not be raised.
   - **What changed:** before, a failed bridge made `telegram()` throw, which stopped the handler before `recordPinged()`. Now `telegram()` returns null. `Request::sendMessage` then fails inside the try/catch in the handler itself, and `recordPinged()` still runs, so the single comeback or day-2 nudge for that player is used up silently.
   - **Severity rests on one configuration:** the key is missing or malformed in a running cron. That is not the normal state of prod, hence minor.
   - **Why plan:** no story listed the callers that relied on the old throw.

3. `tests/unit/Config/TelegramSenderBridgeCoverageTest.php:179`, routed **plan**.
   - **Condition:** the gate must accept only the shared `TelegramBridge::ensure()`, or a named allow-list of existing analogues. It must not accept any method called `->ensureTelegramInitialized(`.
   - **Why:** today, a new service that defines its own private `ensureTelegramInitialized()` with the old broken fallback passes the gate. That is exactly the kind of copy this spec removed.
   - **Also noted:** the scan checks only that the call is present, not that it comes before the send. The docblock says this honestly, and the behaviour test compensates for the one real sender.

4. `tests/unit/Services/PVE/PveNotificationSenderTest.php:403-413`, routed **worker**.
   - **Condition:** the -02 acceptance item "`ensure()` is called before `Request::sendMessage`" must be backed by a test whose assertion fails if the call comes after the send.
   - **What is wrong:** this test asserts only that the error line written by the bridge appears. That line appears whenever `ensure()` is called at all, so the test does not prove the order.
   - **Where the order is actually proven:** the -04 test `PveNotificationSenderNoKeyTest`. It nulls the Longman `Request::$telegram` static, and its HTTP-history assertion catches the send. So the -02 story coverage claim is true only with the -04 test included.

5. `app/TaskHandlers/PVP/StandoffExpiryHandler.php:122-140`, routed **plan**, since the file was outside every story.
   - **Condition:** the comment and the local try/catch around `$this->telegram()` must match the new contract. The comment describes the removed invalid-key `new Telegram` fallback as current behaviour. The catch is now dead code, because `telegram()` no longer throws.
   - **Why it matters:** the code carries a claim that is no longer true.

6. Reinvention that remains after this change, routed **plan**.
   - **Condition:** the remaining places that raise the bridge themselves must be either recorded in `## Descoped` as not moved to `TelegramBridge`, or moved to it.
   - **Those places:**
     - inline copies in `app/Services/Events/EventNotificationSender.php:66`, `app/TaskHandlers/Community/CommunityAutoReplyHandler.php:668`, `app/Services/Notifications/BroadcastService.php:114`, `app/Services/Player/PlayerDetectionService.php:54`, `app/Services/PVE/TowerAlertService.php:41` and `app/Services/World/ObjectSignalService.php:60`;
     - the admin controllers `PollController.php:279` and `CharacterResetController.php:32`.
   - **Why this is minor:** ask 2 named only the five copies, and the non-goals of story -01 deliberately exclude these. So this is not silent narrowing of the asks. But it is now a second primitive next to the new one, and only the -04 story Findings mention it.

7. `CommunityChatSender` admin path (the -04 Finding: `Admin\CommunityController::sendManualAnswer` never raises the bridge), routed **plan**.
   - **Condition:** this likely real, pre-existing delivery gap must be recorded in `plan.md` (`## Descoped` or a follow-up line).
   - **Why:** today it lives only in the story `## Findings` and in a warning comment in the gate exception list, where it will be forgotten.

8. `app/Services/Player/DeathService.php:47` and `app/Services/Player/Progression/LevelUpNotifier.php:39`, routed **worker**.
   - **Condition:** removing the field should not leave a double blank line in the class body. This is cosmetic.

**Accepted as recorded, no finding.**
- A failed `ensure()` is not cached, so each send attempt writes its own error line.
- `safeSend*` writes two error lines when the bridge fails: one from the bridge and `sendMessage exception`.

Both follow from the plan delta "Контракт `TelegramBridge` расширен". They are reasonable for a configuration error, and they make the failure more visible, not less.

**Claims I spot-checked.**
- The -04 notes list the mutations that turn the behaviour test red. Their mechanism matches the test code: nulling `Request::$telegram`, the Guzzle history, and the control case with a key.
- The -01 idempotency test clears the key between calls and asserts `assertSame($first, instance())`, so it would fail if `ensure()` rebuilt the bridge.
- Exception-list entries are keyed by file name, and `app/Services` has no two files with the same name, so no violator can hide behind an existing entry.
