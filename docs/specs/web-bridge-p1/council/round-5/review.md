<!-- seat: review · model: unknown · round: 5 · head: dfaa8fcf · pack: 8c08891b6ac9 · attempt: 1 · recorded: 2026-09-25T08:01:24Z · verdict: PASS -->
VERDICT: PASS

# Adversarial review: web-bridge-p1, round 5 (`884bb558..dfaa8fcf`; focus on wave 7, stories 14 and 15)

**Method.**
- Read the plan (A12, A18, Contracts round-4 lines, Tradeoffs 14/15, Plan deltas), stories 14 and 15, and the round-4 review.
- Read the full wave-7 diff `c89a4f72..dfaa8fcf` and the context it relies on: `MapService:131-168`, `MapZoomService`, `MediaSender::photoStreamUri`, `WebDelivery::route/toInbox/characterForChat`, `AccountAuth::logout`, `LinkCodeService::MSG_OTHER`, `AccountRegister::registrationOpen`, `account_link.php`, `deploy/post-deploy.sh`, and `public/.htaccess`.
- Ran `scope-check.sh` for 14 and 15. The only hits are hive ledgers (`memory/stats/*`, `memory/learnings/*`), so there is no Law 3 violation.
- Ran one `php -r` probe of `parse_url` on the view-guard inputs (findings 5 and 6). Ran no test suite, because the close-story outcome is on record.

**Checked and sound:**
- Copies happen only on recording paths. `photoUrl` has no caller outside `WebDelivery`, and a Telegram-only player's map is never copied.
- The copy happens before the sender's `unlink`.
- Content-hash dedup works, and the rename race is handled on Windows too.
- The deploy claim in story 14 is true: `post-deploy.sh` symlinks only `public/uploads/site`.
- The `.gitignore` line was needed.
- `canRegister` reads the same `AccountRegister::registrationOpen()` that `Play` uses.
- The story-15 tests render `GET /account` with mocked cache and settings, and they would fail if the lock text regressed.
- The main story-14 tests bite. Without the copy, `photo_url` stays `base_url(.../uploads/tmp/...)` and `assertKeptCopy` fails.

## Critical
None.

## Major

1. `app/Views/site/account_cabinet.php:71` together with `app/Views/site/account_link.php:30,52` · **plan**
   - **What happens.** The cabinet now links a logged-in player straight to «Страница ввода кода». That page tells the same logged-in player «Код привяжет твой текущий вход к персонажу из бота» and «код привяжет этот вход». Entering the code there is refused with `MSG_OTHER` (F1).
   - **Why it matters.** This is the contradiction round 4 went RED on for Ask 9. It has moved one click deeper.
   - **The record.** It has been known since story 03's notes, and stories 10 and 15 excluded it on purpose. Story 15 says it is "surfaced to the owner as an open question", but no line in `## Descoped`, `## Plan deltas` or `## Needs a human` records it.
   - **Condition to satisfy:** every page the cabinet's no-character block links to must be truthful for a logged-in visitor about what the code does (F1 refuses it), or the gap must be recorded in plan.md as an owner-facing open item before ship.

2. `docs/specs/web-bridge-p1/plan.md:88-106` (A16, A17, A18) · **plan**
   - **What happens.** A18 is new in round 4 and, like A16 and A17, is marked *owner to confirm*. `**Approved:**` still covers only A0–A15, and no confirmation or veto is recorded anywhere. This carries round-4 Major #1, now widened by A18: 7-day copies, a copy directory that every release wipes, and caption-only after a deploy.
   - **Condition to satisfy:** before ship, the owner's confirmation or veto of A16, A17 and A18 must be on the record in plan.md, or each narrowing must be listed under `## Descoped`.

## Minor

3. `app/Services/Web/WebDelivery.php:639-644` · **worker**
   - **What happens.** On a dedup hit the code calls `touch($target)` after `is_file()`. If a concurrent prune deletes that copy between the two calls, `touch()` creates an empty 0-byte file.
   - **Why it sticks.** Every later send of the same content reuses that file and refreshes its mtime, so the prune never removes it. Identical map renders then show a broken image for as long as they keep being reused.
   - **Reach.** It needs a copy older than `photoKeepHours` and two concurrent requests, so it is narrow.
   - **Condition to satisfy:** a dedup hit must never create a file, and must never keep serving a copy whose content is not the source's.

4. `app/Services/Web/WebDelivery.php:641,648` · **plan**
   - **Assumed shape.** This matters only *if* cron or spark and PHP-FPM run as different OS users on testbot or prod. Nothing today sends a transient photo from the background, because `MapService` is interactive only.
   - **What happens.** A copy created by one user makes `touch()` or `copy()` fail for the other. CI4 turns the warning into an exception, `keepTransient` returns null, and the result is caption-only while a valid copy exists.
   - **Condition to satisfy:** if a background sender of transient photos ever appears, the copy directory must stay usable by both the web and the cron user.

5. `tests/unit/Views/PlayViewsTest.php:174-175` · **worker**
   - **What happens.** Story 14 said the story-13 cases keep their intent, but they no longer bite on the story-13 regex. With the new `is_file` guard, `//evil.example/x.png` resolves to the path `/x.png`, which does not exist, so the case gives no `<img>` even if the regex's negative lookahead is deleted. `/\evil.example/x.png` is rejected the same way.
   - **Where the regex is still the only guard.** A host-relative value whose path does exist is caught by the regex alone. For example, `//evil.example/uploads/x.png` has the path `/uploads/x.png`, which is the fixture this very test creates (I checked this with `parse_url`).
   - **Condition to satisfy:** the protocol-relative cases must fail when the regex guard is removed, which means they must point at a path that exists under `FCPATH`.

6. `app/Views/site/_play/state.php:80` · **worker**
   - **What happens.** The `..` rejection runs on the still-encoded path, before `rawurldecode`. `/%2e%2e/app/Config/App.php` passes the check, and `is_file` then tests a file outside `public/`.
   - **Why it is minor.** The value is server-produced today, and the browser would request the site's own `/app/...`.
   - **Condition to satisfy:** the traversal check must apply to the decoded path that `is_file` actually receives.

7. `app/Views/site/_play/state.php:77-81` (A12/A18) · **plan**
   - **What happens.** Photo screens stored before story 14 carry the absolute `base_url(.../uploads/tmp/tmp_map_*.png)`. The `is_file` guard covers site-relative paths only, so these screens still render an `<img>` that 404s, with a console error (Ask 10).
   - **Where they exist.** Preprod `web_play_state` and `web_inbox` rows left by earlier walks. The post-wave-7 Tier-3 walk ("a reload of /play still shows it in the history") will meet them.
   - **Condition to satisfy:** a stored photo URL on the site's own origin under a transient prefix must render caption-only, or the walk characters' web state on preprod must be cleared before the Tier-3 walk and that step recorded.

8. `app/Services/Web/WebDelivery.php:138` · **plan**
   - **What happens.** The virtual-chat branch calls `buildMsg` for `messageResult` even when `toInbox` wrote nothing, for example with the flag off. A transient photo sent to a virtual chat is therefore copied into `public/uploads/web/` although nothing will ever show it.
   - **Reach.** No background transient-photo sender exists today, so impact is nil for now. The plan contract ("whenever the seam builds a Msg") permits it.
   - **Condition to satisfy:** a transient photo is copied only when a web record (screen, inbox) is actually written.

9. `tests/database/WebDeliveryTest.php:510` · **plan**
   - **What happens.** The Ask-5 test proves that a bot-only transient photo reaches `parent::send()` unchanged. It does not prove that no copy is made. A regression that copied every Telegram player's map into the public directory, costing disk and a `glob` prune per map tap, would stay green.
   - **Condition to satisfy:** a transient photo sent to a chat with no web record must leave `public/uploads/web/` unchanged, and a test must show it.

10. `app/Services/Web/WebDelivery.php:624-673` (A18) · **plan**
    - **What happens.** Map copies are public, content-addressed files that live up to 7 days. A map render is deterministic per coordinate and map type, so anyone holding the base map can compute candidate sha1 names and probe `/uploads/web/<sha1>.png`. That learns which coordinates some web player viewed in the last week.
    - **Why it is minor.** Directory listing is off (`public/.htaccess` `-Indexes`), and the files say nothing about who viewed them.
    - **Condition to satisfy:** the owner must accept this location oracle under A18, or the copy name must not be derivable from the image content alone.

11. `app/Views/site/account_cabinet.php:63` · **worker**
    - **What happens.** The notice reads «Этот вход — другой: выйди из другого входа и введи код». Inside the cabinet, "this login is the other one, log out of the other one" reads as self-contradictory. The canonical `/web` phrase names the site login as "the other" only from the bot's point of view.
    - **Condition to satisfy:** the cabinet's instruction must say unambiguously that the player logs out of the current site login and then enters the `/web` code.

12. `mmorpg-vault/tech-writing/` · **plan**
    - **What happens.** This carries round-4 #14. There is still no tech-writing note for `WebDelivery` or the other web-play services, controller and filter. Wave 7 changed `WebDelivery` again (the A18 copy directory and prune) and `AccountCabinet`. Constitutional rule 1 (ADR-009) requires the notes, and plan delta round 4 assigns them to `drone-docs` before ship.
    - **Condition to satisfy:** before ship, each new or changed service, controller and filter must have its note, and the notes must cover A16, A17 and A18.

13. Carried from the round-4 review, unchanged at `dfaa8fcf` · **plan**
    - **Status.** Findings #2–#10, #12, #13 and #15. Plan delta round 4 records them as "not asks, no story", and none is addressed.
    - **Round-4 #11 (tab/LF protocol-relative `src`).** It is now incidentally mitigated by the story-14 `is_file` guard, except where the stripped path names an existing local file.
    - **Condition to satisfy:** each carried finding must be either fixed or recorded with its disposition in plan.md before ship.

## Not verified
- Whether cron and PHP-FPM run as the same OS user on testbot and prod (finding 4).
- Whether the preprod `web_play_state` and `web_inbox` rows from earlier walks still hold `uploads/tmp` URLs (finding 7). I did not query preprod.
- Actual map PNG size and weekly map-view volume of web players, which bound the copy directory and the per-copy `glob` prune.
