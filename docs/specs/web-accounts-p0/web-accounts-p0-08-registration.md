---
story: web-accounts-p0-08
spec: web-accounts-p0
status: done
returned: DONE
tier: 3
worker: worker-code
model: opus
tracer: false
wave: 3
blocked_by: [web-accounts-p0-01, web-accounts-p0-04, web-accounts-p0-05]
---

# Flag-gated web registration, web character creation, password reset, header auth state

## Goal
Registration and web character creation work behind `web.open_registration`:
- **Flag on.** A visitor with no Telegram registers with email+password
  (`AccountAuthService::registerWithEmail`). They then create a character on
  `/account/character`: the name is validated by the `NameService` rule, and the character is
  created through `CharacterProvisioningService::create($name, null, null, $accountId)` with
  `accounts.acquisition_source = 'web'`. Accounts created by a first OAuth login (story 07) land on
  the same page.
- **Flag off.** `/account/register` and `/account/character` explain the closed beta and point to
  `/web` in the bot. They do not create anything.

Password reset (`PasswordResetService`, `account_tokens` purpose `password_reset`) sends a mail.
If the mail transport fails, it says so honestly and offers the bot-code path instead of claiming
success. The site header shows "Войти" or the character name linking to `/account`.

## Requirements
> Регистрация без Telegram вообще: разрешаем? Рекомендую да — иначе цель не достигается.

## Files
- app/Controllers/AccountRegister.php
- app/Controllers/AccountPassword.php
- app/Services/Web/PasswordResetService.php
- app/Services/Player/NameService.php
- app/Views/site/account_register.php
- app/Views/site/account_character.php
- app/Views/site/account_reset.php
- app/Views/site/layout.php
- app/Views/site/_layout/header.php
- tests/database/AccountRegistrationTest.php
- tests/database/PasswordResetServiceTest.php

## Non-goals
- Do not flip `web.open_registration`. Its default stays 0 and the owner opens it from the admin.
- Do not fix `app/Config/Email.php` or SMTP (plan A10). Detect the failure and report it honestly.
- No email verification, referral on web registration, faction choice, tutorial port, or daily
  tasks (plan A4, A7, A12).
- No name-uniqueness rule. Reuse the `NameService` regex by extracting a pure validator (e.g.
  `isValidName(string): bool`) without changing `applyName` behaviour.
- Do not change `Routes.php` or the CSS. Do not touch the admin `Signup`.

## Map slice
`memory/map/website.md` (header/layout partial data contract: `view()` does not forward scope),
`memory/map/admin.md` (GameSettings 60 s cache); recon.md §A (name rule), §D (Email config).

## Acceptance criteria
- [ ] Worker runs its own new test file(s) singly while iterating; the close-story gate is the full suite + phpstan + migrations lint.
- [ ] Ask 9: registration / character-creation views use only `wildworld-ui.css` tokens; no horizontal scroll at 375/768/1440.
- [ ] Ask 4: with the flag off, `POST /account/register` and `POST /account/character` create no
      rows and render the closed-beta explanation. With the flag on, they create an account (an
      email identity with a `password_hash` secret) and then a character with
      `telegram_user_id = NULL`, `account_id` set and `acquisition_source = 'web'`.
- [ ] Ask 4: an account that already has a character cannot create a second one (plan A1). An
      invalid name gets the same rule message as the bot's `/name`.
- [ ] Ask 15: passwords are stored only as `password_hash()` output, shorter than
      `passwordMinLength` is refused, and the register and reset POSTs go through `accountThrottle`.
- [ ] Password reset: the token is single-use and expires. An unknown email gets the same "sent"
      answer. A transport failure shows an honest "письмо не отправилось" plus the bot-code
      alternative; a test with a failing mailer double covers it.
- [ ] Ask 9: the header shows the auth state on every site page, and the register, character and
      reset pages have no horizontal scroll at 375/768/1440 and use only story-03 components.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
`git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`


## Implementation notes
- `NameService`: extracted `NAME_PATTERN`, `RULE_MESSAGE` (bot text byte-identical), `isValidName()`, `ruleMessagePlain()` (RULE_MESSAGE minus Markdown/emoji, shown on the web). `applyName` behaviour unchanged.
- `AccountRegister`: flag read via `GameSettingsService::get('web.open_registration', false)` (`registrationOpen()`); flag off → closed-beta view on GET and POST, nothing written. Character creation re-checks `characterForAccount` under MySQL `GET_LOCK` per account (double submit cannot yield a second character, A1).
- `PasswordResetService(?db, ?Accounts, ?Closure $mailer)`; link = `/account/reset/{selector}-{validator}`; a new request deletes older reset tokens; mail failure/exception deletes the token → `mail_failed`; `complete` burns the token on any attempt (wrong guess included), refuses short passwords without burning, and deletes the account's remember tokens. Default mailer returns false when `email.fromEmail` is empty.
- Reset pages work regardless of the flag (recovery, not registration). Successful reset renders a "done" page (AccountAuth has no `?auth=` key for it and is not in this story).
- Header: `layout.php` resolves auth only when the browser carries the `ci_session` or `ww_remember` cookie (anonymous visits/crawlers do not open a session); failures fall back to "Войти". Link shows character name, "Аккаунт" if none, `data-auth-state` in/out.
- Surprising: `FeatureTestTrait` reads cookies from the `superglobals` service, not `$_COOKIE`; tests drop the global `csrf` filter via `config(Filters)` and restore it (CSRF itself is covered by AccountAuthTest). `account_link_codes` FK needs `characters` to exist before `CreateAccountsTables::up()`.
- Known tradeoff (spec-mandated): `mail_failed` is only reachable for a known email, so when SMTP is broken the answer distinguishes known vs unknown emails.
- Not done: no browser pass at 375/768/1440 (Ask 9) — views reuse only story-03 classes + existing `.help`/`.field.has-error`, no inline styles added; Tier-2 is the Queen's.

## Findings
