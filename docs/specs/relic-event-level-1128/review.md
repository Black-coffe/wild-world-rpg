# Review

## Round 1 - 2026-11-28 - head be82e2a4f96f0f8dd962919f476521cc41b7ba1f

VERDICT: PASS
TESTS: vendor/bin/phpunit --no-coverage --no-progress tests/database/IntentApplierRelativeDeltasTest.php tests/unit/Services/Events -> pass, 141 tests, 1839 assertions, 2 skipped (EventCloseHandlerSmokeTest, EventDispatcherSmokeTest: test DB has no active_events table)

## Asks
1. delivered: candidates are filtered by `level_required <= character level` in one shared query, app/Models/ResourceModel.php:215; the applier returns without writing when none fit, app/Services/Events/IntentApplier.php:179-182; the effect returns `skipped`, so no log or summary entry, app/Services/Events/Effects/RareResourceGrantEffect.php:93-109.
2. delivered: keyword / biome / rarity filters and the amount calculation are unchanged (IntentApplier.php:175-185, RareResourceGrantEffect.php:111-123); `testEventGrantAtRequiredLevelStillLands` gives a level-100 character 3 of 3.
3. delivered, with the evidence file unread: the owner's decision and the figure (13 under-level units stay with holders) are in the brief's `## Answers`; docs/specs/relic-event-level-1128/evidence.md exists at head, but my rules bar reading it, so its SELECTs are unchecked.
4. delivered: the threshold is the resource's own `level_required` column (ResourceModel.php:217); the only literal is the `0` fallback for a snapshot without a level, which grants nothing.
5. partly: the tech-writing note is updated (vault commit 8b6de28, also ResourceModel.md and RareResourceGrantEffect.md); the guide and tips verdicts are not stated anywhere outside the specs folder, and I could not check inside it.

## Findings
- [minor] [ask 5] Guide and tips verdicts not found in the code, the vault notes, the vault daily note or hot.md. If they are also missing from evidence.md / plan.md, the ask is not fully delivered. Smallest fix: one line each in evidence.md. Check: `grep -i "guide\|tips" docs/specs/relic-event-level-1128/evidence.md` returns both verdicts.
- [minor] [correctness] The effect's production finder (default closure calling `new ResourceModel()->eventGrantCandidates`, RareResourceGrantEffect.php:99-100) is never run by a test: every effect test injects `fakeFinder`, which reimplements the level rule. The model query itself is covered through the applier's DB tests, so the gap is only the wiring.
