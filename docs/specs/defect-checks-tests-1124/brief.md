# Defect checks as PHPUnit tests (brief)
**Date:** 2026-11-24 · **Owner:** Andrei

## Request (verbatim)
> Что осталось от старого улья:
> 1. Рекомендую: перенести 6 скриптов scripts/defects-*-check.php в PHPUnit-тесты. Тонкий VULYK ждёт проверки в тестах проекта, тогда они снова будут ловить дефекты сами. Это работа через /vulyk:go.
> 2. 38 старых веток vulyk/*, docs/vulyk/ADAPTATION.md и локальный хук .git/hooks/post-merge отмечает карту устаревшей и больше никому не нужен. Удалю, если скажете. ДЕЛАЙ

## Answers
- 2026-11-24 · merge question (review round 1 PASS, minor F1 legacy list, F2 seventh card) — «Влить (Рекомендую)»

## Asks
1. Each of the 6 checks `scripts/defects-*-check.php` runs inside the project's PHPUnit suite, in one of the 10 suite parts listed in `CLAUDE.md` `## Commands`, with no manual step.
2. A test fails when the current code (or the current unshipped plans, for the audience-surfaces check) has the defect the check catches; it passes on today's `develop`.
3. Every fixture in `docs/defects/fixtures/` is proven to still make its check fail (the gate is not blind), as the old `defects-check.sh` audit did.
4. The checks keep working as manual commands; the rules in `.claude/rules/` that name them stay true.
5. The suite part that hosts the tests stays within about 300 s, and the tests need no network and no preprod/prod database.
6. Item 2 of the request (old branches, ADAPTATION.md, post-merge hook) was done before this spec, commit `617fed80`; it is not part of this work.
