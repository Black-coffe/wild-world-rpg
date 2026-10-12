---
story: oct-pvp-clarity-04
status: todo
returned:
worker: worker-code
model: opus
wave: 3
blocked_by: []
---

# Repair round 1

## Goal
Make the asks and findings council round 1 left RED pass, and change nothing else.

## Requirements
No brief ask is RED in round 1 - the findings below are the whole task.

## Findings
1. docs/defects/entry-off-audience-path.md:19 [regression] the defect class `entry-off-audience-path`, which this diff gives its second owner quote and whose paths it touches (`app/Controllers/Telegram/Commands/Actions/**`, `app/Views/site/_play/**`, the spec's plan/brief), must leave `scripts/defects-check.sh` green: it needs a failing `check:` with the original-case and neighbour-form fixtures, or the owner's recorded decision on the debt - repro: `bash scripts/defects-check.sh` exits 1 with `DEBT  entry-off-audience-path: 2 quotes, not block, new quote ed9bae6`; base: `git show 0a7b4eca:docs/defects/entry-off-audience-path.md` holds 1 quote, and debt needs at least 2

## Files
- app/Services/PVE/PvpRoundOrchestrator.php
- app/Services/PVE/PvpBattleLogBuilder.php
- app/Services/PVE/PvpOutcomeExplainer.php
- app/Services/PVE/BattleJournalService.php
- tests/database/PvpBattleLogBuilderTest.php
- tests/database/BattleJournalServiceTest.php
- tests/unit/Services/PVE/PvpOutcomeExplainerTest.php
- app/Controllers/Telegram/Commands/Actions/PVP/BattleJournalAction.php
- app/Views/site/_play/native_battle.php
- tests/unit/Views/PlayViewsTest.php
- app/Controllers/BattlesController.php
- app/Views/site/battles/view.php
- tests/database/BattlesPublicViewTest.php
- app/Services/Onboarding/GuideCatalog.php
- app/Database/Migrations/2026-12-20-100000_SeedPvpBaseOutcomeTip.php
- tests/unit/Services/Onboarding/GuideCatalogTest.php

## Verification
`vendor/bin/phpunit --no-coverage --no-progress && vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
`git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`
