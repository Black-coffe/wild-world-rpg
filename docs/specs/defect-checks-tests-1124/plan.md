# Defect checks as PHPUnit tests (plan)
**Brief:** [brief.md](brief.md) · **Branch:** `vulyk/defect-checks-tests-1124` · **Approved:** «Одобрить (Рекомендую)», 2026-11-24

## Goal
The six `scripts/defects-*-check.php` scripts stopped running when the old VULYK gate (`defects-check.sh`) went
away. Wrap them in PHPUnit: every test runs the real script as a subprocess (`PHP_BINARY`, cwd = repo root), so
the manual command and the test are one source of truth. Each check gets two kinds of cases: every fixture the
card declares must make the script exit non-zero (the gate is not blind), and the live run on today's code or
plans must exit 0 (the defect is not back).

## Assumptions
- Scripts are run as subprocesses, not included: all six are top-level code with `const` and `exit()`
  (recon). Precedent: `tests/unit/PostDeployMigrateGateTest.php` runs scripts via `proc_open`.
- Fixture lists are read from each card's `fixtures:` front matter in `docs/defects/*.md`, so a fixture added
  to a card is tested with no test edit; a listed fixture that does not exist fails the test.
- Tests live in `tests/unit/Defects/` → suite part **unit2** (`tests/unit/[!S]*`). Measured script cost
  ~7 s in total, well inside 300 s.
- `task-result-foreign` with no argument took ~29 s with local MySQL down (its catalogs read GameSettings).
  S1 decides it by running it once with local MySQL up and once down: if the live run needs the DB, that one case moves to `tests/database/DefectTaskResultForeignCheckTest.php`
  (part db3) and the fixture cases stay in unit. Only the local `wildworld_tests` DB, never preprod/prod.
- `preprod-parity` cannot see live data in a test (it needs SSH to both servers, Ask 5): the test runs its two
  fixtures (must fail) and a synthetic clean snapshot (must pass). The live comparison stays in
  `bash scripts/preprod-parity.sh` before a preprod smoke.
- `audience-surfaces` live case runs over every `docs/specs/*/plan.md` without a filled `**Shipped:**`. Six old
  plans (2026-08-19…09-04: `backpack-teleport-base-choice`, `community-chat-bot`, `craft-shortfall-buy`,
  `exploit-fix`, `storage-craft-insurance`, `transport-system`) fail it today: they predate the check (added
  2026-10-12) and are shipped or dormant without a Shipped line. They go into a frozen legacy list in the test
  with that reason; every other and every new plan is checked. A spec's plan is checked on its own branch,
  which is where it matters. A thin-VULYK spec reaches `develop` with `**Shipped:**` already filled (the ship
  commit sets it before the merge, e.g. `c8ddb481`), so a merged plan does not trip the suite; this spec's own
  plan touches no player surface and passes.
- The duel script duplicates part of `tests/database/DuelOutcomeTest.php`; it is still wrapped, because its
  fixtures (original, neighbour, first-strike) are the proof the check is not blind, and it needs no DB.
- Not part of this work: changing what any check detects; removing the scripts; the old cards' front matter.
- `docs/defects/README.md` and `INDEX.md` name `bash scripts/defects-check.sh`, which no longer exists: they get
  a short note that the gate is now `tests/unit/Defects/`.
- Goes live: nothing runs on merge except CI's test suite (tag deploy runs it); no player-facing change.
  Guide/tips verdict: no — dev tooling only.

## Stories
- [x] S1 Harness and the five code checks - asks: 1, 2, 3, 5
  - decided: `task-result-foreign` live run measured exit 0 both ways, <1 s with local MySQL up, ~29 s down →
    moved to `tests/database/DefectTaskResultForeignCheckTest.php` (db3); its fixture cases stay in unit.
  - files: `tests/unit/Defects/DefectCheckRunner.php` (subprocess helper + card front-matter reader),
    `tests/unit/Defects/DefectCodeChecksTest.php`; possibly `tests/database/DefectTaskResultForeignCheckTest.php`
  - test first: the fixture cases are the test of the gate: each declared fixture of bot-lexicon,
    confirm-once, duel-knockout, preprod-parity and task-result-foreign must exit non-zero; a deliberately
    clean input (empty dir / clean TSV) must exit 0; then the live runs on `app/` must exit 0. Proved red by
    pointing one case at a fixture with the expectation inverted once, locally, before committing.
  - helper autoload: `DefectCheckRunner` must load through the project's test autoload (check `composer.json`
    `autoload-dev`), or be a trait/static inside the test file.
  - check: `vendor/bin/phpunit --no-coverage --no-progress tests/unit/Defects`
- [ ] S2 Audience surfaces over plans, and the docs - asks: 1, 2, 3, 4
  - files: `tests/unit/Defects/DefectAudienceSurfacesTest.php`, `docs/defects/README.md`,
    `docs/defects/INDEX.md`, `.claude/rules/player-text.md`, `.claude/rules/game-integrity.md`, `CLAUDE.md`
    (the `docs/defects` line: checks run in the suite)
  - test first: three fixtures must exit 1; every unshipped plan outside the legacy list must exit 0; a legacy
    entry whose plan now passes or got a Shipped line fails the test, so the list only shrinks.
  - check: `vendor/bin/phpunit --no-coverage --no-progress tests/unit/Defects`

## Test command
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Defects` per story; before review the unit2 part
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/[!S]* tests/unit/S[!e]*` and phpstan.

## Descoped
(none)
