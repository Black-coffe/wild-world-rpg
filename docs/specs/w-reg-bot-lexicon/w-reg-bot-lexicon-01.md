---
story: w-reg-bot-lexicon-01
spec: w-reg-bot-lexicon
status: todo
returned:
tier: 2
worker: worker-code
wave: 1
blocked_by: []
---

# «Лагерь разбит» — не тупик

## Goal
Оба сообщения о разбитом лагере (`sendCampCreatedMsg`, `sendCampCreatedWithBuildingsMsg`) несут inline-ряд
«🏠 База» (`Base`) · «🏗 Строить» (`Build` с суффиксом новой базы через `BaseCallbackSuffix::append`) и строку
«что дальше» (строить Склад, Теплицу). Разметка собирается одним методом, который тестируется без Telegram.

## Requirements
> 4. «Лагерь разбит» (с переездом и без) несёт кнопки «🏠 База» и «🏗 Строить» в одном ряду и строку «что дальше»; без картинки текст полон.
> 5. (ворота проекта) MEDIA-OFF: экран «Лагерь разбит с переездом» без картинки несёт координаты, список перенесённых зданий и строку «что дальше» в caption ≤1024; фото — только через `MediaSender`.

## Files
- app/Controllers/Telegram/Commands/Actions/Camp/CampCreateConfirmAction.php
- tests/unit/Camp/CampCreatedMessageTest.php

## Non-goals
- Не показывать экран базы вместо сообщения (выбор владельца — кнопки).
- Не трогать остальной поток подтверждения лагеря и его проверки.

## Map slice
`memory/map/bases.md` — экран базы, колбэки `Base`/`Build`, `BaseCallbackSuffix`.

## Acceptance criteria
- [ ] Оба варианта сообщения имеют `reply_markup` с одним рядом из двух кнопок: `Base` и `Build` (+суффикс id новой базы).
- [ ] Текст обоих вариантов содержит строку «что дальше» с «🏗 Строить»; caption варианта с переездом при 14 зданиях ≤1024.
- [ ] Тест падает, если убрать кнопку или строку «что дальше».

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Camp/CampCreatedMessageTest.php`

## Implementation notes

## Findings
