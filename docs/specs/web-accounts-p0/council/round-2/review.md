<!-- seat: review · model: unknown · round: 2 · head: 5dbc60ee · pack: 1ed6015b3754 · attempt: 1 · recorded: 2026-09-24T08:29:22Z · verdict: PASS -->
VERDICT: PASS

Adversarial review: web-accounts-p0, round 2. Diff c0026dad..5dbc60ee on vulyk/web-accounts-p0: wave-4 fix stories 09, 10 and 11, plus plan delta 4d1fa180. Stories 01-08 were reviewed in round 1. Their open findings are carried below, not re-derived.

## Scope
Clean. Every code and test file in 6f823f0c (09), 4ae0c2e6 (10) and 7c4aed91 (11) is named in that story's `## Files`. `AccountRegistrationTest.php` joined story 10 through the recorded 2026-09-24 plan delta. Nothing else was touched apart from cycle paperwork (`docs/specs/web-accounts-p0/*`, `memory/stats/*`).

## Tests run
None. Nothing in the diff made a test result doubtful. I checked the tests for theatre by mentally mutating the code:
- Story 11: if the old one-line `ensureForTelegram` comes back, both new tests fail (`assertAtMostOneCharacterPerAccount`). Confirmed.
- Story 09 nonce test: accepting a missing nonce changes `identitySnapshot()`, and not consuming the nonce trips the "nonce is spent" assert. Both are caught.
- Story 10 log test: `TestLogger::$op_logs` stores the interpolated message (`system/Test/TestLogger.php:40`), and the throwing double quotes the address. So the "no email in log" assertion is real.

Round-1 findings #1, #2, #3 and #5 are closed as their conditions asked. #4 is closed, but through a new ADR contradiction (major 2 below).

## Critical
None.

## Major

1. `app/Controllers/Telegram/Commands/Actions/WebLinkCodeAction.php:67-68`, `app/Database/Migrations/2026-12-10-100020_SeedWebLinkTip.php:31-34`, `app/Services/Onboarding/GuideCatalog.php:1019-1021`, `app/Views/site/account_link.php:30,52`, `app/Views/site/account_cabinet.php:58-59` - **plan**
   Problem: all five player surfaces still promise the removed A2 merge. The bot's code message, the seeded tip, the `/guide` "web" section and the `/account/link` page each say that a code entered while logged in by email, Google or Yandex "привяжет этот вход к персонажу". The character-less cabinet sends its main button, "Привязать персонажа из бота", to that page. Under F1 (story 09) every one of those paths now ends in `MSG_OTHER` refusal.
   Record: story 09's worker flagged `account_link.php:30` in its Implementation notes. No plan delta or story picked it up, and the other four surfaces were never named.
   Condition: every player-facing description of the bot code (bot message, tip, guide, link page, cabinet) must match the F1 rule (logged out: enter the character's account; logged into another account: log out first), and none may offer code entry as a way to attach a bot character to the account currently logged in. The tip seed is idempotent by `title_en`, so the corrected text must also reach any DB where the seed already ran.

2. `app/Services/Player/CharacterProvisioningService.php:135-139` (and plan F2 / `## Contracts` `CharacterProvisioningService`) - **plan**
   Problem: the F2 path creates the bot character's account with `createAccount('telegram')` + `attachCharacter` and no identity. `CharacterProvisioningServiceTest` even asserts 0 identities. This contradicts ADR-188 invariant 5 ("у аккаунта всегда 1+ идентичность", every account always has at least one identity).
   Consequence: after a CHARACTER_RESET wipe (A6 keeps accounts), that account has neither an identity nor a character, and nothing can ever reach it again.
   Record: neither F2 nor any ADR amendment mentions the invariant.
   Condition: ADR-188 and the shipped F2 behaviour must agree. Either invariant 5 is amended to allow an identity-less account reachable only by bot code, with its wipe consequence stated, or no account is ever left without an identity.

## Minor

3. `docs/specs/web-accounts-p0/plan.md:41-44`, `:209` - **plan**
   Problem: F1-F3 supersede the owner-approved A2 and change player-visible behaviour: code refusal, the orphaned email, and a second account for the bot character. They are still marked "owner to confirm" / "pending owner confirmation". Nothing on record in plan.md or journal.md shows that confirmation happened.
   Condition: the owner's confirmation of F1-F3 (or their veto) must be on record before ship.

4. `app/Controllers/AccountCabinet.php:26` (`link_refused` text) - **worker**
   Problem: the refusal tells the player "сначала отвяжи его там". In the common case the other account is a bot player's account whose only identity is Telegram, and the server forbids unlinking a last identity (ADR-188 inv. 5). So the notice instructs an impossible action.
   Condition: the refusal must describe a path the server actually allows.

5. `app/Controllers/AccountLink.php:46-50`, `app/Services/Web/LinkCodeService.php:156` - **worker**
   Problem: on `STATUS_NOOP` the controller redirects to `/account` with no `?auth=` notice, so `MSG_NOOP` is never shown. The player types a code and gets a silent redirect. The code also stays live for its TTL, although the player may think it was used.
   Condition: a no-op code entry must tell the player what happened.

6. `app/Services/Player/CharacterProvisioningService.php:126-129`, `app/Services/Web/AccountService.php:213-217` - **worker**
   Problem (reinvention): the character-to-`telegram_users.telegram_id` lookup is implemented again. `App\Services\Telegram\TelegramChatResolver::chatIdForCharacter()` (`app/Services/Telegram/TelegramChatResolver.php:28`, story 02) already resolves it. `accountForTelegramLogin` also copies the private lookup from `ensureForTelegram` (`AccountService.php:46`). There are now three copies.
   Condition: the telegram_id resolution must go through one existing primitive.

7. `app/Services/Player/CharacterProvisioningService.php:124-141` with `app/Controllers/AccountRegister.php:115` - **plan**
   Problem: the F2 guard is check-then-attach with no lock. `AccountRegister` takes a per-account `GET_LOCK`, but the bot path does not. A `/start` running at the same moment as a web character creation on the account holding that Telegram identity can still attach two characters.
   Configuration: minor only because it needs one player acting from two clients within the same instant, on the single-node app.
   Condition: the one-character-per-account guarantee must hold when a bot `/start` and `/account/character` run at the same time for the same account.

8. `app/Controllers/TelegramLogin.php:87-100` - **plan**
   Problem: the logged-out widget callback is still a bare GET. Anyone can make a visitor log into the account named by the attacker's own signed payload (login CSRF, 24 h replay window). Since F1, the victim's later cabinet actions (add email+password, link Google/Yandex) bind the victim's identities into the attacker's account, where the victim cannot reclaim them.
   Record: this behaviour existed before story 09. F1 only changed its consequences.
   Condition: either a logged-out widget login must be tied to a flow the visitor started, or the risk must be recorded as accepted.

9. `app/Controllers/AccountCabinet.php:137-140`, `app/Services/Web/AccountSession.php:136-142` - **plan**
   Problem: every render of `/account` mints a new nonce and replaces the previous one. A Telegram link begun in an earlier cabinet tab, or after any other `/account` load, fails with `link_unconfirmed`.
   Condition: a link started from a cabinet page the same session rendered must not fail because another cabinet page was rendered later, or the limitation must be recorded.

Carried over from round 1, unchanged at 5dbc60ee. None of these is in `## Descoped`; plan delta 2026-09-23 parks them for a Queen or owner call.
10. #6 `app/Views/site/_layout/meta.php:75` + `/account/reset/{token}`: a live reset token is in the URL of a page that runs third-party analytics - **plan**
11. #7 pre-account-takeover through email squatting is neither closed nor recorded next to A4 - **plan**
12. #8 `TelegramLogin.php:88` calls `accountForTelegramLogin`, which calls `ensureForTelegram` and still creates an account for a never-played Telegram user while `web.open_registration=false` (ADR-188 inv. 8) - **plan**
13. #9 the link code can be delivered into a non-private chat - **plan**
14. #10 `AccountSession` remember-rotation race: the losing request clears the winner's cookie - **worker**
15. #11 `AccountRegister.php:115` ignores the `GET_LOCK` result - **worker**
16. #12 `AccountRegister.php:132` reads `web.open_registration` without `gsBool` - **worker**
17. #13 `row()`/`toInt()` normaliser is copied in `AccountService:252-283`, `AccountSession:329-347`, `LinkCodeService:203-221` and `PasswordResetService:201` - **worker**
18. #14 the cabinet changes the email password without asking for the current one - **plan**
19. #15 a throttle 429 always renders the login page - **worker**
20. #16 the per-IP throttle bucket has no `$proxyIPs` - **plan**
21. #17 `mmorpg-vault/tech-writing/` has no note for any `Account*`/`LinkCode*`/`PasswordReset*`/`CharacterProvisioningService`/new controller. None contains `AccountService` as of now, and wave 4 changed four of those services again (CLAUDE.md rule 1, ADR-188 "Инварианты (в tech-writing)") - **plan**
22. #18 `app/Config/WipeManifest.php:221` `queue_jobs` line lost its alignment - **worker**

## Checked and found sound
- `grep mergeInto app/` is empty.
- `link_nonce` is stripped before `verify()`. The claim that `TelegramLoginVerifier.php:58-82` signs every scalar key except `hash` is true, so the verifier really had to stay untouched.
- The nonce check: hex from `random_bytes(16)`, `hash_equals`, spent on every logged-in callback, cleared on logout. No logged-in bare callback creates an account or an identity.
- `accountForTelegramLogin` returns null only when the identity is missing and the Telegram user's character is attached. The legacy session upgrade and the widget handle null without creating anything.
- `LinkCodeService::link` refuses and no-ops before `redeem()`, so the code stays unspent. The logged-out claim stays atomic.
- `/account/reset`: one mode `requested` for every outcome. The page does not echo the email. The log line interpolates the account id only and strips the address from the reason case-insensitively. Token rules are unchanged.
- `Services::email()` + `clear(true)` gives the same transport with a clean state per send.
- The story 11 guard leaves the existing bot path (`ensureForTelegram`) byte-identical when the identity holder has no other character, and the existing provisioning tests are unchanged.
