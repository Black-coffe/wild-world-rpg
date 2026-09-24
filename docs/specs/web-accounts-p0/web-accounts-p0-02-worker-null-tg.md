---
story: web-accounts-p0-02
spec: web-accounts-p0
status: done
returned: DONE
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
- phpstan-baseline.neon

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
- [ ] Worker runs its own new test file(s) singly while iterating; the close-story gate is the full suite + phpstan + migrations lint.
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
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
`git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`


## Implementation notes
- Migration `2026-12-10-100010_NullableTelegramKeys`: MODIFY keeps `COLUMN_TYPE` from information_schema, toggles only NULL; FK checks off around ALTER (MySQL 8 refuses otherwise). Drift column `character_resources.id_telegram_users` only if present, not reverted in down(); down() refuses while NULL rows exist.
- `TelegramChatResolver` (Query Builder join characters→telegram_users); all 19 §C sites in app/TaskHandlers now resolve by character id after the reward and skip send on null. Marching maps task `telegram_user_id` 0→NULL for revealAround/respawned steps, `deliverMarchMessage` returns silently for web-only.
- Models: `CharacterTaskModel` rule `permit_empty|integer`; `ExploredCellsModel::revealAround(int, ?int, …)`. `ActionLogModel` needed no change (no validation on chat_id).
- Out-of-list file: `phpstan-baseline.neon` — 13 entries for the edited handlers pruned (they no longer match; phpstan fails on unmatched baseline entries).
- Test `tests/database/WebOnlyCharacterWorkerTest.php` (23 tests): schema from migration classes; raw DDL only for `resources`/`character_resources` (migration invalid on MySQL 8), `npc_spawns` (no migration), `active_events` (CreateEventsTable invalid on MySQL 8), `character_tasks.task_settings` (prod drift). Crons with time gates / object dispatch (FoodAndWater, Toolkit, ClosedWarehouse, DeathRoulette, Tax) are driven via reflection on the reward+notify method, not the full `handle()`; Marching/robots/armor/teleport/quests run the real `handle()`.
- Surprising: robot exploration over 3h took 78s in the test (circle search) — test uses 6 minutes.
- Left untouched (recon "safe"): StrategicLoot ×2, BaseRelocation, BaseFullRelocation still call `find($character['telegram_user_id'])`, i.e. `find(null)` for web-only; harmless (list result → `empty($tg['telegram_id'])` → return) but it is a `find(null)`.

## Findings
