---
story: web-accounts-p0-05
spec: web-accounts-p0
status: todo
returned:
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
`vendor/bin/phpunit --no-coverage --no-progress tests/database/AccountSessionTest.php`
`vendor/bin/phpunit --no-coverage --no-progress tests/database/AccountAuthTest.php`
`curl -sS -o /dev/null -w '%{http_code}' http://mmorpg.test/account/login`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes

## Findings
