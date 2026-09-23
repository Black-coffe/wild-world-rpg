---
story: web-accounts-p0-02
spec: web-accounts-p0
status: todo
returned:
tier: 3
worker: worker-code
model: opus
tracer: false
wave: 1
blocked_by: []
---

# Worker and crons tolerate a character without Telegram

## Goal
A character with `telegram_user_id = NULL` (web-only, no `telegram_users` row) passes through every
task completion handler and cron without an exception and without losing its reward. The schema
allows it: `character_tasks.telegram_user_id`, `explored_cells.telegram_user_id` and
`action_log.chat_id` become NULL. The prod-only drift column `character_resources.id_telegram_users`
becomes NULL too, guarded by a column-exists check. One resolver,
`TelegramChatResolver::chatIdForCharacter()` (signatures in plan.md `## Contracts`), replaces
ad-hoc chat lookups at the crash sites of recon.md §C. At every site the reward is applied first,
and the notification is skipped when the chat id is null.

## Requirements
> Регистрация без Telegram вообще: разрешаем?

## Files
- app/Database/Migrations/2026-12-10-100010_NullableTelegramKeys.php
- app/Services/Telegram/TelegramChatResolver.php
- app/TaskHandlers/
- app/Models/ExploredCellsModel.php
- app/Models/CharacterTaskModel.php
- app/Models/ActionLogModel.php
- tests/database/WebOnlyCharacterWorkerTest.php

## Non-goals
- Do not rewrite the ~20 task *creators* (`GatherAction`, `MarchAction`, `StartCraft*`, …). They
  are Telegram-only in P0 (plan A8, Tradeoffs). Do not touch any file under `app/Controllers/`.
- Do not add web notifications or an outbox. That is Phase 1.
- Do not refactor handlers beyond moving the chat lookup below the reward and guarding it on null.
- Handlers already marked "safe" in recon §C stay untouched unless a test proves otherwise.
- If a §C crash site lives outside `app/TaskHandlers/`, stop and report it. Do not edit it
  (plan Q1).

## Map slice
`memory/map/tasks-worker.md` (all gotchas; `BaseTaskHandler::telegram()` is `?Telegram`),
`memory/map/telegram.md` (TelegramBridge); recon.md §B, §C.

## Acceptance criteria
- [ ] Ask 5: for a character with `telegram_user_id = NULL`, each §C completion handler (robot
      exploration/gathering, Marching, the four armor handlers, TeleportBackpack,
      TeleportBeaconBasic) completes without an exception and the reward/row is persisted.
      `revealAround` writes `explored_cells` with a NULL `telegram_user_id`.
- [ ] Ask 5: every §C cron (Toolkit, QuestExplore30/300/AllBiomes, QuestFirstAidkit, FoodAndWater,
      ClosedWarehouse, DeathRoulette, TaxCollection) processes the web-only character and still
      processes a Telegram character in the same run. The FoodAndWater run no longer aborts for
      everyone.
- [ ] Ask 5: no code path calls `find(null)` / `find(0)` on `telegram_users`. The resolver returns
      null instead.
- [ ] Ask 14: for a Telegram character the same handlers send the same notification to the same
      chat as before (assert through the existing transport double or a spy, not a live send).
- [ ] The migration `php -l`s clean, has `down()`, and skips `character_resources` when the column
      is absent (test DB). `ExploredCellsModel::revealAround(int, ?int, …)` accepts null.
      `CharacterTaskModel` validation allows an empty `telegram_user_id`.
- [ ] The DB test builds its schema from the real migration classes (recon §D pattern).

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/database/WebOnlyCharacterWorkerTest.php`
`git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes

## Findings
