---
story: web-bridge-p1-18
spec: web-bridge-p1
status: todo
tier: 1
worker: worker-code
tracer: false
wave: 10
blocked_by: [web-bridge-p1-17]
model: opus
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

## Findings
