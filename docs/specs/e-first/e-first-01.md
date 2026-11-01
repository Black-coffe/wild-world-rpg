---
story: e-first-01
spec: e-first
status: todo
returned:
tier: 2
worker: worker-code
wave: 1
blocked_by: []
red: required
red_reason:
---

# Одна нижняя кнопка «🧭 Сделать первый шаг» на первом экране

## Goal
При включённом флаге `onboarding.cold_open_v2.first_step_keyboard` новичок бота на `/start` получает на сообщении-носителе
reply-клавиатуру из одной кнопки «🧭 Сделать первый шаг» вместо полного меню. Тап этой кнопки (текст) открывает тот же
первый шаг, что inline-кнопка Роби `move~cold`, и пишется в firehose с `origin = 'cold'`. Решение «какая клавиатура у
носителя» принимает ядро (`ColdOpenWelcomeService`), рендерер `StartCommand` его только исполняет. При OFF — байт-в-байт как сейчас.

## Requirements
> 1. Новичок бота на `/start` получает внизу экрана reply-клавиатуру из одной кнопки «🧭 Сделать первый шаг» вместо полного меню; экран Роби и его inline-кнопки («🧭 Сделать первый шаг», «✏️ Назвать героя») не меняются.
> 2. Тап нижней кнопки «🧭 Сделать первый шаг» ведёт туда же, куда inline-кнопка Роби (первый шаг, компас), и пишется в firehose с `origin = 'cold'`.
> 5. Всё под флагом в `GameSettings` (`onboarding.cold_open_v2.first_step_keyboard`, с rationale / effect / above / below и Reset-to-default); при OFF первый экран и меню байт-в-байт как сейчас.
> 6. Веб (`/play`, `CLIENT_WEB`) не меняется — reply-клавиатуры там нет; паритет веб/бот: «только бот, потому что нижнее меню есть только в Telegram».

## Files
- app/Services/Onboarding/ColdOpenWelcomeService.php
- app/Services/Onboarding/ColdOpenGreetingService.php
- app/Services/Telegram/BotMenuService.php
- app/Controllers/Telegram/Commands/StartCommand.php
- app/Controllers/Telegram/Commands/SystemCommands/GenericmessageCommand.php
- app/Database/Migrations/2026-12-22-100000_ColdOpenFirstStepKeyboardGameSettings.php
- tests/unit/Services/Onboarding/ColdOpenWelcomeServiceTest.php
- tests/unit/Controllers/Telegram/StartCommandColdOpenParityTest.php

## Non-goals
- Не трогать `buildWeb()` и `/play`.
- Не менять тексты и inline-кнопки экрана Роби, не менять ветку существующего персонажа в `StartCommand`.
- Не слать полное меню после шага — это `e-first-02`.
- Не переписывать `menuAttachText()` под OFF; при ON текст носителя — новая короткая строка без «ниже»/«внизу» и без бот-лексики (`scripts/defects-bot-lexicon-check.php`).

## Map slice
`memory/map/onboarding.md` — Entry points (ColdOpenWelcomeService), Gotchas (первый экран, бот-лексика, reply-меню);
`memory/map/telegram.md` — reply-клавиатура и текстовый роутинг.

## Acceptance criteria
- [ ] Флаг ON: модель `build(CLIENT_BOT, …)` отдаёт у `menu_carrier` клавиатуру из одной кнопки (подпись — одна константа); `StartCommand::renderNewcomer` шлёт именно её. Флаг OFF: носитель несёт `BotMenuService::mainReplyKeyboard()`, снапшоты `StartCommandColdOpenSnapshots` не меняются.
- [ ] Текст «🧭 Сделать первый шаг» (и в нижнем регистре, как приходит в `GenericmessageCommand`) открывает тот же экран, что callback `move`, с `ActionOrigin` = `cold` до логирования firehose; при флаге OFF текст не роутится по-новому (обычный fallback не меняется).
- [ ] Миграция сидит `onboarding.cold_open_v2.first_step_keyboard` (bool, value 0, default 0, rationale/effect/above/below, категория онбординга как у соседей) идемпотентно; `php -l` чист.
- [ ] Тест падает, если при ON носитель снова несёт полное меню (red).

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Services/Onboarding/ColdOpenWelcomeServiceTest.php`
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Controllers/Telegram/StartCommandColdOpenParityTest.php`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
`git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`

## Implementation notes

## Findings
