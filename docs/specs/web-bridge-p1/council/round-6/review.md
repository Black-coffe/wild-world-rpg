<!-- seat: review · model: unknown · round: 6 · head: d1a87118 · pack: 1e74857c28ea · attempt: 1 · recorded: 2026-09-25T08:47:19Z · verdict: PASS -->
VERDICT: PASS

# Adversarial review: web-bridge-p1, round 6 (`884bb558..d1a87118`; focus on wave 8, story 16)

**Method.**
- Read story 16, the round-5 opus seat (Ask 4 RED), the round-5 review, and the plan diff `c89a4f72..d1a87118`: A18, Q11–Q13, wave 8, the round-5 plan delta, Tradeoff 16 and the VirtualChat contract line.
- Read the full story-16 app diff and its callers:
  - `TeleportBeaconSetAction:73-76,252-254` (`App\Services\Telegram\Request::sendMessage` → `WebDelivery::route`)
  - `ReferralQualifyCron:40-42` → `BaseTaskHandler::safeSendMessage` (the same seam; `isRoutineNotification()` is false, so no dedup or silent policy runs on this path)
  - `ReferralService::referrerChatId`
  - `TelegramChatResolver:60-78`
- `scope-check.sh` on story 16 flags only the hive ledgers `memory/stats/skills.json` and `memory/learnings/*`. There is no Law 3 violation.
- I re-ran the literal grep from the story and got the same hit list the Implementation notes give. I also ran two wider sweeps: every `$…tg…|telegram…|chat…|recipient… (>|<=|<) 0` in `app/`, plus SQL-level `telegram_id >` filters.
  - The only other `<= 0` guards are `$tgUserId`/`$telegramUserId` in `AchievementCheckCron:109`, `DailyTaskProgressHandler:167`, `QuestObjectiveHandler:430`, `StreakMilestoneCron:112`, `TitleCheckCron:108`, `TaxCollectionHandler:788`, `PlantCropCompletionHandler:121` and `MarchingTaskHandler:421/426`.
  - All of them test the `telegram_users.id` row id, which is positive for virtual rows. The chat check after the lookup is `=== 0`, so a virtual chat passes.
  - I found no further notice-dropping guard and no SQL-level sign filter.
- I tried one command: `vendor/bin/phpunit --no-coverage --no-progress tests/database/WebOnlyBackgroundNoticeTest.php`. It errored on schema build: table `characters` missing, and the FK `account_link_codes_character_id_foreign` was reported as incompatible.
  - At that moment a full-suite `vendor/bin/phpunit --no-coverage --no-progress` (PID 70596, presumably a council seat) was running against the same shared `wildworld_tests`.
  - The errors are a collision, not evidence against the story. My run may also have reddened that concurrent suite run; see "Not verified".

**Checked and sound:**
- Both guards now read "positive OR `VirtualChat::is()`".
  - Zero and null are still skipped, and `-1001234567890` is still refused (asserted).
  - `if ($oldOwnerTgId)` is truthy for a negative int, so the untouched action sends.
  - The Telegram path for positive ids is byte-identical.
- The spy subclass inherits the real `sendMessage` → `WebDelivery::route`, so the inbox and flag-off assertions exercise the real seam.
- The mutation claim ("each guard reverted → 2 tests red") holds on inspection:
  - Beacon: the lookup test and the capture-inbox test.
  - Referral: the notice-list test and the referral-inbox test.
  - The flag-off test and the Telegram test stay green, as expected.
- The test-schema pattern (drop and rebuild from the real migrations, FK checks off) is the existing repo pattern, used by many files in `tests/database/`.

## Critical
None.

## Major

1. `app/Views/site/account_link.php:30,52` · **plan**
   - **Status.** Carried from round-5 Major #1. The 2026-09-25 plan delta now records it as an owner-facing open item, which satisfies the "recorded" branch of the round-5 condition. No decision has been made yet, and `## Descoped` is still `(empty)`.
   - **Condition to satisfy:** before ship, the page the cabinet links a logged-in no-character player to must be truthful about what the code does under F1, or the owner decision to leave it must stand as a `## Descoped` line.

2. `docs/specs/web-bridge-p1/plan.md:88-106` (A16, A17, A18) and the plan.md `**Approved:**` line · **plan**
   - **Status.** Carried from round-5 Major #2. `**Approved:**` still covers only A0–A15, and no confirmation or veto of A16, A17 or A18 is recorded. The plan delta names this as an owner decision before ship.
   - **Condition to satisfy:** before `ship-check --record`, plan.md must carry the owner confirmation or veto of A16, A17 and A18, or each narrowing must be listed under `## Descoped`.

## Minor

3. `tests/database/WebOnlyBackgroundNoticeTest.php:278-306` · **worker**
   - **What happens.** The test re-types the send lines of the callers (`if ($oldOwnerTgId) sendMessage(...)` and the per-notice `sendMessage` of the cron). It does not drive `TeleportBeaconSetAction` or `ReferralQualifyCron`.
   - **Why it matters.** A future caller-side sign check would leave the suite green, for example `if ($oldOwnerTgId > 0)` in the action, or a guard added in `safeSendMessage`. The stated reason is "both would reach the live Longman client", but the branch already drives bot handlers through the seam and `BridgeClient` in `WebActServiceTest`/`UpdatePipeline`.
   - **Condition to satisfy:** at least the beacon-capture case must reach the inbox through the real `TeleportBeaconSetAction` callback path, so that a caller-side guard dropping a virtual id turns the test red.

4. `docs/specs/web-bridge-p1/plan.md:130-145` (Q11, Q12, Q13) · **plan**
   - **What happens.** The questions are written, but the `Recon answers` block holds no answer for Q11, Q12 or Q13. The only answer to Q13 ("any further notice-dropping guard") sits in the worker's Implementation notes. The grep used there, which the worker itself flagged, does not match the `$telegramId <= 0` or `(bool)` spellings Q13 asked about.
   - **Why it matters.** Tradeoff 16's "Recon Q13 does that grep once for this spec" is therefore not true on the record. My wider sweep (see Method) found no further dropping guard.
   - **Condition to satisfy:** plan.md must record the Q13 answer, with the sweep pattern that covers `> 0`, `<= 0`, `< 1` and `(bool)` on both chat-id and row-id spellings and its per-hit verdict, so the "latent Ask 4 hole" tradeoff rests on a recorded sweep.

5. `mmorpg-vault/tech-writing/services/ReferralService.md` and `mmorpg-vault/tech-writing/tasks/referral/ReferralQualifyCron.md` · **plan**
   - **What happens.**
     - `ReferralService.md` has `last_reviewed: 2026-06-28` and does not mention the virtual-id notice rule.
     - `BeaconCaptureService` has no note at all.
     - The story named no vault file. This continues round-5 #12 (constitutional rule 1, ADR-009).
   - **Condition to satisfy:** before ship, the notes for `ReferralService` (and the cron that sends its notices) must state that a web-only referrer gets the notice in the inbox via the seam. `BeaconCaptureService` must have a note that states `lookupOwnerTelegramId` may return a virtual (negative) id.

6. `app/Services/Player/TeleportBeacon/BeaconCaptureService.php:21,85-90` · **worker**
   - **What happens.** The class and method docblocks still describe `lookupOwnerTelegramId(charId): ?int` as a Telegram id and list the null cases without the new rule. A future caller could hand the result to a Telegram-only API that bypasses the seam.
   - **Condition to satisfy:** the documented contract of the method must state that it returns a positive Telegram id or a virtual chat id that is only safe to send through the `Request` seam.

7. Round-5 review minors #3–#11 and #13, carried unchanged at `d1a87118` · **plan**
   - **Status.** The round-5 plan delta says "Minors #3–#13 need a recorded disposition". None is recorded yet. These are:
     - #3: `touch()` creating a 0-byte copy
     - #4: cron and FPM user
     - #5: protocol-relative view cases that no longer bite
     - #6: encoded `..` checked before decode
     - #7: pre-story-14 stored `uploads/tmp` URLs on preprod
     - #8: copies with no web record
     - #9: no test that a bot-only map is not copied
     - #10: the sha1 location oracle
     - #11: the "другой вход" phrasing in the cabinet
     - #13: the round-4 carries
   - **Condition to satisfy:** each carried finding must be either fixed or recorded with its disposition in plan.md before ship.

## Not verified
- The singly-run result of `tests/database/WebOnlyBackgroundNoticeTest.php` at `d1a87118`. My run collided with a concurrent full suite on the shared `wildworld_tests` DB (PID 70596) and errored during schema build, and I did not re-run while that suite was active.
  - **Side effect to flag to the Queen:** that concurrent suite run may itself have picked up errors from the table drops and rebuilds in my run. If it belongs to a council seat, its red results in DB tests touching `characters`, `telegram_users`, `accounts` or `teleport_beacons` around 2026-09-25 ~08:2x Z should not be counted against the branch without a re-run.
- The live Tier-3 walk from the wave-8 integration gate (a Telegram character captures the beacon of the web-only character, and the bell count goes up by one). It is the Queen's walk and runs on preprod only.
- Whether a web-only player can actually become a referrer. This depends on whether `ReferralAction` on `/play` shows a working invite link for a virtual identity. I did not trace it, and without it the referral half of the fix has no reachable trigger.
