<!-- seat: review · model: unknown · round: 1 · head: c0026dad · pack: 14c02cf5e190 · attempt: 2 · recorded: 2026-09-23T20:25:23Z · verdict: BLOCK -->
VERDICT: BLOCK

Adversarial review: web-accounts-p0, round 1. Diff c44e1780..c0026dad on vulyk/web-accounts-p0, 8 stories.

## Scope
Clean. I checked each story commit's files against that story's own `## Files` list. Every code, test and config file is named by the story that touched it, after the recorded plan deltas for 02/04/06. The files outside any story are cycle paperwork only: `docs/specs/web-accounts-p0/*` and `memory/stats/*`.

## Tests run
None. The blocking finding comes from reading the code, and the existing `AccountAuthTest::testWidgetLinkWhileLoggedInWithoutCharacterMergesIntoCharacterAccount` already runs green through the same GET-callback merge path. Re-running it would only show the vulnerable behaviour working as designed.

## Critical

1. `app/Controllers/TelegramLogin.php:76-88`, `:111-123` - **plan**
   Problem: the widget callback is a bare GET and is not bound to the session. When a logged-in visitor with a character hits it carrying a Telegram payload whose account has no character, `linkTelegram()` → `mergeInto($telegramAccount, $currentAccount)` moves that foreign Telegram identity into the visitor's account.
   Attack: an attacker takes a signed payload for their own spare Telegram account (valid for 86400 s, see `TelegramLoginVerifier::MAX_AGE_SECONDS`), sends a logged-in player the callback link (`next=` hides it), and can then log in with the widget as the victim. That gives them the victim's character, the `/map` own-position block and the cabinet (add email+password, unlink the victim's other methods).
   Record: this also breaks ADR-188 invariant 4 («Привязка Telegram, уже закреплённого за другим аккаунтом, — отказ, не перенос и не слияние»). The ADR names that invariant as the thing that closes the account-takeover risk.
   Configuration: none needed. The widget is always on and the flag does not matter.
   Condition: a widget callback that the logged-in session did not provably start (for example a single-use, session-bound value that a third party cannot forge) must never attach or merge a Telegram identity into an already-logged-in account. A bare callback may at most log the visitor into the payload's own account. The plain logged-out widget login must keep working.

## Major

2. `app/Services/Web/LinkCodeService.php:167-176`, `app/Services/Web/AccountService.php` `mergeInto()`, plan A2 - **plan**
   Problem: plan A2 (and story 05/06) turns code-linking and widget-linking into account merges. ADR-188 says «Понадобится слияние двух аккаунтов — отдельный ADR; сейчас это отказ» and invariant 4 says refuse.
   Condition: ADR-188 and the shipped behaviour must agree. Either the ADR is amended to authorise the A2 merge together with its security argument, or the merge paths are replaced by refusal.

3. `app/Services/Web/PasswordResetService.php:62-108`, `app/Controllers/AccountPassword.php:39-41` - **plan**
   Problem: `mail_failed` can only happen for an email that exists, so while prod SMTP is broken (plan A10 expects this) the page is an exact oracle for which emails have accounts. The contract in plan.md defines exactly this split.
   Condition: `/account/reset` must give a response for an unknown email that cannot be told apart from a known one, whether sending succeeds or fails. The honest failure notice with the bot-code alternative must still reach real users.

4. `app/Services/Web/AccountService.php:71-74` with `app/Services/Player/CharacterProvisioningService.php:81-85` and `app/Controllers/AccountRegister.php:98-123` - **plan**
   Problem: any Telegram user who has never played gets a character-less account with a telegram identity at their first widget login. They can create a web character on `/account/character`. If they later `/start` the bot, `ensureForTelegram` attaches the new bot character to the same account, so the account owns two characters. That breaks plan A1.
   Configuration: major if `web.open_registration` is ever turned on. It cannot be reached while the flag is off.
   Condition: an account must never own more than one character, on any sequence of widget login, web character creation and bot `/start`.

5. `app/Controllers/TelegramLogin.php:76`, `app/Services/Web/AccountSession.php:92` - **plan**
   Problem: after the player unlinks the Telegram identity, the next widget login (or a legacy-session upgrade) calls `ensureForTelegram`. That creates a new empty `telegram` account and logs into it, so the player seems to have lost their character. Plan A3 says unlinking «only removes widget login».
   Record: the gap appears only in story 07's `## Implementation notes` («A3 side effect worth a look»), not in `## Descoped` or `## Plan deltas`.
   Condition: after its Telegram identity is unlinked, a Telegram login must not make a character-less account that silently shadows the character's account. Otherwise the narrowed A3 behaviour must be recorded in `## Descoped` or `## Plan deltas`.

## Minor

6. `app/Views/site/_layout/meta.php:75` with `app/Controllers/AccountPassword.php:44` (`/account/reset/{selector-validator}`) - **plan**
   Problem: the password-reset page URL carries a live reset token and loads the third-party Statable script, so the token reaches the analytics provider if it records page paths (I did not verify that).
   Condition: a live reset credential must not appear in the URL of a page that runs third-party analytics.

7. `app/Services/Web/AccountAuthService.php` `registerWithEmail()` / `setEmailPassword()` - **plan**
   Problem: there is no email verification (A4 accepts that), but the consequence is not recorded. Anyone can squat a victim's email and pre-attach their own Google or Yandex. The victim later "resets the password" into an account the attacker still controls.
   Configuration: harmful once `web.open_registration` is on.
   Condition: the pre-account-takeover risk must be either closed (for example, a successful reset drops login methods the email owner never proved) or recorded as an accepted risk next to A4.

8. `app/Controllers/TelegramLogin.php:76` - **plan**
   Problem: the widget creates a new account for any Telegram user even while `web.open_registration=false`. ADR-188 invariant 8 says registration stays closed and is checked on the server in every entry route.
   Condition: either no account is created without the flag for a Telegram user who has no character, or ADR-188 records that widget account creation is exempt.

9. `app/Controllers/Telegram/Commands/WebCommand.php:26-49`, `Actions/WebLinkCodeAction.php:48-57` - **plan**
   Problem: the code works as a login to the whole account, and it is posted into whatever chat `/web` was typed in.
   Configuration: minor if the game bot is ever present in a group chat. Nothing in the bot guards chat type.
   Condition: a link code must only be delivered in the player's private chat with the bot.

10. `app/Services/Web/AccountSession.php:205-211` - **worker**
    Problem: when two requests present the same remember cookie, the losing request calls `expireCookie()`. If its response arrives last, it deletes the cookie the winning request just rotated in, and remember-me is silently dropped.
    Condition: a request that loses the rotation race must not clear the cookie the winner just issued.

11. `app/Controllers/AccountRegister.php:115` - **worker**
    Problem: the `GET_LOCK` result is ignored, so when the lock times out the one-character check runs unguarded.
    Condition: character creation must not continue when the per-account lock was not acquired.

12. `app/Controllers/AccountRegister.php:130-135` - **worker**
    Problem (reinvention): it re-implements bool coercion of `web.open_registration` over `GameSettingsService::get`, while `AccountOAuth` reads the same flag through `App\Services\GameSettings\GameSettingsReaderTrait::gsBool`. The plan contract names `gsBool`.
    Condition: the flag must be read through one existing primitive everywhere.

13. `app/Services/Web/{AccountService,AccountSession,AccountAuthService,LinkCodeService,PasswordResetService}.php` - **worker**
    Problem (reinvention): five private copies of the same `row()/rows()/toInt()` result normaliser.
    Condition: the new services must share one normaliser.

14. `app/Services/Web/AccountAuthService.php:115-121` - **plan**
    Problem: the cabinet changes the password of the account's own email without asking for the current password, so any live session (including a remember-me restore) can re-key the email login.
    Condition: changing an existing email password from the cabinet must require the current password or a fresh login.

15. `app/Filters/AccountThrottleFilter.php:71-86` - **worker**
    Problem: a 429 on `/account/link`, `/account/register`, `/account/reset` or `/account/character` always renders the login page, not the form the user was on.
    Condition: the throttled response must keep the user on the form they submitted.

16. `app/Filters/AccountThrottleFilter.php:37` with `app/Config/App.php:179` (`$proxyIPs = []`) - **plan**
    Problem: the IP bucket uses `getIPAddress()` and no proxies are configured.
    Configuration: only if wildworld.fun sits behind a reverse proxy or CDN. Then every visitor shares one bucket of 10 POST/min.
    Condition: the per-IP bucket must key on the real client IP in the prod topology.

17. `mmorpg-vault/tech-writing/` - **plan**
    Problem: there are no notes for `AccountService`, `AccountSession`, `AccountAuthService`, `LinkCodeService`, `PasswordResetService`, `OAuthProviderFactory`, `YandexOAuthProvider`, `TelegramChatResolver`, `CharacterProvisioningService`, the new controllers or the four new models. CLAUDE.md rule 1 and ADR-188 («Инварианты (в mmorpg-vault/tech-writing/)») require them.
    Condition: tech-writing notes for every new or touched model, service, controller and handler must exist before ship.

18. `app/Config/WipeManifest.php:221` - **worker**
    Problem: the edit to the `queue_jobs` line lost its alignment (`self::TRANSIENT,` directly followed by the `note` key, no space).
    Condition: unrelated manifest lines keep their original formatting.

## Checked and found sound
- `AccountService::unlinkIdentity` refuses the last method on the server under a row lock.
- OAuth state is session-bound and single-use. Yandex PKCE S256 is checked against the installed league/oauth2-client 2.9.1. Google subject is `sub` (oauth2-google 5.0.0 `GoogleUser::getId`).
- Link codes are stored as sha256 only and claimed atomically. Older codes are deleted on reissue.
- Passwords go through `password_hash`/`password_verify` with a dummy-hash timing guard.
- Global CSRF covers every `/account` POST.
- The migrations guard duplicates and are idempotent.
- The worker-side handlers write state before the null-chat skip. `CraftCompletionPortableTeleportHandler` was already null-safe, so it was rightly left alone.
- WipeManifest classes match ADR-188.
- The `.env.example` additions hold no secrets.
