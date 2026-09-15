<!-- seat: review · model: claude-opus-5 · round: 3 · head: 65cb4b3c · pack: ce99166e76e3 · attempt: 1 · recorded: 2026-09-15T11:38:16Z · verdict: PASS -->
VERDICT: PASS

# lead-review: multibase-picker, round 3 (repair: stories 11 and 12 @ 6ae041b6)

Note: this file has no backslashes and no single quotes, because the shell write failed on them. Array keys are written with double quotes, and namespaces are written with spaces instead of backslashes. Otherwise it is identical to the chat copy.

**Scope.** Every app and test file in the two repair commits is listed in its story `## Files`. Story 11 touched DetailedBaseInfoAction, BaseDevelopmentAction, DetailedBaseInfoBareConstructionTest and BaseDevelopmentBaseScopeTest. Story 12 touched BaseService, BasePickerTest, HangarBaseScopeTest, StartRobotGatheringBaseTest and CompleteRobotGatheringBaseTest. The only other files are `memory/stats/scope.jsonl` and the story files, which are paperwork. The non-goals held: BaseScopeResolver, CommunicationTowerCoverageService, StartRobotGatheringAction, RobotGathererActivator, CompleteRobotGatheringHandler, HangarAction and BuildingCardBaseScopeTest are not in either diff. No Law 3 violations.

**Project gates.** No new balance numbers: the tower numbers still come from `coverageByBase()`, which reads the GameSettings radius. No new tables or columns, so WipeManifest does not apply. Media-off holds. The bare `construction` screen still carries base coordinates, biome, tower level and distance in its caption. "Развитие базы" is text-only and now names the base as "Имя (x, y)" through the markdown-safe `baseLabel()`. The new picker refusal is text-only.

**What I ran.** Only diff reads and greps. I did not re-run the suite. The one suspicion, that the caption of `activate()` cannot be reached (story 12 Findings), I settled by reading the fopen shim and checking the image with `git ls-files`.

## Round-2 findings: status

- **Ask 3 RED and minor 1: closed.** Bare `construction` off-base under tower cover now does this:
  - it takes the base from `resolve()["cell"]` and maps it to its row in `$activeCells`;
  - it builds the header from the `coverageByBase()` row of that same base (`coverageResultForBase`);
  - it stamps `_b<that id>` on every building button and on "Развитие базы" (DetailedBaseInfoAction.php:103-139).
  - `checkCoverage()` and `coverageByBase()` both call `buildRows()` with the cell of the character, so when one is covered the other is too. That makes the `cell=null` branch and the `TEXT_AMBIGUOUS` branch effectively unreachable. The uncovered path `handleNotOnBasePhysically()` is unchanged, and it gets `$activeCells[0]` as before.
  - The claim that `resolve()` does not return `base_id` is true (BaseScopeResolver.php:53).
  - The single-base case is consistent. `resolveTargetBaseCell()` returns the only base, and the covered row of `checkCoverage()` belongs to that same base.
  - `testOnlySecondBaseTowerCoversShowsSecondBase` would go red on the old code, because the old code shows "L10" from base 1 and the test asserts it is absent.
- **"К базе" suffix and base name: closed**, except for minor 2 below (a note that does not match the code) and minor 3 (a design issue on the refusal path).
- **Minor 2 (legacy robot order): closed.**
  - Why MyISAM is needed is stated correctly: InnoDB returns a primary-key scan in ascending id order, while MyISAM returns rows in insert order when nothing has been deleted.
  - The test table has no secondary index, so the query without `orderBy` is a physical scan and returns id=9 first.
  - The red/green run the worker reports is plausible. I did not repeat it.
- **Minor 3 (activator names the launch base): not closed.** The narrowing rests on a false claim. See major 1.
- **Minor 4 (hub lock text and path): closed.** Both no-base hub tests now assert the full lock line for their `reason` and the full path line.
- **Minor 5 (picker refusal text): closed.**
  - `TEXT_PICKER_ROW_MISSING` is used only in the branch where one base is covered but its row is missing.
  - `assertSame` pins the text, and two negative asserts check it does not mention the tower signal or tell the player to stand on the base.
  - The branch is really reached, because `find()` is substituted (as in round 2).
- **Minor 6 (docblock): closed.**

## Critical

None.

## Major

1. `tests/unit/Camp/StartRobotGatheringBaseTest.php:329-355` (`testActivatorRunsAndNamesSameBaseAsLaunchHelper`) and the story 12 `## Findings`. Routing: **worker**.
   - **Coverage gap.** The test passes the cell from `launchBase()` into `buildCaption()` itself. If `activate()` stopped using `launchBase()` and picked a different base, the test would stay green: the `activate()` call only has to throw an exception whose message contains `robot_gatherer.jpg`. That is the exact regression minor 3 asked a test to catch.
   - **Invented fact.** The reason the Findings give for narrowing, that the caption is "unreachable given Non-goals", is false. The repo already has a test-only way to make it observable without touching production code. The process-wide fopen shim in namespace Longman TelegramBot (`tests/unit/Camp/BuildingCardBaseScopeTest.php:27-40`) is switched on by `BuildingCardBaseScopeTest::$fopenShimActive`. It rewrites `base_url(...)` to the local file, and `public/uploads/telegram/craft/standard/robot_gatherer.jpg` exists and is tracked in git. `DetailedBaseInfoBareConstructionTest` in this same round uses the shim to read a real caption from a real `ServerResponse`.
   - **Not on the record.** The narrowing is written only in the story `## Findings`, not in plan.md `## Descoped` or `## Plan deltas`.
   - **Condition:** the minor 3 test must read the base name and coordinates from the caption that `activate()` itself returns. It can use the existing test-side shim, with no production edit. Removing `launchBase()` from `activate()` must turn the test red. If that turns out to be impossible, the narrowing must be recorded in plan.md with a reason that is true.
   - The production code is correct: `activate()` calls `launchBase()` at RobotGathererActivator.php:75 and passes `$baseCell` into `buildCaption()` at :104. So this is a test-theater gap plus a false claim, not a player-facing defect, and I rank it major, not critical.

## Minor

2. `C:/Projects/mmorpg-vault/tech-writing/handlers/camp/BaseDevelopmentAction.md` (vault commit b285416). Routing: **worker**.
   - The note says the "База: Имя (x, y)" header appears "on the success path and on the `refusal()` refusal".
   - In the code, `refusal()` (BaseDevelopmentAction.php:125-137) sends only the resolver text and never calls `baseLabel()`.
   - **Condition:** the note must describe the header only on the success path, as the code does.
3. `app/Controllers/Telegram/Commands/Actions/Camp/BaseDevelopmentAction.php:83-84` with `:119-121`. Routing: **plan** (story 11 Goal 3 explicitly required the suffix inside `refusal()`).
   - When `resolveForBase()` refuses `baseDevelopment_b<id>`, the only way forward on the refusal, "К базе", is `construction_b<id>` for the same base. `DetailedBaseInfoAction` then re-runs `resolveForBase()` and refuses again with `TEXT_UNAVAILABLE`, which is a guaranteed dead end.
   - **Condition:** the button on a refusal screen must lead somewhere that does not repeat the same refusal (for example the bare picker path). Or the dead end must be recorded as accepted in `## Открытые хвосты`.
4. `tests/unit/Camp/BaseDevelopmentBaseScopeTest.php:316-347`. Routing: **worker**.
   - The new behavior "К базе keeps the suffix on the refusal screen", which the story 11 Implementation notes say was delivered, has no test. Only the success path with a suffix and the bare path are asserted.
   - **Condition:** the suffixed refusal path must have a test that asserts the `callback_data` of the button. Or, if minor 3 changes that behavior, the test must assert the new target.
5. `app/Controllers/Telegram/Commands/Actions/Camp/BaseDevelopmentAction.php:143-147`. Routing: **plan**.
   - The empty-state text "У тебя пока нет построек … Разбей лагерь («База» → «Разбить лагерь»)" now sits under a header naming a specific existing base.
   - For a player whose chosen base has no buildings, the screen names the base and then tells them to set up camp.
   - **Condition:** the empty state must say that this base has no buildings yet and point to building on it, not to setting up a camp. This text was there before this round, but the new header makes it contradictory.
6. `app/Controllers/Telegram/Commands/Actions/Camp/DetailedBaseInfoAction.php:101,106,137`. Routing: **plan**.
   - The covered bare path now computes coverage four times per tap. It calls `checkCoverage()` itself. `resolve()` then calls `checkCoverage()` again and runs `coverageByBase()`. `coverageResultForBase()` then runs `coverageByBase()` once more.
   - **Condition:** one tap should compute per-base coverage at most once, or the cost should be recorded as accepted.
   - This is cosmetic on the one app node that exists today. It does not justify a round.
7. `docs/specs/multibase-picker/plan.md:81-89`. Routing: **plan**.
   - `## Открытые хвосты` correctly strikes out the items stories 11 and 12 close and keeps the rest open: UNASKED 1-3, `Build_b` and the bare back buttons.
   - The labels "Minor 5" and "Minor 6" use the round 1 numbering, while round 2 reused those numbers for different things, so the list is ambiguous. The list also leaves out the narrowing from major 1.
   - **Condition:** each open tail must name the round it came from, and the list must include the minor 3 narrowing if it is not closed.

## Checked and fine

- Byte-identical legacy refusal: `testNoTowerCoversKeepsLegacyRefusalByteForByte` pins the full `handleNotOnBasePhysically()` text and the `[TeleportToCamp, move]` buttons. That code is unchanged.
- `assertStampedWith` checks every building button and "Развитие базы" for `_b<id>`, and checks that `resolveForBase()` does not answer `unavailable`. Both AC 3 scenarios are covered.
- `baseLabel()` is called with its existing signature and escapes the camp name through `MarkdownSafe::name`, so the `parse_mode` Markdown header is safe.
- No reinvention: `coverageResultForBase()` and `BaseCallbackSuffix::append()` are the existing helpers, reused as they are.
- Security: the base id from the callback is still checked by `resolveForBase()` (owner, active, reachable) before use. No new input surfaces.
- Tech-writing for DetailedBaseInfoAction, BaseService, BaseScopeResolver and ClaimedCellModel is updated in vault commit b285416, and it matches the code (the BaseDevelopmentAction exception is minor 2).
