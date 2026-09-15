<!-- seat: review · model: claude-opus-5 · round: 1 · head: 981a1b56 · pack: e51476922063 · attempt: 1 · recorded: 2026-09-15T10:32:04Z · verdict: PASS -->
VERDICT: PASS

# lead-review — multibase-picker, round 1 (diff develop...vulyk/multibase-picker @ 981a1b56)

Scope: every changed app/test file is in its story’s `## Files`. CallbackRouter.php and CallbackPrefixDispatcher.php are listed for story 01 but untouched, which the story notes. Paperwork (memory/stats, learnings, specs) is on the paperwork list. No Law 3 violations.
Project gates: the balance number moved to GameSettings `communication_tower.coverage_per_level` (default 100, rationale/effect/above/below, soft 50-200, hard 1-1000, default_value_text for Reset). The migration is idempotent. No new tables or columns, so WipeManifest is untouched correctly. The picker and refusals are text-only. Photos keep their existing MediaSender/safeSendPhoto paths. Captions carry the base name and coordinates. ADR-187 in the vault closes "Revisit when" (line 57) and records the beacon rule (lines 106, 138).
Baseline: story 08 only removed the 8 CommunicationTowerCoverageService `offsetAccess` entries. Nothing was added.
Commands run: diff reads and greps only. I did not re-run the suite. Nothing in the diff made one specific test suspicious enough to re-run; the findings below come from code paths the tests do not reach.
Note: this file has no backslashes and no emoji because the shell write failed on them (Throwable is written without its namespace backslash, the suffix regex as "_b followed by digits at the end"). The chat copy of this report is otherwise identical.

## Critical

None.

## Major

1. `app/Controllers/Telegram/Commands/Actions/Camp/HangarAction.php:326-340` — **plan**. The bare `hangar` callback now goes through `BaseScopeResolver::resolve()` and returns a plain refusal (`TEXT_AMBIGUOUS` / `TEXT_NO_BASES`, no buttons) when there is no single base in scope. Before this change the hub always rendered: the lock-state or the robot/drone inventory. Three live entry points still send bare `hangar`: `CraftInsuranceListAction.php:113`, `CraftedResourcesAction.php:281` and `OnboardingHintCatalog.php:243`. Two groups lose the hub:
   - a character with 2 or more bases, off-base and outside every tower, can no longer reach their robots and drones through the Hangar;
   - a character with no base gets "Базы у тебя сейчас нет…" instead of the ADR-120 lock-state explanation.
   Story 04 prescribed "без суффикса → resolve()" and did not say what happens when there is no base or the base is ambiguous. Condition: without a suffix, the Hangar must stay reachable (ADR-120 hub / UX-discoverability) when `resolve()` yields no base. The robot/drone inventory and a lock-state with a path to the Robotics Workshop must still render, with no base named as local. The suffixed path and the single-base path stay as they are.

2. `app/Controllers/Telegram/Commands/Actions/Camp/Buildings/Robots/StartRobotGatheringAction.php:143-150` (and `RobotGathererActivator.php:72-81`) — **worker**. The launch base comes from `BaseScopeResolver::resolve()`. For a character with 2 or more bases who stands on none, the tower branch of that resolver returns the first active base by `id` (ADR-187 legacy rule). It returns that base whichever base’s tower actually covers the player. So a player under base-2’s signal only can launch a robot "from" base-1, which neither holds them nor covers them. If base-1 has no Workshop, the player is refused in base-1’s name while standing next to base-2, which does have one. The story Goal says "Робот запускается только с базы, на которой стоит игрок…", and plan Assumption 2 agrees. The implementation notes say the tower branch was kept. `StartRobotGatheringBaseTest` has only on-base scenarios, so this branch is untested. Condition: the launch base must be the base the player stands on, or (if remote launch is kept) a base whose own tower covers the player. It must never be an uncovered base picked by id order. Any deviation from the plan’s "standing on base" assumption must be recorded in `## Plan deltas`, and a test must cover the off-base, 2+ bases case.

## Minor

3. `app/Services/BaseService.php:163-172` — **worker**. When exactly one base is covered but `findBaseRow()` returns null (a race or a deleted row), the code falls through to `basePicker()`. That screen then says "Под сигналом Вышки связи сразу несколько баз" over one button. Condition: the picker text must match the actual count of covered bases it offers.

4. `app/Controllers/Telegram/Commands/Actions/Camp/DetailedBaseInfoAction.php:99-116` — **plan**. On the legacy bare `construction` path with 2 or more bases, `checkCoverage()` may be satisfied by base-2’s tower while the screen shows base-1 (ADR-187 first-by-id). The building buttons now carry `_b<base-1 id>`, and `resolveForBase()` refuses those with `unavailable`. Before, the legacy `resolve()` rule let them through. The only live bare `construction` emitter is the new `BaseDevelopmentAction::refusal()` button (`:110`, `:123`); the other path is old messages. Condition: a screen reached without a suffix must not stamp its buttons with a base that `resolveForBase()` will reject for the same player in the same position.

5. `HangarAction.php:378` — **worker**. The lock-state "Строить" button now sends `Build_b<id>`. `BuildListAction` ignores the suffix and builds for the current cell, so the suffix promises a base binding that nothing honours. The story notes admit this. Condition: a base suffix appears only on callbacks whose handler consumes it, or the story records that `Build` stays position-bound.

6. `app/Controllers/Telegram/Commands/Actions/Camp/Buildings/*Handler.php` (e.g. `ArsenalHandler.php:144`) — **plan**. The "База" back button on a card opened with `_b<id>` stays bare `Base`. For a multi-base player it returns to the picker, not to the chosen base’s screen. The Hangar and Decor back buttons do carry the suffix, so behaviour is inconsistent across screens. Condition: back-navigation from a base-scoped screen returns to the same base consistently, or the plan records the card exception.

7. `tests/unit/Camp/StartRobotGatheringBaseTest.php:192-197` — **worker**. The success test swallows every Throwable, not just the photo-open failure. It checks the caption through a separate `launchCaption()` call with hand-fed arguments, not through the rendered message. A regression that stopped passing `$baseLabel` into the real `launchCaption()` call at `:190` would stay green. Condition: the success test must catch only the expected photo-transport exception, and must assert that the base label reaches the caption the action actually builds.

8. `app/TaskHandlers/CompleteRobotGatheringHandler.php:493-497` — **plan**. The legacy fallback (no `base_cell`, or launch base inactive) is still `->where(status active)->first()` with no `orderBy`. That is the non-deterministic pick the spec removes elsewhere; the result names the base it picked, so it is visible. Condition: the legacy completion base is chosen deterministically, lowest `id` as `findAllActiveCells()` does, with "без ошибки по прежнему правилу" preserved.

9. Ask 8 (tech-writing notes for every touched entity) — **plan**. Plan Assumptions give this to `drone-docs` after the merge. Nothing in this diff delivers it, and story 04 flags the missing `HangarAction` note. Condition: the notes named in plan Assumptions must exist in `mmorpg-vault/tech-writing/` before the council judges ask 8 green. Otherwise ask 8’s tech-writing half must go into `## Descoped` / `## Plan deltas`.

## Checked and fine (the dispatch’s specific points)

- **Story 01: tower counts only for its own base.** This narrows semantics: any tower of the character used to count. It matches ask 2 ("её собственная Вышка связи"), and the prod SELECT (12/12 towers bound) is recorded as a plan delta. `checkCoverage()` is deterministic and keeps its return shape. The `level<1 → 1` quirk is preserved.
- **Story 04: suffix consumption.** `Base_b<id>` is consumed by `ShowBaseInfoAction` → `showBaseById()` with a fresh `resolveForBase()`. `Build_b` is harmless but meaningless (minor 5).
- **Story 08.** The confirm button carries the suffix through `BaseCallbackSuffix::append()`. Without a base it is byte-identical to before. `confirmUpgrade()` re-validates through `resolveForBase()`. The baseline edit is removal-only.
- **64-byte limit and legacy callbacks.** `append()` throws above 64 bytes (tested at 64 and 65). Real button lengths are asserted in BasePicker, BuildingCardBaseChoice and UpgradeConfirm tests with a 10-digit id. Unsuffixed callbacks take the unchanged `resolve()` path. No callback in `app/` beginning `Base_` collides with the suffix pattern (_b followed by digits at the end).
- **Ask 5, "only from a base with its own Workshop".** This holds for whichever base `resolve()` picks: `workshopAtBase()` filters on `map_cell_id` before durability is spent. The defect is which base gets picked (major 2).
