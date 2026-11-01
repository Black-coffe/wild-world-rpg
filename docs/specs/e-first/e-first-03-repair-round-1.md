---
story: e-first-03
status: done
returned: DONE
worker: worker-code
model: opus
wave: 3
blocked_by: []
red: required
red_reason: repair-1: мост не поднят
red_verified_at: 2026-11-01T14:36:02Z 13b110a1b75ef06ac1605ad2f0b7b78144a4f80da051c2982e454a75ec101b59
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

## Red receipt
- command: vendor/bin/phpunit --no-coverage --no-progress tests/unit/Services/Onboarding/FirstStepMenuServiceTest.php
- exit: 1
- tests: 13b110a1b75ef06ac1605ad2f0b7b78144a4f80da051c2982e454a75ec101b59 (tests/unit/Controllers/Telegram/StartCommandColdOpenParityTest.php, tests/unit/Services/Onboarding/ColdOpenWelcomeServiceTest.php, tests/unit/Services/Onboarding/FirstStepMenuServiceTest.php)
- impl: f5dbd116af79c6231a38b860d54f713a6ac1314014c7f146e803690941c00209
- stamped: 2026-11-01T14:36:02Z

```
    {\n
        return Request::sendMessage($payload);\n
    }\n
}\n
' matches PCRE pattern "/TelegramBridge::ensure\s*\(/".

C:\laragon\www\mmorpg\tests\unit\Services\Onboarding\FirstStepMenuServiceTest.php:158

[37;41mFAILURES![0m
[37;41mTests: 6[0m[37;41m, Assertions: 29[0m[37;41m, Failures: 1[0m[37;41m, PHPUnit Deprecations: 1[0m[37;41m.[0m
```

## Implementation notes
- Находка 1: `FirstStepMenuService::send()` поднимает `TelegramBridge::ensure()` до `Request::sendMessage`. Без моста — `ok=false` и лог error, маркер не ставится. `TelegramSenderBridgeCoverageTest` зелёный.
- Находка 2: inline-кнопки Роби и веба снова держат литерал `'🧭 Сделать первый шаг'` (как в базе). `FIRST_STEP_LABEL` остался источником для нижней кнопки и текстового роута; совпадение литерала с копией сторожит `ColdOpenSingleScreenTest`, он зелёный.
- Красные проверки repair-1 добавлены в `FirstStepMenuServiceTest` и `ColdOpenWelcomeServiceTest`.
- Minor-замечания ревью в этот repair не входят (scope — только находки), разобраны в plan / ответе владельцу: SQL порога учитывает обе формы первого шага (plan.md), `logging.action_origin.enabled` и `single_screen` сверить на проде перед включением флага, двойной тап может дать два сообщения с меню (неатомарная проверка — как у `NavMenuRefreshService`).

