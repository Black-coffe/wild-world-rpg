---
story: web-accounts-p0-01
spec: web-accounts-p0
status: done
returned: DONE
tier: 3
worker: worker-code
model: opus
tracer: true
wave: 1
blocked_by: []
---

# Accounts core: identity root, backfill, AccountService

## Goal
The database has `accounts`, `account_identities`, `account_tokens` and `account_link_codes`.
`characters.account_id` points at an account. Every existing character with Telegram owns exactly
one account holding a `telegram` identity. `telegram_users.telegram_id` and
`characters.telegram_user_id` are UNIQUE. `Config\Accounts` carries the security constants,
`web.open_registration` is seeded off, and `AccountService` exposes the account operations every
later story builds on. The schema and the constants follow plan.md `## Contracts` exactly.

## Requirements
> /vulyk-plan на Фазу 0 своими словами.
> все єто связівает единая систтема авторизации

## Files
- app/Database/Migrations/2026-12-10-100001_CreateAccountsTables.php
- app/Database/Migrations/2026-12-10-100002_LinkCharactersToAccounts.php
- app/Database/Migrations/2026-12-10-100003_WebOpenRegistrationSetting.php
- app/Models/AccountModel.php
- app/Models/AccountIdentityModel.php
- app/Models/AccountTokenModel.php
- app/Models/AccountLinkCodeModel.php
- app/Models/CharacterModel.php
- app/Services/Web/AccountService.php
- app/Config/Accounts.php
- app/Config/WipeManifest.php
- tests/database/AccountsSchemaTest.php

## Non-goals
- No controllers, routes, views, sessions or login logic. Those are stories 05-08.
- Do not make `character_tasks` / `explored_cells` / `action_log` nullable. That is story 02.
- Do not touch `StartCommand` or character creation. That is story 04. New bot players get their
  account through `ensureForTelegram` called from story 04, not from a DB trigger.
- Do not touch the admin `users` table or `Signup`.
- Do not "clean up" duplicates silently. If the UNIQUE cannot be added, the migration must throw
  with the offending ids.

## Map slice
`memory/map/admin.md` (WipeManifest, GameSettings gotchas); recon.md §B.

## Acceptance criteria
- [ ] Worker runs its own new test file(s) singly while iterating; the close-story gate is the full suite + phpstan + migrations lint.
- [ ] Ask 1: after `migrate`, every character with a non-null `telegram_user_id` has `account_id`
      set. Its account has exactly one identity (`telegram`, subject = `telegram_users.telegram_id`).
      Re-running the backfill section is idempotent.
- [ ] Ask 1: `ensureForTelegram()` returns the same account for the same Telegram user on repeat
      calls, and creates one (and attaches the character) when there is none.
- [ ] Ask 2: `unlinkIdentity()` refuses the last identity. `addIdentity()` refuses a
      `(provider, subject)` that is already taken. `mergeInto()` refuses when `from` owns a character.
- [ ] Ask 4: GameSettings row `web.open_registration` exists (bool, default 0) with
      rationale/effect/above/below text, following the `2026-09-22-100000_S8ReferralGameSettings.php`
      pattern. The seed is idempotent.
- [ ] Ask 13: `accounts` and `account_identities` are IDENTITY_RESET, `account_tokens` and
      `account_link_codes` are TRANSIENT, and `characters.account_id` is classified if the manifest
      tracks columns. `tests/unit/Config/WipeManifestCoverageTest.php` is green.
- [ ] `CharacterModel` allows `account_id` (lesson: an ALTERed column must be added to allowedFields).
- [ ] The migrations have a `down()` that reverses them. Before writing, run
      `ls app/Database/Migrations | tail` and confirm the prefixes are unique and latest.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
`git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`


## Tracer
Thinnest slice through all persistence layers: real migration classes (the create migrations for
`telegram_users`/`characters` plus the three new ones) on the test DB → models → `AccountService`
→ one DB test asserting "a character with Telegram gets an account + telegram identity, and
`ensureForTelegram` is idempotent". If the backfill or the UNIQUE forces a different contract,
report it on the INTERFACES line before wave 2 starts.

## Implementation notes
- Migrations 2026-12-10-100001/2/3 (prefixes confirmed free; newest before was 2026-12-08-100000). Schema exactly per plan `## Contracts`; `account_link_codes.character_id` is INT(5) UNSIGNED to match `characters.id` for the FK.
- 100002: duplicate check runs first and throws with `telegram_users.id` / `characters.id` lists, nothing altered. UNIQUE/column adds are existence-guarded; `backfill()` is public so the test re-runs it (idempotent: skips `account_id IS NOT NULL`, reuses an existing telegram identity).
- Surprise: adding UNIQUE(characters.telegram_user_id) makes MySQL drop the implicit FK index, so `down()` re-adds a plain `characters_telegram_user_id_foreign` key in the same ALTER before dropping the UNIQUE (otherwise "needed in a foreign key constraint").
- 100003: `web.open_registration`, category `world`, bool default 0, idempotent insert.
- WipeManifest: accounts/account_identities IDENTITY_RESET (reset `last_login_at`/`last_used_at` - the coverage test requires a non-empty reset), tokens/link codes TRANSIENT. Manifest does not track columns; `characters.account_id` is kept out of `characterResetValues` (survives wipe) and noted in a comment.
- AccountService: raw builder on an injectable `BaseConnection`; `unlinkIdentity`/`mergeInto` lock the account row(s) `FOR UPDATE` in a transaction; `addIdentity` pre-checks and also catches the UNIQUE race; `ensureForTelegram` rolls back its fresh account if the identity insert loses a race. Throws InvalidArgumentException for an unknown `telegram_users.id`.
- Contract unchanged by backfill/UNIQUE. Full-repo phpstan shows 13 `ignore.unmatched` errors in `app/TaskHandlers/*` from the parallel story 02 working tree; 0 errors in this story's files.
- Test DB: earlier failing runs of my own test left `characters/game_settings/telegram_users` in `wildworld_tests` (empty before); I dropped those three to restore the empty state.

## Findings
