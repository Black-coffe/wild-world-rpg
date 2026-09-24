---
story: web-accounts-p0-10
spec: web-accounts-p0
status: done
returned: DONE
tier: 3
worker: worker-code
tracer: false
wave: 4
blocked_by: [web-accounts-p0-08]
---

# Password reset: the same answer for every email

## Goal
Council round 1, major #3: `/account/reset` currently tells known emails apart from unknown ones
whenever mail fails. After this story, `POST /account/reset` renders one page for every outcome:
an unknown email, a known email whose mail was sent, and a known email whose mail failed. That
page always states the honest caveat: if no letter arrives, mail may be failing, and the player
can take a code in the bot with `/web` and enter it at `/account/link`. The real failure is not
shown to the visitor. It still reaches operators through `log_message('error', …)`, which names
the failure but not the email. `PasswordResetService::request()` may keep returning
`'sent'|'mail_failed'` for that log, but the controller must not branch the visible output on it.

## Requirements
> Email + пароль (Рекомендую)

## Files
- app/Services/Web/PasswordResetService.php
- app/Controllers/AccountPassword.php
- app/Views/site/account_reset.php
- tests/database/PasswordResetServiceTest.php
- tests/database/AccountPasswordResetPageTest.php
- tests/database/AccountRegistrationTest.php

## Non-goals
- Do not fix SMTP or `app/Config/Email.php` (plan A10).
- Do not add a mail queue or artificial delays. The timing gap of a synchronous send is a recorded
  residual (plan `## Descoped`).
- Do not move the reset token out of the URL (minor #6) and do not drop login methods on reset
  (minor #7).
- Do not change the token rules. Tokens stay single-use, expire, and are burned on a wrong guess.
- Do not touch `Routes.php`, the CSS or `AccountRegister`.

## Map slice
`memory/map/website.md`; `council/round-1/review.md` finding 3; plan.md `## Contracts`
(`PasswordResetService`).

## Acceptance criteria
- [ ] Worker runs its own test files singly while iterating; the close-story gate is the full suite + phpstan + migrations lint.
- [ ] #3: for an unknown email, a known email with a mailer double that succeeds, and a known email
      with a double that fails or throws, the `POST /account/reset` responses have the same HTTP
      status and the same body once the CSRF token is removed (page-level test).
- [ ] #3: that body contains the bot-code alternative (`/web`, `/account/link`) in all three cases.
- [ ] A mail failure writes one error-level log line that does not contain the email address.
- [ ] The existing reset tests stay green: single use, expiry, the short-password refusal, and
      remember tokens deleted on success.
- [ ] Ask 9: `account_reset` still uses only story-03 components.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
`git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`

## Implementation notes
- `PasswordResetService`: on mail failure it now writes one `log_message('error', '[PasswordReset] reset mail failed for account {id}: {reason}')`. The reason is the exception class and message, or "transport returned false", with the recipient address removed case-insensitively (`str_ireplace`), because SMTP errors can quote it. It still returns `sent|mail_failed`. `defaultMailer()` now uses the shared `Services::email()` + `clear(true)`, so tests can `injectMock('email', …)`.
- `AccountPassword::send` ignores the result and always renders `mode=requested`. The view drops `sent`/`mail_failed` for one `notice info` block (story-03 component) that has the spam hint, the "mail sometimes doesn't arrive → /web code" caveat, and the buttons «Ввести код из бота» (/account/link), «Открыть бота» and «Ко входу».
- New `tests/database/AccountPasswordResetPageTest.php` covers 4 cases (unknown, known+sent, known+false, known+throws): same status and the same body. It also checks that a failure logs exactly one error line without the email and that a sent mail logs none. The log is read through reflection on `TestLogger::$op_logs`. `AccountRegistrationTest::testResetPageShows…` was edited to assert the unified page (failed == unknown). It was not deleted.
- Surprise: under the testing env the bodies differ by `<!-- DEBUG-VIEW START|ENDED N -->` counters, which exist only outside production. Both tests normalise those counters away together with the CSRF input before comparing.

## Findings
- NEEDS_CONTEXT (file list): `tests/database/AccountRegistrationTest.php::testResetPageShowsHonestMailFailureWithBotCodeAlternative`
  (story 08) asserts the OLD split - `Письмо не отправилось` for a known email with a failing
  transport and `Письмо отправлено` for an unknown one. Criterion #3 (one body for all outcomes)
  makes that test red by construction, and the file is not in `## Files`. Question: may this story
  delete that test method (its coverage moves into the new `AccountPasswordResetPageTest.php`), or
  edit it to assert the unified page? Either way `AccountRegistrationTest.php` must join `## Files`.
  (Keeping both old strings in the unified page to satisfy it would be a false "sent" claim - rejected.)
- Seam note for the planner (no question, the chosen approach): the page test needs a mailer double
  that succeeds. `PasswordResetService::defaultMailer()` uses `Services::email(null, false)`, which
  bypasses `Services::injectMock`. Plan: switch it to the shared instance + `clear(true)` so the test
  injects an `email` mock; no change to `Config/Services.php` or `Routes.php`.
- Re-dispatch 2026-09-23: story unchanged (`## Files` still lacks `AccountRegistrationTest.php`, no
  answer recorded in story or plan.md); conflict re-confirmed at lines 227/233 of that test. No code
  edited. Same question stands.
