---
story: e-first-03
status: todo
returned:
worker: worker-code
model: opus
wave: 3
blocked_by: []
red: required
red_reason:
---

# Repair round 1

## Goal
Make the asks and findings council round 1 left RED pass, and change nothing else.

## Requirements
No brief ask is RED in round 1 - the findings below are the whole task.

## Findings
1. app/Services/Onboarding/FirstStepMenuService.php:43 [regression] full suite `vendor/bin/phpunit --no-coverage --no-progress` is red on TelegramSenderBridgeCoverageTest::testEverySenderRaisesBridgeOrIsExcepted: a service that sends to Telegram must raise TelegramBridge::ensure() before sending and handle false, or sit in that test's EXCEPTIONS with a verified reason - the file does not exist on base aa148fde, so the guard was green there - repro: `vendor/bin/phpunit --no-coverage --no-progress tests/unit/Config/TelegramSenderBridgeCoverageTest.php`
2. app/Services/Onboarding/ColdOpenWelcomeService.php:109 [regression] full suite is red on ColdOpenSingleScreenTest::testFirstStepButtonIsWiredAndLabelMatchesCopy: the anti-drift check that the first-step inline button carries the copy label and routes to the live `move` callback must still pass after the label moved into FIRST_STEP_LABEL (the test greps for the literal `'text' => '🧭 Сделать первый шаг', 'callback_data' => …tag('move'`, present on base at `git show aa148fde:app/Services/Onboarding/ColdOpenWelcomeService.php` line 94) - repro: `vendor/bin/phpunit --no-coverage --no-progress tests/unit/Services/Onboarding/ColdOpenSingleScreenTest.php`

## Files
- app/Services/Onboarding/ColdOpenWelcomeService.php
- app/Services/Onboarding/ColdOpenGreetingService.php
- app/Services/Telegram/BotMenuService.php
- app/Controllers/Telegram/Commands/StartCommand.php
- app/Controllers/Telegram/Commands/SystemCommands/GenericmessageCommand.php
- app/Database/Migrations/2026-12-22-100000_ColdOpenFirstStepKeyboardGameSettings.php
- tests/unit/Services/Onboarding/ColdOpenWelcomeServiceTest.php
- tests/unit/Controllers/Telegram/StartCommandColdOpenParityTest.php
- app/Services/Onboarding/FirstStepMenuService.php
- app/Controllers/Telegram/Commands/Actions/MoveCharacterAction.php
- tests/unit/Services/Onboarding/FirstStepMenuServiceTest.php

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Services/Onboarding/ColdOpenWelcomeServiceTest.php`
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Controllers/Telegram/StartCommandColdOpenParityTest.php`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
`git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Services/Onboarding/FirstStepMenuServiceTest.php`
