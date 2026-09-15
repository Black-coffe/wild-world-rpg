<!-- seat: review · model: claude-opus-5 · round: 2 · head: e8239424 · pack: b5d957a52620 · attempt: 1 · recorded: 2026-09-15T11:03:18Z · verdict: PASS -->
VERDICT: PASS

# lead-review: multibase-picker, round 2 (repair: stories 09, 10 and plan deltas @ 7c6bc7d4)

Note: this file has no emoji and no backslashes, because the shell write failed on them. Emoji are written as words (lock icon, base icon), and class names are written without the leading namespace backslash. Otherwise it is identical to the chat copy.

**Scope.** Every app and test file in stories 09 and 10 is in its story `## Files`. The plan delta at 7c6bc7d4 adds `tests/unit/Services/Buildings/BaseScopeResolverTest.php` to story 09 `## Files` before it was used. `memory/stats/scope.jsonl` is paperwork. No Law 3 violations.

**Project gates.** No new balance numbers. Distance and level come from the existing GameSettings `communication_tower.coverage_per_level`. No new tables or columns, so WipeManifest does not apply. Media-off holds: the new hub and the new refusals are text-only, and the launch caption still names the base and its coordinates.

**What I ran.** Diff reads and greps only, no test re-runs. The one suspicion (the minor 8 test) was settled by reading CI4 `Model::doFirst()` and the test schema, not by running anything.

## Round-1 findings: status

- **Major 2 / ask 5: closed.**
  - `StartRobotGatheringAction::launchBase()` picks the base in this order:
    - the base the player stands on;
    - otherwise the first `coverageByBase()` row with `isCovered` that has its own Workshop;
    - covered bases exist but none has a Workshop: refuse, naming the first covered base;
    - nothing covered: `cell=null` and a refusal.
  - An uncovered base is never returned. `coverageByBase()` is ordered by `claimed_cells.id` (`buildRows` calls `findAllActiveCells`, which orders by id ASC).
  - `resolve()` keeps the `checkCoverage()` gate, then takes the first covered row. Texts and return shape are unchanged.
  - The `BaseScopeResolverTest` edit is only a `coverageByBase()` override on the double. `BuildingCardBaseScopeTest` and `BuildingUpgradeBaseScopeTest` are untouched.
  - The four off-base scenarios in `StartRobotGatheringBaseTest` use the real coverage service with seeded towers. They would fail if the pick reverted to first-by-id.
- **Minor 7: closed.** The test catches only TelegramException or ErrorException and asserts the message names `robot_gatherer.jpg`. The caption comes from the real `handle()` through `lastLaunchCaption`, so dropping the base label from the real call would turn it red.
- **Minor 8: code closed, but see minor 2 below.** An `orderBy` on id ASC was added.
- **Major 1: closed.** Bare `hangar` with no base in scope now renders `renderNoBaseHub()`:
  - it shows the robot/drone blocks, the buttons `AllRobots`, drone types, `craftInsuranceList` and a bare `Base`;
  - the lock line depends on `reason`, and the build path is shown;
  - no base name and no Workshop level appear.
  - The suffixed path and the single-base path are unchanged.
- **Minor 3: closed.** Exactly one covered base with no row found now returns `TEXT_UNAVAILABLE`, not the "several bases" picker. The test substitutes `find()`, which is what `findBaseRow()` calls (`BaseService.php:187`), so the test does reach the branch.
- **Minors 4/5/6: recorded** in plan.md `## Открытые хвосты` (lines 77-79). Minor 4 is recorded but not closed, and the plan reason for expecting it closed is wrong (minor 1 below).
- **Ask 8, tech-writing half: delivered.** Vault commit a46b379 has `tech-writing/services/BaseScopeResolver.md` and `tech-writing/handlers/camp/HangarAction.md` with `last_reviewed: 2026-09-15`.
  - `BaseScopeResolver.md` describes the covered-base tower rule (lines 69-74).
  - `HangarAction.md` has a `renderNoBaseHub` section (line 79).
  - ADR-187 is updated: the tower table row (line 42), rule 6 for the launch base, and "Revisit when" for choosing between several covered bases.

## Critical

None.

## Major

None.

## Minor

1. `app/Controllers/Telegram/Commands/Actions/Camp/DetailedBaseInfoAction.php:91-114`, with `docs/specs/multibase-picker/plan.md:77`. Routing: **plan**.
   - Plan.md says story 09 would close minor 4 in passing, because after it `resolve()` and `checkCoverage()` would both pick the first covered base. That is false for this screen: `DetailedBaseInfoAction` does not call `resolve()`.
   - The screen still takes the first entry of `findAllActiveCells()` and only gates on `checkCoverage()`. When only the base 2 tower covers the player, bare `construction` still shows base 1 and stamps the base 1 id. `resolveForBase()` then rejects that id.
   - The tail is recorded, so this is not silent narrowing, but the note premise is an invented fact and the Queen check after wave 4 will fail.
   - Condition: the bare `construction` screen must show and stamp the same base `resolve()` would choose for that player, or the open-tails entry must say plainly that minor 4 is still open and why.

2. `tests/unit/TaskHandlers/CompleteRobotGatheringBaseTest.php:210-229` (`testLegacyTaskPicksActiveBaseWithLowestId`). Routing: **worker**.
   - The test cannot fail without the fix. `claimed_cells` in the test schema has only a clustered PK and no secondary index, so InnoDB scans in PK order and `first()` returns id 3 whatever order the rows were inserted in.
   - CI4 `Model::doFirst()` adds an ORDER BY only when there is a GROUP BY (soft deletes). `ClaimedCellModel` has `useSoftDeletes = false`.
   - The `orderBy` is the right fix. The story claim that inserting in reverse proves it is not.
   - Condition: the legacy-fallback test must go red when the id-ASC `orderBy` is removed, for example by forcing a scan order where the lowest id is not first. Otherwise the story must not claim it proves determinism.

3. `tests/unit/Camp/StartRobotGatheringBaseTest.php:403-415`. Routing: **worker**.
   - Criterion 4 of story 09 says the robot screen in `RobotGathererActivator` picks the base with the same helper and names the same base. The test calls only `launchBase()`. `RobotGathererActivator::activate()` is never run.
   - A regression that sent the activator back to `resolve()` would stay green.
   - Condition: the activator criterion must be proven by running `RobotGathererActivator::activate()` and asserting on the base it names. Otherwise the story claim of coverage must be narrowed to the helper.

4. `tests/unit/Camp/HangarBaseScopeTest.php:623-641`. Routing: **worker**.
   - The two-bases-out-of-signal hub test checks for the lock icon and checks that nothing leaks. It never asserts the Workshop build path (base icon, then Build, then Robotics Workshop) or the ambiguous-reason lock text, which the story first criterion requires.
   - The no-bases test only reaches the words "Robotics Workshop" through a substring.
   - Condition: each no-base hub test must assert the lock explanation for its own `reason` and the path to the Workshop.

5. `app/Services/BaseService.php:175-178`. Routing: **plan**.
   - In the minor-3 branch the player is under that base signal, yet `TEXT_UNAVAILABLE` tells them to stand on the base or come under its tower signal.
   - The reply also has no buttons, while the other uncovered bases the picker would have listed as text are dropped.
   - The story allowed a reply without buttons, and this path is a race or deleted-row case, so the impact is small.
   - Condition: the refusal for a covered base whose row has vanished must not tell the player to do something they have already done.

6. `docs/specs/multibase-picker/multibase-picker-09-launch-base-covered.md:66`. Routing: **worker**.
   - The Implementation notes say the success test asserts the message names `robot_gatherer.jpg`. That assertion runs only if an exception is thrown. If a future stand serves the photo, the test just passes on the caption, which is fine.
   - The class docblock (`StartRobotGatheringBaseTest.php:197-199`) says the only expected exception is TelegramException, but the code also catches ErrorException.
   - Condition: the test docblock must describe the exceptions the test actually catches.

## Checked and fine

- **Single-base players off-base.** `launchBase()` uses `coverageByBase()`, which reads the same `buildRows()` as `checkCoverage()`, so they cannot disagree. The fallback throws away the single-base cell from `resolve()` and returns `TEXT_NOT_COVERED`, so an uncovered single base is not launched from either.
- **`RobotGathererActivator` with a covered base and no Workshop.** It shows that base screen with the lock button, and the launch then refuses with the same base name. The two are consistent.
- **`resolve()` when the gate says covered but no per-base row is covered.** It falls to `ambiguous`, and `BaseScopeResolver.md` documents this.
- **`automationRows` with a null base id.** It emits a bare `Base`, which leads to the picker. It invents no suffix.
- **Robot-chain base number (two covered bases, both with a Workshop).** Recorded in `## Открытые хвосты` and in the ADR-187 "Revisit when".
