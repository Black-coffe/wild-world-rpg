---
story: w-reg-text-fixes-01
spec: w-reg-text-fixes
status: done
returned: DONE
tier: 1
worker: worker-code
wave: 1
blocked_by: []
---

# Путь к титулам, звёздочки на экране действий, подсказка имени

## Goal
Сообщения о новом титуле ведут «карточка Персонажа → «📊 Прогресс» → «🎖 Титулы»» (метка хаба —
`ProfileHubService::HUB_PROGRESS_LABEL`); экран «Действия» не содержит `**`; веб-форма имени показывает
под полем нейтральное правило (`NameService::ruleHintPlain()`), а ошибка после неверного ввода — прежнее
`ruleMessagePlain()`.

## Files
- app/Services/Player/ReferralService.php
- app/TaskHandlers/Titles/TitleCheckCron.php
- app/Controllers/Telegram/Commands/Actions/CharacterGoActions.php
- app/Services/Player/NameService.php
- app/Controllers/AccountRegister.php
- tests/unit/Services/Player/ReferralServiceTest.php
- tests/database/AccountRegistrationTest.php
- tests/unit/Services/Player/NameServiceTest.php

## Non-goals
- Не переписывать тексты экрана «Действия» и не трогать его кнопки.
- Не менять правило имени (`NAME_PATTERN`) и бот-сообщение `RULE_MESSAGE`.
- Не трогать вёрстку `account_character.php` — подсказку подаёт контроллер.

## Map slice
memory/map/onboarding.md, memory/map/website.md — разделы про создание персонажа.

## Acceptance criteria
- [ ] `ReferralService::notifyText()` и уведомление `TitleCheckCron` содержат `HUB_PROGRESS_LABEL` и «🎖 Титулы», не содержат «в карточке Персонажа._» без хаба; парные `*`/`_`.
- [ ] В тексте `CharacterGoActions` нет `**`.
- [ ] `ruleHintPlain()` = правило без «Имя не соответствует правилам»; страница `account/character` до ввода показывает его, после `bad name!` — `ruleMessagePlain()`.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Services/Player/ReferralServiceTest.php`
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Services/Player/NameServiceTest.php`

## Implementation notes
- F9: `ReferralService::notifyText()` и `TitleCheckCron` — «карточка Персонажа → «` . HUB_PROGRESS_LABEL . `» → «🎖 Титулы»»; путь сверен с `CharacterSheetService:331` (хаб на карточке) и `ProfileHubService::progressButtons` («🎖 Титулы» внутри). «🔗 Мой профиль» из отчёта — url-кнопка на веб-профиль, не вход в титулы.
- F10: пять строк `**…**` → `*…*`, тексты дословно прежние.
- F15: `NameService::ruleHintPlain()` — `ruleMessagePlain()` после «: »; `AccountRegister` подаёт его в `ruleText`; ошибка после ввода не тронута.
- Тесты: unit `NameServiceTest::testRuleHintIsTheRuleWithoutTheRefusal`, `ReferralServiceTest` (путь через хаб), database `AccountRegistrationTest` (подсказка на пустой форме) и `TitleServiceTest` — зелёные; phpstan по тронутым файлам — 0 ошибок. Tech-writing — vault `tech-writing: w-reg-text-fixes`.

## Findings
