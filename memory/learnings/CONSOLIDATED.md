# Consolidated learnings

<!-- Maintained by the librarian GC pass. Max 40 entries, newest evidence wins.
     Last GC: 2026-09-29. Sources are in parentheses. -->

## Data, settings, kill switches
1. **A value whitelist next to a range filter can match nothing.** `price >= 5000 AND type IN (whitelist)` returned zero rows, because the most expensive allowed item in the live catalog costs 500. The "beat a strong NPC" branch silently died. Before you set defaults, run one `SELECT COUNT(*)` with both conditions on real data. "Behaviour unchanged" is a claim about the intersection, so check it. (2026-08-19 pve-reward-pool)
2. **A kill switch that promises "back to how it was" needs a fixed reference point.** `type_filter_enabled=off` split the pool by the tunable threshold. Once the threshold was lowered from 5000 to 100, turning the switch off would have handed out 95k robots. Use a code constant with a comment explaining why it is NOT in GameSettings. Recheck the promise whenever any parameter it depends on changes. (2026-08-19)
3. **A missing `game_settings` table leaks builder state.** `GameSettingsService` reuses one `GameSettingsModel`. `findByKey()` throws inside `Model::first()` before the builder is reset, so each later `where()` stacks conditions and memory grows. This happens on prod during the deploy window: rsync ships the code first and `deploy/post-deploy.sh` migrates afterwards. Fix: `try/finally` reset, or a fresh instance per call. When memory grows, run `php spark migrate:status` before reaching for a profiler. (2026-08-26)

## Tests
4. **Tests that mock every setting explicitly do not cover the defaults.** Add one case with no overrides. Add another that runs the migration and compares the seeded values with the code constants. (2026-08-19)
5. **MockCache ignores TTL.** `get()` never checks expiry, and `getMetaData()` has the condition inverted (it returns null for a live entry). You cannot test expiry in unit tests, with or without `Time::setTestNow()`. Test that caching happens instead: count SQL through the `DBQuery` event, so N reads should produce 1 query. You may swap a tool that lies. Do not weaken the assertion. (2026-08-26 mockcache-ttl, story 61 lost 2 rounds)
6. **Tests that call `telegram()` must stub the bridge.** Two tests passed locally only because `.env` held a key in a valid format. They failed on CI. (2026-09-11)
7. **Longman `Request::send()` short-circuits under PHPUnit** (`Request.php:698-702`). Code that captures actors or messages must hook the app's `Request::send()` seam, not only `BridgeClient`. (web-bridge-p1)
8. **False-red suites come from shared test state.** Causes seen: a council seat left `php -S` running, and workers ran DB tests on `wildworld_tests` at the same time. (web-accounts-p0)
9. **Name a cause only if it exists.** One diagnosis cited `BaseConnection::$saveQueries`, which does not exist in our CI4 version. Grep before you name something. (2026-08-26)

## Cron, Telegram, prod observability
10. **Sends from cron fail silently, and this is a class of bug.** The Telegram bridge is not initialised in CLI cron (`getBotUsername() on null`). Seen 3 times: community-chat-bot (08-25), the standoff expiry ping, and `AutoPveHandler -> PvEService::attack() -> PveNotificationSender`. The battle is recorded and the player's message is lost. Any path that sends outside `BaseTaskHandler` is suspect. (2026-09-11)
11. **`BaseTaskHandler::telegram()` fallback crashes.** Its `catch` runs `new Telegram('invalid','invalid')`, and that constructor throws too. Any of the ~70 handlers dies without a key. (2026-09-11)
12. **Prod log threshold is 4, so `warning` is never written.** A missing prod log line does not prove a code path did not run. Check where the threshold is 9. (2026-09-11)
13. **Tier-3 live passes find defects at the seam between an ADR and a neighbouring subsystem.** Diff review and tests miss them. Two findings came after 26 stories, 3 lead-reviews and blind acceptance. (2026-09-11)

## Web bridge
14. **Guards on player-notice paths must accept `VirtualChat::is()` as well as `chat_id > 0`.** A plain `> 0` check drops web players from hooks and notices. (ADR-189 amendments 2026-09-25)

## VULYK process
15. **`## Verification` lines must match a `## Commands` table cell byte for byte.** `close-story` and cycle.sh reject per-file phpunit and curl lines, and curl exits 0 on HTTP 500 anyway. Put the full suite, phpstan and migration lint on record. Iterate on per-file tests off the record. Recurred in bugs-info-0923, multibase-picker, web-accounts-p0 and web-bridge-p1, so it is a `/vulyk-evolve` candidate.
16. **Overlaps in `## Files` come from the story layout.** Stories -27 and -28 both named `StandoffNotifier.php` and ran in parallel. Check for overlaps before launching a wave. (2026-09-11)
17. **Council seats return empty on large specs.** On a 68-file spec, seats came back empty in rounds 1-4 because the turn cap was too low. Split the spec or raise the seat budget before round 1. (web-bridge-p1)
18. **Council rounds are expensive** (~4M tokens per 3-round circuit). For a red ask that only needs text changes, a fix story plus a human check beats reopening. (web-bridge-p1)
