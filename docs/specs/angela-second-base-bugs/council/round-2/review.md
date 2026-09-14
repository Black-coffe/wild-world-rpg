<!-- seat: review · model: claude-opus-5 · round: 2 · head: 874d92e9 · pack: 6cb085774590 · attempt: 1 · recorded: 2026-09-14T06:45:02Z · verdict: PASS -->
VERDICT: PASS

**LEAD-REVIEW: angela-second-base-bugs, council round 2.** HEAD 874d92e9, diff `develop...HEAD`, main tree.

No critical or major findings, and all four round-1 majors are closed. There are 9 minor findings, listed at the end.

**Not verified by me:** the full suite. Seven php.exe processes were running (other seats), and the project rule forbids a parallel run. The "4114 tests green" claim in story 07 and the "4107 green" claim in story 05 rest on the workers' word only.

## What I checked myself

1. **Round-1 major 1 is fixed.** `BuildingCardBaseScopeTest` passes when run on its own: 5/5. It creates its own `bcbs_*` tables through `setPrefix()` and checks the columns it needs.
2. **Round-1 major 2 is fixed.** The `fopen` stub is still declared for the whole process, but it passes straight through to the real `fopen()` unless `$fopenShimActive` is on. That flag is switched on in `setUp()` and off in a `finally` in `tearDown()`.
3. **Round-1 major 3 is fixed.** `ClaimedCellFindAllActiveCellsTest` calls the real `findAllActiveCells()`; only `$table` is swapped. Together with `BaseScopeResolverTest`: 12/12 green.
4. **Round-1 major 4 is fixed.** The `building_type` ENUM in the upgrade test now includes `defensive` and is `NOT NULL`. `BuildingUpgradeBaseScopeTest` plus `TextMapMultiBaseTest`: 13/13 green.
5. **Round-1 minor 5 is fixed.** `BaseScopeResolver` returns a typed reason, and "no bases" is now a separate text.
6. **All 14 building cards are scoped to the base.** All 14 handlers that `BuildingHandlerAction` routes to call the resolver and filter by `map_cell_id`; no direct `resolveTargetBaseCell` calls remain. `DefensiveBuildingHandler` keeps its `orderBy('hp')`.
7. **The story-07 claim that remote upgrade never worked is true.** `upgrade_building_` goes only through `CallbackPrefixDispatcher.php:60` → `UpgradeBuildingAction` → the validator, which requires the player to stand on the base. `RoboticsWorkshopUpgradeAction`, the only subclass of `BaseBuildingUpgradeAction`, is never created anywhere in `app/`.
8. **The remote view and the card agree.** `DetailedBaseInfoAction` and the resolver both pick the first active base by `id`, and an abandoned base is never picked.
9. **phpstan:** No errors.
10. **Scope:** the round-2 commits touched only the files their stories name, plus spec paperwork. The one widening — the upgrade test added to story 07 — is written down in that story.
11. **Project rules:** no new tables or player columns, so WipeManifest is not involved. No hardcoded balance numbers. The refusal texts are plain text with no `parse_mode`, so they read fully with images off and are safe for Markdown.

## MAJOR
None.

## MINOR

1. **Test covers 2 of 14 cards** — `tests/unit/Camp/BuildingCardBaseScopeTest.php:343-422`
   - Routing: **plan**.
   - The claim in story 05 that "the proof that all fourteen cards read their own base exists again" is overstated: the test drives only HandPump and Warehouse. I confirmed the other twelve by grep, not by a test.
   - Condition: either every handler routed by `BuildingHandlerAction` has its base-scoped read and both refusal texts proven by behaviour, or the claim is narrowed to the two it covers.

2. **Card test schema written by hand** — `tests/unit/Camp/BuildingCardBaseScopeTest.php:116-190`
   - Routing: **worker**.
   - Story 05 said to take the schema from the migrations. The test uses VARCHAR where the real tables have ENUMs: `character_buildings.building_type`, `claimed_cells.status`, `usage`.
   - Condition: the test's DDL must match the migrations, or the story must record why a minimal schema was accepted.

3. **Vault note has the wrong counts and a dead caller** — `mmorpg-vault/tech-writing/services/BaseScopeResolver.md`
   - Routing: **worker** (drone-docs).
   - It says "twelve cards" and "thirteen direct callers", leaves out `DefensiveBuildingHandler` and `LeanToHandler`, and lists `BaseBuildingUpgradeAction` as a live caller although nothing reaches it.
   - Condition: the note must list all 14 handlers and mark the generic upgrade as unreachable.

4. **Tower coverage may measure against a different base** — `app/Services/Coverage/CommunicationTowerCoverageService.php:68`
   - Routing: **plan**.
   - Coverage distance is measured to an active base picked by `first()` with no `orderBy`. The resolver and `DetailedBaseInfoAction` show the first base by `id`. With two or more bases, "covered" can be judged against a different base than the one the screen shows.
   - The open-tails list names only line 106 of this service.
   - Condition: coverage must be measured against the base the screen shows, or that mismatch must be written into the open-tails list.

5. **Refusals are sent as new messages** — the 14 handlers plus `BaseBuildingUpgradeAction`
   - Routing: **plan**.
   - This carries over round-1 minor 6. The refusal goes out through `Request::sendMessage` as a new message, while the cards edit in place. It was neither fixed nor written into the open-tails list.
   - Condition: fix it, or record it as a tail.

6. **Reply to the reporter still omits the level gate** — `docs/specs/angela-second-base-bugs/reply-draft.md`
   - Routing: **plan**.
   - This carries over round-1 minor 9. The draft still does not say the Lean-to (Навес) is also gated by level (≤ 6).
   - Condition: before it is sent (ask 5), the reply must state both parts of the gate — level and "no buildings yet".

7. **Story 06 criterion changed without a record** — story 06
   - Routing: **plan**.
   - The criterion "the test fails if `orderBy` is removed" cannot be met on this setup (small InnoDB tables come back in `id` order anyway). It was replaced by flipping the sort to DESC, but only the story's `## Implementation notes` says so.
   - Condition: the substitution must be written into `## Plan deltas`.

8. **Test reaches into a private field** — `tests/unit/Player/BuildingUpgradeBaseScopeTest.php:230-239`
   - Routing: **plan**.
   - The test sets the validator's private `baseScopeResolver` field by reflection, because story 07 forbade changing the constructor.
   - Condition: the validator must offer a supported way to inject the resolver, or the test must depend only on public API.

9. **Stories closed with every criterion unticked** — stories 04, 06 and 07
   - Routing: **worker**.
   - All three are `status: done` with every acceptance criterion `[ ]` (4, 6 and 9 unchecked respectively).
   - Condition: each criterion must be ticked with its evidence, or marked as not met.

## Outside the diff (by plan assumptions, not findings)
- Ask 5, second half: the reply to the reporter has not been sent.
- Ask 8, second half: tech-writing notes are updated after the merge.
- Ask 9: the live Tier-3 run on the preprod test bot, including the remote tap from the Communication Tower screen.
- Ask 10: the tag and the prod smoke test.

Relevant files:
- `C:\laragon\www\mmorpg\app\Services\Bases\BaseScopeResolver.php`
- `C:\laragon\www\mmorpg\app\Services\Coverage\CommunicationTowerCoverageService.php`
- `C:\laragon\www\mmorpg\tests\unit\Camp\BuildingCardBaseScopeTest.php`
- `C:\laragon\www\mmorpg\tests\unit\Player\BuildingUpgradeBaseScopeTest.php`
- `C:\Projects\mmorpg-vault\tech-writing\services\BaseScopeResolver.md`

VERDICT: PASS
