# Queued 🔒 craft is promoted when any 🔒 task ends (plan)
**Brief:** [brief.md](brief.md) · **Branch:** `vulyk/craft-queue-promote-1125` · **Approved:** «Одобрить (Рекомендую)», 2026-11-25

## Goal
Today a queued craft is offered the slot only by `GenericCraftCompletionHandler` when a craft of the *same*
`task_id` completes (`CraftQueueService::promoteNext($char, $taskId)`, caller `GenericCraftCompletionHandler.php:287-296`).
So a queued row is stranded whenever its recipe is no longer running: the F4 race (the craft completed, another 🔒
task took the slot before promotion → `Refused`), the player's «⛔️ Прервать» of the running craft, a handler that
threw or returned early. Add one service call, "promote whatever this character's queue can start now", and run it
wherever a task leaves `in_work`: once in the Worker for every timed task, and in the player's interrupt/cancel paths.

## Assumptions
- **Eligible row:** the oldest `queued` row of the character whose `task_id` has no `in_work` row of that character
  (a recipe that is still running keeps its own FIFO and is promoted by its own completion, as today). Promotion
  goes through the existing `promoteNext()` per eligible `task_id`, so atomicity, `Refused` (stays queued) and
  `Missing` (skip) are unchanged. At most one 🔒 row can win; non-exclusive queued crafts (`is_exclusive=0`) are
  never refused and also start.
- **Hook points** (recon, no shared choke point exists):
  - `Worker::handleTask` after the handler ran, and after the `interrupted` catch path (`Worker.php:121-141`) —
    covers every timed completion (gather, march, relocations, repair, robots, crafts, buildings, farm) and the F4
    race. The call goes through a new `BaseTaskHandler::promoteQueuedCrafts(int $characterId)` so the message uses
    the handler's own `safeSendMessage` (cron sends outside `BaseTaskHandler` fail silently — `.claude/rules/cron-and-notifications.md`).
  - Player paths: `FinishTaskAction` (also web `finishAllTasks_*`), `CancelGatherAction`, `MarchService::stop()`
    callers (`CancelMarchAction`, `WebNativeScreenService:736`), `DeleteBaseAction` (BaseRelocation interrupt).
    They run in the webhook/web request, where the app `Request` reaches both clients (ADR-189 bridge).
- **Worker hook safety (Sonnet cross-check):** the hook runs *after* the handler's `try/catch`, in its own
  `try/catch` that only logs — a failed send or query there must never flip a finished task to `interrupted`
  (`Worker.php:135-144`). When the handler itself failed to construct, the hook uses a plain `BaseTaskHandler`
  instance for sending.
- **Backstop sweep (Sonnet cross-check):** exits we did not find (death, admin, future code) would strand the queue
  again, so each Worker tick also runs one cheap sweep: characters that have a `queued` craft whose `task_id` has no
  `in_work` row → `promoteEligible()`. Delay for such paths is one cron tick; the event hooks stay for instant start.
  The sweep is bounded (a `LIMIT` per tick, infrastructure constant in `Config\*`, not balance).
- **Message:** the existing «▶️ Очередь активирована! … Завершится в HH:MM» text moves from
  `GenericCraftCompletionHandler::notifyQueuedActivated()` into one builder used by every caller; text-only, so it is
  complete with media off. `GenericCraftCompletionHandler` keeps its own same-recipe call (harmless: the general
  call then finds nothing new).
- Deaths do not end tasks (recon found no status change on death — unconfirmed); admin wipe deletes rows — out of scope.
- One recipe per `task_id` (recon could not fully confirm; the eligibility rule is per `task_id` either way).
- No balance numbers, no new table/column (no WipeManifest change), no new setting.
- Verdicts: `/guide` — no: no new player surface, the queue screen and message already exist. Tips — no: the fix
  makes the queue behave as players already expect; nothing new to learn.
- Goes live: nothing on merge; the next `develop` push deploys preprod, a tag deploys prod (owner's word each).

## Stories
- [ ] S1 `CraftQueueService::promoteEligible(int $characterId): list<Promoted>` - asks: 1, 2, 4
  - files: `app/Services/Craft/CraftQueueService.php`, `tests/database/CraftQueuePromoteEligibleTest.php`
    (real migrations incl. S1 slot, as `CraftExclusiveSlotRefusalTest` does)
  - test first: (a) F4 race — queued 🔒 craft R, no R in work, a 🔒 gather in work → nothing starts; gather
    leaves `in_work` → `promoteEligible` starts R (fails today: no such method / `promoteNext` needs R to finish);
    (b) R still `in_work` → its queued row is not touched; (c) two eligible 🔒 recipes → exactly one starts, the
    other stays `queued`; (d) a queued non-exclusive craft starts beside a 🔒 task; (e) two concurrent calls never
    start two 🔒 rows (second connection, as `CraftQueueCoreTest`).
  - check: `vendor/bin/phpunit --no-coverage --no-progress tests/database/CraftQueuePromoteEligibleTest.php tests/database/CraftQueueCoreTest.php tests/database/CraftExclusiveSlotRefusalTest.php`
- [ ] S2 Wire it everywhere a task leaves `in_work`, one shared message - asks: 1, 3
  - files: `app/Controllers/Worker.php`, `app/TaskHandlers/BaseTaskHandler.php`,
    `app/TaskHandlers/Craft/GenericCraftCompletionHandler.php`, `app/Controllers/Telegram/Commands/Actions/FinishTaskAction.php`,
    `.../CancelGatherAction.php`, `.../CancelMarchAction.php` (or `MarchService::stop()` callers),
    `app/Services/Web/WebNativeScreenService.php` (march stop path), `.../DeleteBaseAction.php`,
    a small message builder in `app/Services/Craft/`, tests
  - test first: a Worker run of a completing 🔒 gather with a stranded queued craft of the same character ends with
    the craft `in_work` and one «Очередь активирована» message captured; `FinishTaskAction` interrupting the running
    craft R starts the next queued R; the message text is identical from every caller.
  - check: `vendor/bin/phpunit --no-coverage --no-progress tests/database/CraftQueuePromoteEligibleTest.php` plus the new wiring tests; phpstan
- [ ] S2b Backstop sweep in the Worker tick - asks: 1, 2
  - files: `app/Controllers/Worker.php` (or a small service it calls), `app/Config/*` for the per-tick limit, test
  - test first: a stranded queued 🔒 craft with no task leaving `in_work` (e.g. the running craft deleted by hand)
    starts on the next Worker tick, one message; a character whose recipe is still running is not touched; the
    sweep does nothing when nothing is stranded.
  - check: the new test file plus `tests/database/CraftQueuePromoteEligibleTest.php`
- [ ] S3 Docs - asks: 6
  - files: `mmorpg-vault/tech-writing/services/CraftQueueService.md`, `.../controllers/Worker.md`,
    `.../tasks/craft/GenericCraftCompletionHandler.md`, `.../handlers/FinishTaskAction.md`; Follow-up line in
    `docs/specs/s1-atomicity-1124/plan.md` (item 7 → this spec)
  - test first: none: documentation only
  - check: `git diff --stat`

## Test command
Per story the `check:` line; before review the parts that hold the touched tests (db1 `tests/database/[AC]*Test.php`,
db3 for Worker/`F*` tests, db7/`tests/session` for the web march-stop path) and phpstan; before any push the whole suite in one process
(`vendor/bin/phpunit --no-coverage --no-progress`), as CI runs it.

## Descoped
(none)
