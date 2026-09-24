---
story: web-accounts-p0-05
spec: web-accounts-p0
status: done
returned: DONE
tier: 3
worker: worker-code
model: opus
tracer: false
wave: 2
blocked_by: [web-accounts-p0-01, web-accounts-p0-03]
---

# Auth core: sessions, remember-me, email+password login, throttle, routes

## Goal
The site has one login system keyed by `account_id`. The APIs of `AccountSession` and
`AccountAuthService` follow plan.md `## Contracts`.
- `AccountSession` writes the session keys `account_id`, `character_id` and the legacy
  `tg_user_id`, restores logins from the `ww_remember` selector/validator cookie (hashed in
  `account_tokens`, rotated on use), upgrades old `tg_user_id`-only sessions, and logs out.
- `AccountAuthService` verifies email+password with `password_verify`.
- `/account/login` offers email+password always, plus the existing Telegram Login Widget.
- The widget (`TelegramLogin.php`) now logs in through the account's `telegram` identity. When the
  visitor is already logged in, it links that identity under the merge rule of plan A2.
- `AccountThrottleFilter` (alias `accountThrottle`, CI4 Throttler, per IP and per identifier)
  guards form POSTs.
- The whole `/account` route group from `## Contracts` is declared here.
- `/account` renders a stub cabinet: character name and a logout button.
- `Map`, `Profile`, `Achievements` and `Battles` read the character through `AccountSession`
  instead of `tg_user_id`.

## Requirements
> Email + пароль (Рекомендую)
> все єто связівает единая систтема авторизации

## Files
- app/Services/Web/AccountSession.php
- app/Services/Web/AccountAuthService.php
- app/Filters/AccountThrottleFilter.php
- app/Config/Filters.php
- app/Config/Routes.php
- app/Controllers/AccountAuth.php
- app/Controllers/AccountCabinet.php
- app/Controllers/TelegramLogin.php
- app/Controllers/Map.php
- app/Controllers/ProfileController.php
- app/Controllers/AchievementsController.php
- app/Controllers/BattlesController.php
- app/Views/site/account_login.php
- app/Views/site/account_cabinet.php
- tests/database/AccountSessionTest.php
- tests/database/AccountAuthTest.php

## Non-goals
- Do not implement the controllers of stories 06-08 (`AccountLink`, `AccountOAuth`,
  `AccountRegister`, `AccountPassword`). Declare their routes only.
- No OAuth buttons on the login page and no identity management in the cabinet (story 07).
- No password reset flow (story 08). The login page may link to `/account/reset`.
- Do not change `app/Config/Session.php` (FileHandler, 7200 s stays). The long life comes from
  remember-me.
- Do not touch the webhook `TelegramRateLimitFilter`, the CSRF config, or admin `Login`/`users`.
- Do not add CSS. Use the story-03 classes. A missing component goes into Findings.

## Map slice
`memory/map/website.md`; recon.md §D (session readers with line numbers, CSRF, filters).

## Acceptance criteria
- [ ] Worker runs its own new test file(s) singly while iterating; the close-story gate is the full suite + phpstan + migrations lint.
- [ ] Ask 6: every account form (`/account/login`, `/account/logout`, code entry) is protected by the global CSRF filter — a POST without the token is rejected (test).
- [ ] Ask 9: the new views use only `wildworld-ui.css` tokens/classes (no inline colours, radii, shadows); at 375 px no horizontal scroll (`document.documentElement.scrollWidth <= innerWidth`).
- [ ] Ask 6: a login with "remember me" survives session expiry. A new session is restored from
      the cookie, the token is rotated, and a stolen or old validator is rejected and the token
      deleted. `POST /account/logout` clears the session, deletes the token and expires the cookie.
      Every form POST carries a CSRF token.
- [ ] Ask 6 / Ask 15: `accountThrottle` returns 429 with a readable notice after
      `Config\Accounts` limits per IP and per identifier (email). The password is checked with
      `password_verify`. A wrong email and a wrong password give the same message.
- [ ] Ask 2: a player logs in with email+password or with the Telegram widget and lands on
      `/account` with their character. A logged-in widget link follows plan A2.
- [ ] Ask 10: the email+password form is always rendered on `/account/login`, whatever the flag
      and env values.
- [ ] Ask 14: an old session that holds only `tg_user_id` still opens `/map`, profile,
      achievements and battles as before (legacy upgrade). A web-only session (`character_id`, no
      Telegram) also opens them.
- [ ] Ask 9: `/account/login` and `/account` use only story-03 components, have no horizontal
      scroll at 375/768/1440, and render without JS apart from the widget.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
`git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`


## Implementation notes
- Files: `AccountSession`, `AccountAuthService`, `AccountThrottleFilter` (new); `AccountAuth`, `AccountCabinet` controllers + `account_login`/`account_cabinet` views (new); `Filters.php` alias `accountThrottle`; `Routes.php` whole `/account` group (declared before the root catch-all); `TelegramLogin`, `Map`, `Profile/Achievements/BattlesController` read the session via `AccountSession`; tests `AccountSessionTest` (7), `AccountAuthTest` (9).
- Remember-me: selector 24 hex + validator 64 hex, only sha256 stored; the token row is deleted on every presentation (valid → re-issued, invalid/expired/stolen → cookie expired), `affectedRows()===1` makes parallel reuse single-winner. Cookie Secure follows `Config\Cookie::$secure` (true in production), HttpOnly, Lax.
- Deviation: `logout()` removes the keys and regenerates the session id instead of `session->destroy()` (destroy warns under MockSession and kills flash for the redirect); same security effect.
- `current()` checks the account row still exists (an account merged away on another device logs out). Legacy `tg_user_id`-only sessions are upgraded via `ensureForTelegram` once; an unknown `tg_user_id` clears the keys.
- Widget (A2): always `ensureForTelegram` first, then if logged into another account: no-char account merges into the char account; char account + Telegram without char → the Telegram account merges into the current one; two characters → `/account?auth=link_refused`. Widget on `/account/login` passes `next=/account`.
- Throttle 429 renders `site/account_login` with the notice for every guarded path (no generic notice view in Files); per-identifier bucket keys on POST `email` or `code`.
- `AccountAuthService::setEmailPassword`: same account + same email = password change; account already holding a different email → `account_has_email`.
- Not verified: browser pass at 375/768/1440 (no-scroll, console) — views use only story-03 classes and no inline styles; left for the Queen's Tier-2 pass.
- Gates: my two test files green; full suite 4278 tests, 1 failure `OnboardingNavLabelConsistencyTest::testStartCommandPassesSingleScreenContextToSections`, and phpstan 2 `ignore.unmatched` in `StartCommand.php` — both from story 04's uncommitted `StartCommand.php` in the shared tree (not touched here). Migrations lint OK.
- Re-dispatch 2026-09-23: no code changes. Story 04's worker ran `CharacterProvisioningServiceTest` against `wildworld_tests` at the same time, so my DB tests failed randomly ("table doesn't exist/already exists"). Run alone they are green (16/145). Gates were unchanged: 4278 tests / 1 failure in the StartCommand-only source-scan test, phpstan 2 `ignore.unmatched` in StartCommand, lint OK.
- CSRF test non-vacuity: with `account/*` added to the csrf `except` list the test went red on `account/login` (logout was not separately exercised in that run).

## Findings
