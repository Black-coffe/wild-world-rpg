# Review: h6-relics-1127

## Round 1 - 2026-11-27 - head 21dd86bc4e54e081876e01c53d8800818b250be4

VERDICT: PASS
TESTS: vendor/bin/phpunit --no-coverage --no-progress tests/database/LootTableServiceTest.php tests/database/StrategicLootWeaponsTest.php -> pass, 10 tests, 47 assertions (1 PHPUnit deprecation notice)

## Asks
1. delivered: resources 69/70/71 are renamed in place and the cave post is rewritten by the same migration, app/Database/Migrations/2027-01-03-100000_H6RenameRelicsAndBracketWeapons.php:112-125; canon line at GAME_DESCRIPTION.md:556. A dry run of the replacements on the local post gives text identical to the canon file.
1a. delivered: the three new descriptions promise no effect and call «Дракон-4» a factory index, same migration :44-62.
2. delivered: the migration never touches `character_resources`; the test asserts holder rows and quantities are identical after two up() runs and after down(), tests/database/H6RenameRelicsMigrationTest.php:153. The prod before/after count is a deploy-time SELECT and is not visible from the branch.
3. delivered: `collection_slots.resource_name` is updated in the same loop, migration :121-123; the test confirms held quantity resolves and the donated collection stays complete, test :164-167.
4. delivered: weapons 3 and 10 and task 56 are fixed by id plus old value, migration :152-168; craft screen text at app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchStandard/Weapons/WeaponsCraft2Select.php:23. No «Pipe Gun» or «(Firebomb)» is left in any tracked file outside the migration and its test.
5. delivered: every write is keyed on the old value or is a replace that finds nothing on a second run; up() twice is tested; `php -l` reports no syntax errors.
6. partly: no table or column is added, so WipeManifest is correctly untouched, and the diff has no tip seed or guide change, which fits «нет». The written tips verdict with its reason is not in the code, the repo docs outside the specs folder, or the vault notes for this work; I do not read the specs folder, so I cannot confirm it is recorded.
7. not judged: Tier-3 runs on preprod, which only deploys from `develop` after merge; nothing at `head` can show it. It does not count toward this verdict and stays open until the smoke is done.

## Findings
- [minor] [ask 6] Tips verdict (yes/no with reason) is not found outside the specs folder. If it is only in the plan, nothing to do; if it is nowhere, add one line to the spec before merge. Test: none, paperwork.
- [minor] [correctness] Admin text still names the old relics: `game_settings.rationale_text` of `rare_event.RevealingHiddenCameras.chance_per_tick` reads «спавнит Сердце Дракона/Древняя Книга/Корона». The migration fixes the other admin text (`below_effect_text` of the buy gate, migration :171-193) but not this one. Admin-only, no player sees it. Smallest fix: one more replace in the same migration. Test: after up(), no `game_settings` text column contains «Сердце Дракона».

Orchestrator note: finding 1 — the tips verdict is recorded in plan.md Assumptions («Tips — no: a rename adds no mechanic; no existing tip names these items»).

## Round 2 - 2026-11-27 - head 81cb9e54a1dc40281d88dd96f5c95a848b89037a

VERDICT: PASS
TESTS: `vendor/bin/phpunit --no-coverage --no-progress tests/database/LootTableServiceTest.php tests/database/StrategicLootWeaponsTest.php` -> pass, 10 tests, 47 assertions. Also run: `tests/database/H6RenameRelicsMigrationTest.php` -> pass, 3 tests, 140 assertions; `php -l` on the migration -> no syntax errors.

## Asks
1. delivered: relics 69/70/71 are renamed in place and the site post is updated by the migration itself, migration :130-140; no old name is left in `GAME_DESCRIPTION.md`, `app/Data` or `public`.
1a. delivered: the new descriptions promise no effect and name «Дракон-4» as a factory index, same file :44-63.
2. delivered: only `name` and `description` of the resource row change and its id stays, so holder rows are untouched, same file :131-133; the prod before/after count is a deploy step and cannot be checked at head.
3. delivered: `collection_slots.resource_name` is updated by the same migration, same file :135-137.
4. delivered: weapons 3 and 10 and task 56 are renamed, same file :90-102, and the craft screen text at WeaponsCraft2Select.php:23; no «(Pipe Gun)» or «(Firebomb)» is left in player text under `app/`.
5. delivered: a second `up()` changes nothing (tested in H6RenameRelicsMigrationTest.php), and `php -l` passes.
5a. delivered: the migration now replaces the old names in all four `game_settings` text columns of every row, migration :105-121 and :190-232. A read-only run of the same replacements on the local `game_settings` leaves no old name in the two rows that had one.
6. partly: there is no schema change, so WipeManifest is correctly untouched, and the guide verdict is in the brief; a tips verdict with its reason is not found anywhere outside the specs folder (see F1).
7. partly: Tier-3 on preprod-testbot cannot be shown at head; it runs after the preprod deploy.

## Findings
- [minor] [ask 6] The tips verdict (yes/no with reason) is still not found outside the specs folder. The branch adds no tip seed, so the de facto verdict is "no", but the reason is not written where I can see it. If it is in the plan, nothing to do; otherwise add one line to the spec before merge. Test: none, paperwork.

## Past findings (round 2 and later)
F1: open - no tips verdict outside the specs folder; I do not read the plan, so I cannot confirm it there. Still minor and not blocking.
F2: fixed - `replaceInGameSettings` covers rationale/effect/above/below for every row, including the «…/Корона (» short form. `testUpTwiceLeavesNoOldRelicNameInAnyGameSettingsText` fails without the fix and covers re-run and `down()`. Rows mentioning «Древние реликвии» (a different resource) are not matched.

Orchestrator note: F1 — the tips verdict is in plan.md Assumptions («Tips — no: a rename adds no mechanic; no existing tip names these items (recon: 0 matches)»).
