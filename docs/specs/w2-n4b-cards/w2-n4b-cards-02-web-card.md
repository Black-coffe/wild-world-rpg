---
story: w2-n4b-cards-02
spec: w2-n4b-cards
status: todo
returned:
tier: 2
worker: worker-code
wave: 2
blocked_by: [w2-n4b-cards-01]
red: required
red_reason: строка постройки в обзоре /play ведёт в мост вместо нативной карточки, или кнопка действия здания пропала
---

# Нативная карточка постройки в /play

## Goal
`/play?view=base&section=card&key=<Key>` рисует карточку «моей» постройки из `BuildingCardService`: имя,
уровень, стопка, прочность (оборона), налог, срок, использования (только при лимите), доступность, описание,
эффект и заметки (грядки). «🆙 Поднять уровень» ведёт в нативный `section=upgrade`, «🏠 База» — на нативный
обзор, собственные действия здания — кнопками моста с той же `callback_data`. Строка постройки в обзоре
открывает карточку вместо «⚙️ Действия». Компонент сначала в `ui-kit.html`.

## Requirements
> 2. В /play строка постройки в обзоре базы открывает нативную карточку вместо «⚙️ Действия»: имя, уровень, стопка, налог, срок, использования, описание, эффект; «🆙 Поднять уровень» ведёт в нативный апгрейд.
> 3. Грядки, Роботы, Маяки, Ремонт, Развитие базы, Путь новичка — видимые кнопки моста, ни одна не пропадает.
> 5. Экран сначала в ui-kit.html; 375/768/1440 без горизонтального скролла; читается без картинок.

## Files
- app/Services/Web/WebNativeScreenService.php
- app/Services/Bases/BaseScreenService.php
- app/Views/site/_play/native_base.php
- public/assets/css/wildworld-ui.css
- public/ui-kit.html
- tests/unit/Views/PlayViewsTest.php
- tests/database/PlayViewControllerTest.php

## Non-goals
- Не делать нативными Грядки, Роботов, Маяки, Ремонт, Развитие базы — только мост (W2.Xb).
- Не менять текст бота и `BuildingCardService` сверх недостающего поля; правка ядра — Findings.
- Без фото в веб-карточке; без новых цветов/радиусов/теней вне токенов `wildworld-ui.css`.

## Map slice
`memory/map/website.md` (`/play`, native-экраны), `memory/map/bases.md`.

## Acceptance criteria
- [ ] `BASE_SECTIONS` содержит `card`; плохой `key` (не `[A-Za-z]{1,40}`) или чужая/не та база — отказ ядра текстом, без 500.
- [ ] Обзор: у строки постройки с карточкой — переход в `section=card`; у неизвестной ядру — мост, как сейчас.
- [ ] Карточка теплицы показывает «🌱 Грядки» мостом (при включённом фарме), роботов — «🤖 Роботы», центра — «📡 Маяки», повреждённой стены — «🔧 Ремонт», Навеса — «🏗 Развитие базы» и «📖 Путь новичка» и не показывает апгрейд.
- [ ] Подписи веба: без «lvl», без «$», без строки использований при отсутствии лимита; Markdown-звёздочек и `_` на экране нет.
- [ ] 375/768/1440 без горизонтального скролла (длинное описание переносится).

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Views/PlayViewsTest.php` && `vendor/bin/phpunit --no-coverage --no-progress tests/database/PlayViewControllerTest.php` && `vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes

## Findings
