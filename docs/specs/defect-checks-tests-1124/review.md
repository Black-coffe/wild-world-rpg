# Review

## Round 1 - 2026-11-24 - head 1516c45ba47cd9ad9235755822297070aaf670ab

```
VERDICT: PASS
TESTS: vendor/bin/phpunit --no-coverage --no-progress tests/unit/Defects -> pass, 26 tests, 54 assertions, 7.6 s (also tests/database/DefectTaskResultForeignCheckTest.php -> pass, 1 test, 3 assertions, 28.5 s with local MySQL down)

## Asks
1. delivered: all six scripts run as subprocesses from PHPUnit. Five are in C:\laragon\www\mmorpg\tests\unit\Defects\DefectCodeChecksTest.php:33 and the sixth in C:\laragon\www\mmorpg\tests\unit\Defects\DefectAudienceSurfacesTest.php:29, both in unit2 (the expanded unit2 glob lists `tests/unit/Defects`). The live task-result run is C:\laragon\www\mmorpg\tests\database\DefectTaskResultForeignCheckTest.php:29, in db3 (`--list-tests` on the db3 glob finds it).
2. delivered: live runs on `app/` for bot-lexicon, confirm-once and duel-knockout, on the real picker for task-result, and on every unshipped plan outside LEGACY for audience-surfaces; all exit 0 today. A scratch `app/` with a defect makes the no-argument mode of bot-lexicon and confirm-once exit 1. Preprod-parity has only a synthetic clean snapshot, because a live comparison needs SSH to both databases, which ask 5 rules out.
3. delivered: all 14 files in `docs/defects/fixtures/` are declared by the six cards and each must exit exactly 1, DefectCodeChecksTest.php:72 and DefectAudienceSurfacesTest.php:80; an undeclared fixture file fails DefectCodeChecksTest.php:91.
4. delivered: the diff does not touch `scripts/`, and the manual commands still run (exit 0 on the repo, 1 on fixtures). C:\laragon\www\mmorpg\.claude\rules\game-integrity.md:6 and C:\laragon\www\mmorpg\.claude\rules\player-text.md:6 now describe the PHPUnit run and the manual command; no "авто-гейта нет" text remains in the rules or CLAUDE.md.
5. delivered: the Defects folder adds 7.6 s to unit2, and the db3 test costs 28.5 s with MySQL down (the test's own comment says under 1 s with it up). No network: the only database touched is `wildworld_tests` on 127.0.0.1. The total unit2 time was not measured: local MySQL is not listening on 3306, and my full unit2 run was still going after 10 minutes, so I stopped it.
6. delivered: out of scope as stated; the diff touches no branches, hook or ADAPTATION.md, and the brief is unchanged since `plan(defect-checks-tests-1124): approved`.

## Findings
- [minor] [ask 2] Six unshipped plans fail the audience-surfaces check today and are exempted by a hardcoded list, C:\laragon\www\mmorpg\tests\unit\Defects\DefectAudienceSurfacesTest.php:32. The brief has no owner answer allowing an exemption. The list is exactly the set failing today (12 unshipped, 6 fail), all first committed 2026-08-19 to 2026-09-02, before the check existed, and `testLegacyListOnlyShrinks` stops it growing or going stale. Smallest fix: the owner confirms the list, or those plans get a `**Shipped:**` line and the list is emptied.
- [minor] [ask 3] `testEveryFixtureFileIsDeclaredByACard` (C:\laragon\www\mmorpg\tests\unit\Defects\DefectCodeChecksTest.php:91) accepts a fixture declared by any card, but fixtures are only executed for the six hardcoded cards. A seventh card with fixtures would count as covered while nothing runs it. No such card exists today. Smallest fix: assert that the cards with non-empty `fixtures:` equal `CODE_CARDS` plus `entry-off-audience-path`. Test: add a card declaring a fixture outside those six; that assertion fails until the card is wired into a test.
```
