---
story: web-onboarding-05
status: done
returned: DONE
worker: worker-code
model: opus
wave: 3
blocked_by: []
---

# Repair round 1

## Goal
Make the asks and findings council round 1 left RED pass, and change nothing else.

## Requirements
> 6. Экран читается в media-off: приветствие, итог задачи и плашка нового сообщения несут весь смысл текстом; в веб-текстах нового первого экрана нет бот-лексики («ниже», «нижнее меню», «команда /…»).

## Findings
ASK 6: RED - no bot lexicon on the web first screen - run: WebLexProbeTest (web build fed with the real ColdOpenSignalService/NewbieGreeterService narratives, withNavHint = !singleScreen exactly as CharacterProvisioningService passes it) saw: single_screen=1 -> 0 hits; single_screen=0 (the migration default) -> 2 hits "нижнем меню": signal and greeter come with "_Жми «🌍 …» в нижнем меню …_" inside the web greeting. The web path always uses one window, but provisioning still adds the nav tail based on the bot's flag. Tests only seed single_screen=1, so they miss this case
1. app/Services/Web/WebActService.php:140-178 [regression] a screen tagged with one step of a chained task (Поход: `march_go_*` via the bridge inserts a Marching `in_work` row that `startedTaskId()` at :279 tags; `MarchingTaskHandler.php:157-158` completes that step and spawns the next `in_work` step every tick while it edits the same message) must not be replaced by the neutral "✅ Задача «…» завершена — итог во входящих 🔔" or by an unrelated inbox message while the chain is still running - today, 120 s after the first step completes, the live march screen with «❌ Остановиться» goes to history and the player sees "завершена" mid-march with nothing in the inbox; on the base `current()` only returned the state (`git show df8920f9:app/Services/Web/WebActService.php` lines 119-122). Repro: in WebTaskResultScreenTest's setup, tag the screen with task A, set A `completed` with `updated_at` 3 min ago, insert task B `in_work` for the same character, call `current()` -> the screen becomes TASK_DONE_TEXT.

## Files
- app/Services/Onboarding/ColdOpenWelcomeService.php
- app/Services/Onboarding/ColdOpenGreetingService.php
- app/Controllers/Telegram/Commands/StartCommand.php
- tests/unit/Services/Onboarding/ColdOpenWelcomeServiceTest.php
- tests/unit/Controllers/Telegram/StartCommandColdOpenParityTest.php
- tests/unit/Controllers/Telegram/StartCommandColdOpenSnapshots.php
- tests/unit/Services/Onboarding/ColdOpenSingleScreenTest.php
- app/Services/Web/WebScreenStore.php
- app/Services/Web/WebActService.php
- app/Config/WebPlay.php
- app/Services/Web/WebTaskResultPicker.php
- tests/unit/Services/Web/WebTaskResultPickerTest.php
- tests/database/WebTaskResultScreenTest.php
- scripts/defects-task-result-foreign-check.php
- docs/defects/task-result-foreign-message.md
- docs/defects/fixtures/task-result-foreign-message-original.json
- docs/defects/fixtures/task-result-foreign-message-neighbour.json
- app/Services/Web/WebInboxService.php
- app/Controllers/Play.php
- app/Views/site/_play/banner.php
- app/Views/site/play.php
- public/assets/js/wildworld-play.js
- public/assets/css/wildworld-ui.css
- public/ui-kit.html
- app/Views/site/_layout/meta.php
- tests/database/PlayInboxBannerTest.php
- app/Controllers/AccountRegister.php
- tests/database/WebFirstScreenTest.php

## Implementation notes
- app/Services/Onboarding/ColdOpenWelcomeService.php: buildWeb strips the bot nav tail «_Жми «…» в нижнем меню …_» from signal/greeter (provisioning adds it by the bot's single_screen flag; CharacterProvisioningService is outside Files, so stripped at the web renderer of the core).
- app/Services/Web/WebActService.php: settleTaskScreen judges a chained task by its newest later step of the same task type (character_tasks.task_id); while that step is in_work/queued/paused the screen stays.
- tests: ColdOpenWelcomeServiceTest gets the single_screen=0 nav-tail case (both fit and oversized); WebTaskResultScreenTest gets the finding's repro (A completed 3 min ago + B in_work -> screen kept; B completed + result -> result replaces).
- Decision: chain = same task type with a higher id; rejected "any later active task" (an unrelated task would freeze the screen) and retagging the screen (extra store API).

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
