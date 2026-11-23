# S1 atomicity: finish the parked spec on thin VULYK (brief)
**Date:** 2026-11-24 · **Owner:** Andrei

## Request (verbatim)
> 2026-11-23, on what to do with the stalled s1-atomicity before installing thin: «Отложить, сначала маленькая
> (Рекомендую)» — «… Первой идёт маленькая свежая спека: на ней проверим цикл и тесты на PHP. Вторая —
> s1-atomicity, ревью по находкам RED, по частям. …»
>
> 2026-11-24, on the orchestrator's list «1. … тест из Follow-up плюс вторая половина B16 … 2. Потом
> `s1-atomicity` … 3. … 4. …»:
>
> «Давай по порядку»

Context (not the owner's words). The old-framework spec `s1-atomicity` (ROADMAP S1, ADR-167) lives on branch
`vulyk/s1-atomicity` (head `72d6e107`, 26 commits after fork point `f9b3bf3a`). Its brief (2026-11-11, request
«бнри следующую задачу с роудмепа») had 8 asks and three owner answers (strictOn split out as S1b; the 🔒 slot
held by a generated column + UNIQUE like ADR-186; crediting through UNIQUE + upsert). Council round 1 was RED with
two findings: (1) `RepairCraftedItemAction::payAndScheduleRepair()` inserts the task before `deductResources()`,
which breaks the unit2 part through the shared connection's transaction state, and the test's spy assertion went
vacuous; (2) `StartRobotGatheringAction.php:239` still writes `character_tasks` with `characterTaskModel->save()`.
Checked 2026-11-24: the old branch carries the repair round's tests (`RobotGatheringStartSlotTest`, the fixture
fix in `StartRobotGatheringBaseTest`, the listener in `PoolAdoptionRepairUpgradeTest`) but **not** the two code
fixes: `payAndScheduleRepair()` still inserts first (line 472 before 477) and line 239 still calls `save()`. The
old branch merges into `develop` with one conflict only, in `memory/stats/anomalies.jsonl` (old-framework file).
Owner's order via the orchestrator: review the code only, against the RED findings and the asks, split by
finding/area.

## Answers
- 2026-11-24, plan questions and approval: Вопрос 1: «Новая ветка, squash только кода (Рекомендую)». Вопрос 2:
  «Оставить только на старой ветке (Рекомендую)». Вопрос 3: «Да, 1–8 плюс 9–13 (Рекомендую)». Вопрос 4:
  «Одобрить (Рекомендую)».

## Asks
Asks 1–8 are the old brief's asks (2026-11-11), carried over unchanged in meaning.

1. `quest_steps` gets `UNIQUE (quest_id, character_id)` by migration; its writers (`QuestStartService`, and
   `NpcInteractionService::acceptOfferedQuest()` found by recon 2026-11-24) write through `insertUnique()`, and a repeated start of the same quest by the same character (double tap, bot and web
   at once) creates exactly one row and throws no exception — proved by a test.
2. A character cannot hold two `in_work` tasks with `tasks.parallel_execution_allowed = 0`: the database holds
   this (generated column + UNIQUE after ADR-186), not only the ADR-167 check; parallel tasks (craft, robots)
   still run beside the 🔒 task; `paused`/`queued`/`completed` are not affected — proved by a test with two
   concurrent starts of a 🔒 task.
3. Every write into `character_tasks` goes through `ActiveTasksService::insertExclusiveTaskRow()`; a database
   refusal for a taken 🔒 slot shows the player the same ADR-167 refusal text («уже занят …»), not an error, and
   no resources are spent on refusal. Grep-checkable: no direct insert/save of a `character_tasks` row in `app/`
   outside `ActiveTasksService`.
4. `character_resources` gets `UNIQUE (id_characters, id_resources)`; `increaseResources` and
   `addOrIncreaseResource` credit with one atomic upsert — two concurrent credits give the sum of both — proved by
   a test.
5. `deductResource`, `deductResourceForCraft` (and the wrapper `deductResourceByName`), `decreaseQtyById` deduct
   with a conditional write (`quantity >= n` inside the UPDATE): two concurrent deductions never go below zero
   and never take more than there was; signatures and return values for callers do not change.
6. Migrations are safe for prod: before creating a UNIQUE they check for duplicates and, if any, fail with a clear
   message instead of deleting rows; `php -l` is clean; the new columns and indexes are in the test schema
   (fixtures that write `character_tasks` DDL by hand); `WipeManifest` does not change (no new tables).
7. strictOn is not touched: `app/Config/Database.php` is not in the diff; in `ROADMAP.md` S1 is closed by a journal
   line and a new item **S1b** (audit writes under strict + switch `strictOn`) stands in track S and in the §2 queue.
8. Verdicts: `/guide` — no, tip — no, because the player sees no new mechanic (refusal texts unchanged);
   tech-writing notes of the touched models, services, handlers and `db/` tables are updated; ADR — the decision
   "task exclusivity is held by the database" is recorded in `mmorpg-vault/decisions/` (or as an addendum to
   ADR-167).

From council round 1 RED and the move to thin:

9. RED finding 1: in `RepairCraftedItemAction::payAndScheduleRepair()` a lost resource race spends nothing and
   sends no `character_tasks` query (deduction first, then the slot insert, one transaction; a refused insert
   rolls the deduction back); the unit2 part is green, including
   `tests/unit/Player/PoolAdoptionRepairUpgradeTest.php` run together with
   `tests/unit/TaskHandlers/GreenhouseProductionWaterTest.php`, and the test's assertion is not vacuous.
10. RED finding 2: the robot-gathering start (`StartRobotGatheringAction`) writes its task through
    `insertExclusiveTaskRow()`; a taken 🔒 slot answers with the ADR-167 text and spends nothing (no durability);
    `tests/database/RobotGatheringStartSlotTest.php` and `tests/unit/Camp/StartRobotGatheringBaseTest.php` are green.
11. What reaches `develop` is code, tests, migrations, `phpstan-baseline.neon` and `ROADMAP.md` only; none of the
    old framework's files (`docs/specs/s1-atomicity/`, `memory/stats/*`) arrive on `develop`. Path check: every
    path in `git diff develop..HEAD --name-only` outside `docs/specs/s1-atomicity-1124/` is also in
    `git diff f9b3bf3a..vulyk/s1-atomicity --name-only` or named by a story; no develop change since the fork is
    reverted. The old branch stays as an archive at `72d6e107`.
12. On the merged result all ten parts of the full suite, phpstan and `php -l` of migrations are green, and the
    develop work since the fork (`UpdatePipeline`, `BaseTaskHandler`, `BaseObjectHandler`, `telegram_users`
    blocked mark) still passes its tests.
13. Resource writers outside `CharacterResourceModel` that recon found (`CraftQueueService.php:383` plain insert,
    `GatherResultPersister.php:262-271` read-add-write) credit through the same atomic upsert: under the new
    UNIQUE a concurrent credit neither throws a duplicate-key error nor is lost — proved by a test.
