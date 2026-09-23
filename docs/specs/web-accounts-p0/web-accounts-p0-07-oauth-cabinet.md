---
story: web-accounts-p0-07
spec: web-accounts-p0
status: done
returned: DONE
tier: 3
worker: worker-code
model: opus
tracer: false
wave: 3
blocked_by: [web-accounts-p0-01, web-accounts-p0-05]
---

# Google and Yandex login, and the account cabinet

## Goal
Google and Yandex become login methods, and the cabinet lets a player choose which methods to
keep.
- **Google** runs on `league/oauth2-google` ^5.0.
- **Yandex** runs on an in-house `YandexOAuthProvider` built on `league/oauth2-client` ^2.9, using
  the endpoints in recon.md §E. The worker re-verifies them against the Yandex ID docs at build
  time.
- **OAuthProviderFactory** builds both providers from the env vars in `## Contracts`.
- **`/account/oauth/{google|yandex}`** stores `oauth_state`, `oauth_intent` and, for Yandex,
  `oauth_pkce` (S256). The callback checks state and verifier, then:
  - logs in by `(provider, subject)`;
  - or links the identity to the logged-in account;
  - or, for an unknown identity, creates an account with it and sends the player to
    `/account/character` when `web.open_registration` is on. When the flag is off it answers that
    there is no account and points to the bot code.
- **The OAuth buttons partial** renders on the login page and in the cabinet. When a provider has
  no env it shows the button as unavailable, with the reason from `unavailableReason()`.
- **`/account`** becomes the full cabinet: character, identities, "add email+password", "unlink"
  (never the last one), a Telegram widget to link, a link to `/account/link`, and logout.

## Requirements
> Google аунтификация + Yandex аунтификация
> где любой метод должен біть доступен по мере желания игроком

## Files
- composer.json
- composer.lock
- app/Services/Web/OAuthProviderFactory.php
- app/Services/Web/YandexOAuthProvider.php
- app/Controllers/AccountOAuth.php
- app/Controllers/AccountCabinet.php
- app/Views/site/account_cabinet.php
- app/Views/site/account_oauth_buttons.php
- app/Views/site/account_login.php
- .env.example
- tests/unit/YandexOAuthProviderTest.php
- tests/database/AccountCabinetTest.php

## Non-goals
- Do not use `aego/oauth2-yandex` (abandoned 2018, recon §E). Do not add other providers.
- Do not register the OAuth apps or put real ids/secrets anywhere. Only empty `.env.example` keys.
- No character-creation page (story 08) and no link-code logic (story 06); only links to them.
- Do not change `Routes.php` or `wildworld-ui.css`. Report a missing component in Findings.
- Do not hide the buttons when env is empty (Ask 10). Do not auto-merge an OAuth identity that
  already belongs to another account (plan A2: refuse).

## Map slice
`memory/map/website.md`; recon.md §D, §E (library facts, Yandex endpoints, env var names).

## Acceptance criteria
- [ ] Worker runs its own new test file(s) singly while iterating; the close-story gate is the full suite + phpstan + migrations lint.
- [ ] Ask 9: cabinet views use only `wildworld-ui.css` tokens; no horizontal scroll at 375/768/1440.
- [ ] Ask 15: the callback rejects a missing or mismatched `state` (a test for each provider).
      The Yandex authorize URL carries `code_challenge` + `code_challenge_method=S256`, the token
      request carries the matching `code_verifier`, and the state/verifier are single-use (cleared
      from the session).
- [ ] Ask 10: with the env vars empty, both buttons render as unavailable with an explanation and
      are not links. Email+password stays available. With the env set, they are active links.
- [ ] Ask 2: from the cabinet a player can add email+password
      (`AccountAuthService::setEmailPassword`), Google, Yandex and Telegram. Every identity except
      the last one can be unlinked. Unlinking the last one is refused with a readable message, and
      a test covers it.
- [ ] Ask 2 / Ask 4: a known `(provider, subject)` logs into its account. An unknown one creates
      nothing while the flag is off and says how to get in. With the flag on, it creates an account
      plus identity and redirects to `/account/character`.
- [ ] Ask 9: the cabinet and login pages have no horizontal scroll at 375/768/1440, use only
      story-03 components, and add no console errors besides third-party widget ones already
      present.
- [ ] The Yandex userinfo call sends `Authorization: OAuth <token>` and maps `id` to the subject
      (unit test with a mocked HTTP client).

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
`git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`


## Implementation notes
- composer: `league/oauth2-client` 2.9.1 + `league/oauth2-google` 5.0.0 (only these two locked, no other package moved).
- `YandexOAuthProvider` (AbstractProvider): PKCE S256 via the library's own `getPkceMethod()`; `Authorization: OAuth <token>`; subject = `id`; empty `scope` and `approval_prompt` are dropped from the authorize URL (app registration defines rights).
- Yandex endpoints could NOT be re-fetched from yandex.ru docs at build time (the page answers "Доступ заборонено" from this machine). Cross-checked instead against SocialiteProviders/Yandex source (same authorize/token/`login.yandex.ru/info` URLs, `id` as subject); that source sends `Bearer`, recon's T1 says `OAuth` — story's `OAuth` kept. PKCE S256 rests on recon's single T1 fetch.
- `AccountOAuth` gets the factory via `Factories::get('libraries', OAuthProviderFactory::class)` so the DB test injects a factory with a Guzzle MockHandler (factory forwards `$collaborators` to providers).
- `AccountAuth.php` is not in this story, so OAuth errors for a logged-out visitor are rendered by `AccountOAuth` itself on the `account_login` view (reusing `AccountAuth::meta()`), not via `?auth=` codes. Cabinet messages use `?auth=` codes in `AccountCabinet::AUTH_NOTICES`.
- `web.open_registration` read with `GameSettingsReaderTrait::gsBool` (default false); the test flips it through the cache box.
- OAuth buttons partial builds its own `OAuthProviderFactory` (login page data comes from `AccountAuth`, which this story does not touch); in the cabinet it hides providers already linked.
- The cabinet shows the "Отвязать" button only when the account has 2+ identities; the server refuses the last one regardless (`unlink_last`).
- Surprising: wave-3 workers ran DB tests at the same time on the shared `wildworld_tests` (table exists / doesn't exist flapping). My own test file and the full suite were run on a private scratch schema `wildworld_tests_s07` (`env 'database.tests.database=wildworld_tests_s07' vendor/bin/phpunit ...`); it is left on local MySQL, empty.
- Not verified here: Tier-2 (no horizontal scroll at 375/768/1440, console) — no browser in this worker; views use only story-03 classes (`.identity-*`, `.provider-*`, `.notice`, `.auth-form`, `.btn sm ghost`, `.badge`).
- A3 side effect worth a look: after the Telegram identity is unlinked, a later widget login creates a new empty account for that Telegram (`ensureForTelegram`), not the character's account.

## Findings
