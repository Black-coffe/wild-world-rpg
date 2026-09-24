---
story: web-accounts-p0-11
spec: web-accounts-p0
status: done
returned: DONE
tier: 3
worker: worker-code
tracer: false
wave: 4
blocked_by: [web-accounts-p0-04, web-accounts-p0-08]
---

# One character per account when the bot creates a character

## Goal
Council round 1, major #4. Here is the bad sequence:
1. A Telegram user who has never played logs in with the widget, which gives them an account with
   a telegram identity and no character.
2. They create a web character on `/account/character`.
3. They send `/start` in the bot.

Today `CharacterProvisioningService` then calls `ensureForTelegram`, which attaches the new bot
character to the same account, so that account owns two characters.

After this story, before the bot path attaches a character, `CharacterProvisioningService`
checks whether the account holding that Telegram user's `telegram` identity (subject
`telegram_users.telegram_id`) already owns a different character. If it does, the new bot
character gets a fresh account (`createAccount('telegram')` + `attachCharacter`). That account
has no identity, and the player reaches it on the site with the `/web` code. The Telegram
identity stays where it is. Otherwise the path is unchanged (`ensureForTelegram`). The web path
(`$accountId` given) stays guarded by `AccountRegister`, as it is today.

## Requirements
> Регистрация без Telegram вообще: разрешаем? Рекомендую да — иначе цель не достигается.

## Files
- app/Services/Player/CharacterProvisioningService.php
- tests/database/CharacterProvisioningServiceTest.php

## Non-goals
- Do not edit `AccountService` (story 09 owns it). Use only its existing public methods:
  `findByIdentity`, `characterForAccount`, `createAccount`, `attachCharacter`,
  `ensureForTelegram`.
- Do not adopt the web character into the bot (that is Phase 1 and needs an owner decision). Do
  not change `StartCommand`, the starting numbers or the order of steps.
- Do not fix the ignored `GET_LOCK` result in `AccountRegister` (minor #11).

## Map slice
`memory/map/onboarding.md`; `council/round-1/review.md` finding 4; plan.md `## Contracts`
(`CharacterProvisioningService`, `AccountService`) and delta F2.

## Acceptance criteria
- [ ] Worker runs its own test file singly while iterating; the close-story gate is the full suite + phpstan + migrations lint.
- [ ] #4: a DB test replays the review's sequence through the services: `ensureForTelegram` makes a
      character-less account, then `create(name, null, null, $acc)`, then `create(name, $tg, $chat,
      null)`. Afterwards no `accounts.id` owns more than one `characters` row, the bot character
      has `account_id` set to a different account, and the telegram identity is still on the
      first account.
- [ ] #4: the reverse order also ends with at most one character per account: a web character on
      an email account, a Telegram identity added to it with `addIdentity`, then a bot `create`.
- [ ] Ask 14: the existing provisioning tests (bot path fixture, web-only path, `Путник-{id}`)
      stay green without changes to their assertions.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
`git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`

## Implementation notes
- `CharacterProvisioningService`: new private `attachBotCharacter()` on the bot path. It looks up `telegram_users.telegram_id` → `findByIdentity('telegram')` → `characterForAccount`. If that account owns a different character, it does `createAccount('telegram')` + `attachCharacter`. Otherwise it calls `ensureForTelegram` as before. It reads `telegram_id` through `Config\Database` because AccountService exposes no public getter for it (story 09 owns AccountService).
- Tests: added two DB tests for the forward and reverse sequences, and `assertAtMostOneCharacterPerAccount()` (GROUP BY account_id). With the old one-line `ensureForTelegram` restored, both new tests fail. The existing 3 tests are unchanged.
- Full suite: 5 failures (BasePickerTest ×4, StartRobotGatheringBaseTest ×1). The baseline run at HEAD, without this story's changes, has the same set.
- Side effect: a returning Telegram user whose account already holds an older bot character also gets a fresh account on a new `/start`. The rule is "a different character", so the story's wording covers this.
- Re-dispatch (2026-09-24): I kept the previous attempt's edits as they were. The first run of the single file gave 5 errors in the `CreateTelegramUsersTable` migration, probably because an earlier run left the test DB in a stale state. The next 4 runs were green (5 tests, 187 assertions). phpstan is clean, migration lint is OK, and the full suite has the same 5 baseline failures.

## Findings
