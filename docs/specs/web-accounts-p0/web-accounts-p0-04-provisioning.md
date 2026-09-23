---
story: web-accounts-p0-04
spec: web-accounts-p0
status: todo
returned:
tier: 3
worker: worker-code
model: opus
tracer: false
wave: 2
blocked_by: [web-accounts-p0-01, web-accounts-p0-02]
---

# CharacterProvisioningService extracted from /start

## Goal
Character creation no longer lives only in the Telegram `/start` handler.
`CharacterProvisioningService::create(string $name, ?int $telegramUserId, ?int $chatId, ?int $accountId): int`
performs the writes of `StartCommand.php:87-181` (recon §A b-i): character row with starting
stats, name fallback `Путник-{id}`, spawn cell selection, onboarding chain, starter kit,
cold-open bait and newbie greeter. It also attaches the character to an account, calling
`AccountService::ensureForTelegram` for bot players. `StartCommand` keeps only the
`telegram_users` insert, the Telegram UI (keyboard, welcome/kit texts), and the referral call if
that call needs the Telegram payload. `StarterKitService` and `NewbieGreeterService` accept
`?int $chatId`: they grant and place with null, and skip only the chat message.

## Requirements
> Регистрация без Telegram вообще: разрешаем? Рекомендую да — иначе цель не достигается.

## Files
- app/Services/Player/CharacterProvisioningService.php
- app/Controllers/Telegram/Commands/StartCommand.php
- app/Services/Onboarding/StarterKitService.php
- app/Services/Onboarding/NewbieGreeterService.php
- tests/database/CharacterProvisioningServiceTest.php

## Non-goals
- No web controller, form or route. The web caller is story 08.
- Do not change the starting numbers (gold, stats, health), the spawn biome list or the order of
  steps. This is a move, not a rebalance. If a number is hardcoded today, keep it where it moves
  to and note it in Findings; do not migrate it to GameSettings here.
- Do not port daily tasks, streak, `last_seen` or the return digest (plan A7).
- Do not touch the tutorial/onboarding action handlers or `WithoutTrainingStartAction`.
- No name-uniqueness rule. `characters.name` has none today.

## Map slice
`memory/map/onboarding.md`, `memory/map/telegram.md`; recon.md §A (line map of `StartCommand`).

## Acceptance criteria
- [ ] Ask 14: `/start` for a new Telegram user produces the same rows as before. The DB test
      compares against a pre-refactor fixture: `characters` columns, spawn cell in an allowed biome
      with y ≥ 900, onboarding chain, starter-kit items plus its `action_log` row with the chat id,
      bait, greeter. `/start` for an existing player is unchanged.
- [ ] Ask 1: a character created through `/start` after this story has `account_id` set with a
      `telegram` identity, the same shape as the backfill in story 01.
- [ ] Ask 5: `create('Имя', null, null, $accountId)` creates a playable character with
      `telegram_user_id = NULL`, `account_id = $accountId`, a starter kit granted (its `action_log`
      row with NULL `chat_id`) and a greeter placed. No exception, and no `telegram_users` row is
      created.
- [ ] The empty-name fallback `Путник-{id}` works for both callers. Referral recording still fires
      for bot players when `referral.enabled`.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/database/CharacterProvisioningServiceTest.php`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes

## Findings
