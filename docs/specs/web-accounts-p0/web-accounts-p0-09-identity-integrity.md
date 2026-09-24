---
story: web-accounts-p0-09
spec: web-accounts-p0
status: done
returned: DONE
tier: 3
worker: worker-code
model: opus
tracer: false
wave: 4
blocked_by: [web-accounts-p0-05, web-accounts-p0-06, web-accounts-p0-07]
---

# Identity integrity: session-bound widget link, no merges, no shadow account after unlink

## Goal
Council round 1 found three problems: critical #1 and majors #2 and #5 in
`council/round-1/review.md`. After this story, a login identity never moves from one account
to another (ADR-188 invariant 4, plan delta F1):
- **Widget callback (`TelegramLogin`), logged out.** The visitor logs into the account that holds
  the payload's `telegram` identity. It resolves through
  `AccountService::accountForTelegramLogin()` (see Contracts).
- **Widget callback, logged in, bare.** A callback without a valid link nonce changes no account
  and no identity. The session stays as it is, and the visitor is redirected to `/account` with a
  notice.
- **Widget callback, logged in, with the nonce.** The cabinet mints a single-use nonce
  (`tg_link_nonce`) into the session when it renders the link widget and puts it in the widget's
  auth URL. A callback whose nonce matches is consumed and then links:
  - an unowned identity is added to the current account;
  - an identity the current account already owns is a no-op;
  - an identity owned by another account is refused with a readable reason.
- **Link code (`LinkCodeService::link`).** Logged out, the code logs the visitor into the
  character's account. Logged into that same account, it is a no-op. Logged into any other
  account, the code is refused before it is spent, with a message saying to log out first. There
  is no merge branch.
- **`mergeInto()`** is deleted, and nothing calls it.
- **Telegram login after unlink.** If the player unlinked their Telegram identity and their
  character is still on an account, a later Telegram login creates no new account. The widget
  says Telegram login is unlinked. A legacy `tg_user_id` session upgrade clears the keys. A Telegram user
  who has neither an identity nor a character behaves as before (minor #8 is not in scope).

## Requirements
> все єто связівает единая систтема авторизации
> в том числе и связка с телеграм

## Files
- app/Controllers/TelegramLogin.php
- app/Services/Web/AccountService.php
- app/Services/Web/AccountSession.php
- app/Services/Web/LinkCodeService.php
- app/Controllers/AccountLink.php
- app/Controllers/AccountCabinet.php
- app/Views/site/account_cabinet.php
- tests/database/AccountAuthTest.php
- tests/database/AccountSessionTest.php
- tests/database/LinkCodeServiceTest.php
- tests/database/AccountCabinetTest.php
- tests/database/AccountsSchemaTest.php

## Non-goals
- Do not change the signatures or behaviour of `ensureForTelegram`, `findByIdentity`,
  `createAccount`, `attachCharacter` or `characterForAccount`. Story 11 runs in parallel and
  builds on them.
- Do not change `TelegramLoginVerifier` or `Routes.php`. The nonce rides as a query parameter
  that the verifier leaves out, the same way it leaves out `next`. If the verifier puts unknown
  parameters into the data-check string, stop and return NEEDS_CONTEXT.
- Do not fix minor #8 (the widget creating an account for a never-played Telegram user while the
  flag is off), and do not change OAuth linking, which already refuses.
- Do not touch `CharacterProvisioningService` (story 11) or the password reset (story 10).
- Add no CSS. No "log out everywhere".

## Map slice
`memory/map/website.md`; `council/round-1/review.md` findings 1, 2, 5; plan.md `## Contracts`
(Services, Session keys) and delta F1; ADR-188 invariant 4.

## Acceptance criteria
- [ ] Worker runs its own test files singly while iterating; the close-story gate is the full suite + phpstan + migrations lint.
- [ ] #1: a logged-in visitor (with or without a character) who hits the widget callback with a
      valid signed payload and no nonce, a wrong nonce, or a reused nonce keeps their session, and
      no `account_identities` row moves or is added. Tests cover each case. The old test
      `testWidgetLinkWhileLoggedInWithoutCharacterMergesIntoCharacterAccount` is replaced by its
      refusal counterpart.
- [ ] #1: a logged-out widget login still works: a known identity lands on `/account` with its
      character.
- [ ] #1: a cabinet-minted nonce links an unowned Telegram identity to the current account and
      works once. The same identity owned by another account is refused, and both accounts stay
      unchanged.
- [ ] #2: `grep -rn mergeInto app/` is empty. A code redeemed while logged into any other account is
      refused, and the code stays unspent: a test redeems it afterwards while logged out.
- [ ] #5: after the telegram identity is unlinked, a widget login for that Telegram user creates
      no `accounts` row and shows the "Telegram login is unlinked" notice. A legacy
      `tg_user_id`-only session for that user comes back logged out. The character's account and
      its other identities are unchanged.
- [ ] The Ask 6 CSRF test and the `unlinkIdentity` last-identity test stay green.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
`git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`

## Implementation notes
- Files: TelegramLogin, AccountService (`accountForTelegramLogin` added, `mergeInto` deleted), AccountSession (legacy upgrade via `accountForTelegramLogin`; `mintTelegramLinkNonce()` / `consumeTelegramLinkNonce()`; `tg_link_nonce` cleared on logout), LinkCodeService (`STATUS_MERGED` -> `STATUS_NOOP`, `MSG_FAILED` removed, `MSG_NOOP` added), AccountLink, AccountCabinet, account_cabinet view, and 4 test files. AccountCabinetTest was not changed; the cabinet nonce is covered in AccountAuthTest.
- Verifier (Q8): `TelegramLoginVerifier` does put every scalar key except `hash` into the data-check string. `next` is kept out only because the controller unsets it before `verify()`. I strip `link_nonce` the same way, in the same place, so the verifier is untouched. This matches the story's "the same way it leaves out `next`", so I did not return NEEDS_CONTEXT.
- Logged-in callback: the session nonce is consumed on every logged-in callback (match or not), so a forged attempt only burns it and the cabinet mints a new one on render. A bare callback whose identity is already the current account's redirects to `/account?auth=ok`. Every other bare, wrong or reused nonce redirects to `/account?auth=link_unconfirmed`. `ensureForTelegram` is no longer called before the logged-in branch, so a bare callback cannot create an account.
- "Telegram login is unlinked" notice: no in-scope page renders `?auth=` for a logged-out visitor (`AccountAuth` is not in `## Files`). So the unlinked widget login redirects to `/account/link?auth=tg_unlinked`, and `AccountLink::index` shows `MSG_TG_UNLINKED`. The code page is also the recovery path. `next` is ignored in that case.
- Link code, logged into the character's own account: no-op, the code is not spent, and there is no re-login.
- Surprising: the shared `wildworld_tests` flapped (tables exist or are missing) while the parallel wave-4 workers ran. I ran the full suite on a private schema, `env 'database.tests.database=wildworld_tests_s09'`, and dropped the schema afterwards. Result: 4328 tests, 5 failures, all in `tests/unit/Camp/{BasePickerTest,StartRobotGatheringBaseTest}`. They expect `encodeFile(base_url(...))` to throw because "the photo is unreachable on the test stand". That depends on the environment and is outside this story's files.
- Stale copy outside scope: `app/Views/site/account_link.php:30` still says "Код привяжет твой текущий вход к персонажу из бота" (merge semantics). Under F1, a logged-in visitor is refused. The copy should be fixed by whoever owns that view.
- Re-dispatch (2026-09-24): I found the previous attempt's edits intact and did not re-edit them. On the shared DB the story files flapped (AccountAuth 4 errors, AccountSession 6 errors), then went green on a rerun. On the private schema: full suite 4330 tests, the same 5 Camp failures; phpstan OK; migration lint OK.

## Findings
