---
story: web-onboarding-01
spec: web-onboarding
status: todo
returned:
tier: 3
worker: worker-code
model: sonnet
wave: 1
blocked_by: []
---

# Ядро первого экрана новичка; бот рисует его из ядра без единого изменения

## Goal
Появляется `App\Services\Onboarding\ColdOpenWelcomeService::build($client, $texts)` (контракт — `plan.md` →
`## Contracts`). Он собирает упорядоченный список сообщений первого экрана новичка из `lastTexts()` и построителей
`ColdOpenGreetingService`. Легаси-текст на 121 слово (`StartCommand.php:134-144`) и инлайн-клавиатуры («🧭 Сделать
первый шаг» = `move~cold`, «✏️ Назвать героя» = `setCharacterName~cold`, «🤔 Задать имя персонажа») переезжают в ядро.
Ветка нового персонажа `StartCommand` (`:88-263`) становится тонким рендерером: идёт по модели и шлёт те же
сообщения теми же вызовами в том же порядке; носитель reply-меню (`role=menu_carrier`) и само меню остаются за
ботом. Веб-вариант (`CLIENT_WEB`) — одно окно, только «🧭 Сделать первый шаг» (`callback_data='move'`), без носителя
меню и без бот-лексики.

## Requirements
> одной спеки «веб-онбординг» в общем ядре: приветствие, итог задачи на экране, шаги обучения не только во входящих, переход сразу в игру.
> 1. Веб-новичок при первом входе видит приветствие Роби — тот же первый экран, что бот даёт в ленте (паёк, сигнал, встречающий, «🧭 Сделать первый шаг»), собранный в общем ядре, и бот рисует его из того же ядра.
> 5. Бот после переноса первого экрана в ядро выглядит для новичка так же, как сейчас: тот же текст, те же кнопки и порядок сообщений при каждом сочетании флагов `onboarding.cold_open_v2.*` (проверка — тест на рендер бота до/после).
> 6. Экран читается в media-off: приветствие, итог задачи и плашка нового сообщения несут весь смысл текстом; в веб-текстах нового первого экрана нет бот-лексики («ниже», «нижнее меню», «команда /…»).

## Files
- app/Services/Onboarding/ColdOpenWelcomeService.php
- app/Services/Onboarding/ColdOpenGreetingService.php
- app/Controllers/Telegram/Commands/StartCommand.php
- tests/unit/Services/Onboarding/ColdOpenWelcomeServiceTest.php
- tests/unit/Controllers/Telegram/StartCommandColdOpenParityTest.php
- tests/unit/Controllers/Telegram/StartCommandColdOpenSnapshots.php
- tests/unit/Services/Onboarding/ColdOpenSingleScreenTest.php

## Non-goals
- Не трогать ветку существующего игрока (`StartCommand.php:265+`, «🧭 Меню ниже.»): это не первый экран новичка.
- Не менять ни одного символа текста бота и не «чинить» бот-лексику в бот-варианте: бот должен совпасть байт в байт.
- Не трогать `CharacterProvisioningService` и сервисы пайка, сигнала и встречающего: ядро только собирает их текст.
- Не трогать веб (`AccountRegister`, `Services/Web/*`): это story 04.
- Не заводить новый флаг и не менять смысл существующих `onboarding.cold_open_v2.*`.

## Map slice
`memory/map/onboarding.md` → Entry points, Gotchas (подписи кнопок из `BotMenuService`, killswitch'и);
`memory/map/telegram.md` → Key types / contracts, Gotchas (legacy Markdown, ряды кнопок, caption);
`docs/specs/web-onboarding/recon.md` → «Cold open бота и цепочка».

## Acceptance criteria
- [ ] **Сначала снимок, потом перенос.** До правки `StartCommand` на текущем коде снят golden-снимок всего, что ветка
      новичка отправляет: метод, `chat_id`, `text`, `parse_mode`, `reply_markup` (JSON), порядок. Снимок снят для
      каждого сочетания флагов `onboarding.cold_open_v2.*` (полный список — из кода) × {спавн есть, спавна нет} ×
      {kit/signal/greeter есть, часть null} и закоммичен фикстурой `tests/unit/Controllers/Telegram/StartCommandColdOpenSnapshots.php` (PHP-массив снимков).
- [ ] `StartCommandColdOpenParityTest` гоняет новый `StartCommand` по тем же сочетаниям через транспорт-двойник и
      сравнивает с фикстурами побайтно. Фикстуры после переноса не перегенерируются. Двойник записывает реальный
      исходящий payload, а не вызов-заглушку.
- [ ] В `StartCommand` не осталось ни литерала легаси-текста, ни инлайн-клавиатуры cold open: всё приходит из `build()`.
- [ ] `ColdOpenWelcomeServiceTest`: веб-модель содержит тексты пайка, сигнала и встречающего (из `$texts`) и
      приветствие с закрытием, в порядке бота. Ровно одна кнопка `🧭 Сделать первый шаг` / `move`. Ни `menu_carrier`,
      ни «Назвать героя», ни «Задать имя». При `spawned=false` — `role=error` с текстом ошибки бота.
- [ ] Тест бот-лексики: ни один `text` веб-модели не матчит `/ниже|нижн\w* меню|команд\w* \/|\/start|\/menu|клавиатур/iu`.
      Для этого у веба своё закрытие в `ColdOpenGreetingService` (например, `closingWeb()`), бот-закрытие не меняется.
- [ ] Веб-модель читается в media-off: весь смысл — в `text`, фото нет. Legacy Markdown веб-текстов без
      незакрытых `*`/`_`.
- [ ] Если паритет-тест не идёт без БД, он переезжает в `tests/database/`, а это уходит в INTERFACES (plan delta на
      Verification). Тест не ослабляется.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes

## Findings
Вопрос (NEEDS_CONTEXT, код не тронут): `tests/unit/Services/Onboarding/ColdOpenSingleScreenTest.php` читает исходник
`StartCommand.php` (`START_COMMAND`) и требует в нём метку «🧭 Сделать первый шаг», `callback_data` `move`/`tag('move'`,
`tag('setCharacterName', ...FROM_COLDOPEN)` и `$starterKitText|$signalText|$greeterText = null;`
(тесты `testFirstStepButtonIsWiredAndLabelMatchesCopy`, `testBothFirstScreenDoorsCarryOriginTag`,
`testSectionsAreClearedAfterInlining`). После переноса в `ColdOpenWelcomeService` (AC: в StartCommand нет литералов
клавиатуры) эти три теста станут красными, а файл не в `## Files`. Нужно добавить его в Files: разрешите перенацелить
`START_COMMAND` на `ColdOpenWelcomeService.php` и убрать проверку обнуления переменных.

Ответ Queen (2026-10-12, plan delta): да — `tests/unit/Services/Onboarding/ColdOpenSingleScreenTest.php` добавлен в `## Files`.
Перенацель source-scan `START_COMMAND` на `ColdOpenWelcomeService.php` (метка «🧭 Сделать первый шаг», `move`/`tag('move'`,
`tag('setCharacterName', …FROM_COLDOPEN)`), проверку обнуления `$starterKitText|$signalText|$greeterText` убери:
её заменяет golden-тест паритета. Смысл тестов (кнопки подписаны и несут метку cold) сохраняется.
