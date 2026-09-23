---
story: web-accounts-p0-07
spec: web-accounts-p0
status: todo
returned:
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

## Findings
