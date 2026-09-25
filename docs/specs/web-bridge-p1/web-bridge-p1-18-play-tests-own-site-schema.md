---
story: web-bridge-p1-18
spec: web-bridge-p1
status: done
tier: 1
worker: worker-code
tracer: false
wave: 10
blocked_by: [web-bridge-p1-17]
model: opus
returned: DONE
---

# `/play` view tests build the site schema they render

## Goal
Post-merge CI (run 36116558846, develop 38c8a816) fails 17 tests on the empty CI database:
`Tests\Database\PlayControllerTest` (10) and `Tests\Unit\Views\PlayViewsTest` (7) render
`site/layout.php`, which reads `site_categories`, and error with
`Table 'wildworld_tests.site_categories' doesn't exist`. Locally the table survives from other tests,
so the suite was green (memory `feedback_ci_runs_on_empty_db_repro_locally`,
`feedback_db_test_must_build_own_schema`, `feedback_test_schema_must_come_from_migration`).
`tests/database/AccountLinkPageTest.php` (story 17) renders the same layout and passes in CI — reuse
its approach. After this story both files create every site table the layout reads, from the real
migration(s), in their own setup, and pass on an empty `wildworld_tests`.

## Requirements
> `/play` читается на ширине 375/768/1440 без горизонтальной прокрутки.

## Files
- tests/database/PlayControllerTest.php
- tests/unit/Views/PlayViewsTest.php

## Acceptance criteria
- [ ] Repro first: drop `site_categories` (and any other site table the layout reads) from the local
      `wildworld_tests` only, run the two files singly → they fail as in CI; after the fix they pass.
- [ ] Schema comes from the migration class, not a hand-written CREATE; no test intent changed.
- [ ] Never drop or recreate the whole `wildworld_tests` database; touch only the tables needed.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
`git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`

## Map slice
`memory/map/website.md`

## Implementation notes
- Repro: layout reads only `site_categories` (SiteCategoryModel nav; auth label is try/catch). Dropped just that table in `wildworld_tests`; PlayControllerTest 10 errors, PlayViewsTest 7 errors, as in CI.
- `tests/database/PlayControllerTest.php`: after its migrations, runs `CreateSiteCategoriesTable` via the existing `migration()` helper only if the table is missing; `dropTables()` drops it only when this test created it (no wipe of a pre-existing table).
- `tests/unit/Views/PlayViewsTest.php`: new setUp/tearDown, same conditional create/drop via the migration class (require_once like AccountLinkPageTest). Test bodies untouched.
- After fix: both files green singly with the table absent, and the table is gone again after each run (cleanup verified). Restored local `site_categories` afterwards with `CREATE TABLE ... LIKE mmorpg.site_categories`.
- Verification: full phpunit 4527 tests OK (10 skipped, deprecations = pre-existing XML-schema notice); phpstan no errors; migrations lint OK.

## Findings
