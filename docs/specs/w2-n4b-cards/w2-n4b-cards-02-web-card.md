---
story: w2-n4b-cards-02
spec: w2-n4b-cards
status: done
returned: DONE
tier: 2
worker: worker-code
wave: 2
blocked_by: [w2-n4b-cards-01]
red: required
red_reason: строка постройки ведёт в нативную карточку
red_verified_at: 2026-11-02T09:14:19Z 3c7d21e3cd6bd85e04e5e63e0b4977e651e8b3b99d3ed46a08491bbb6fa3b9ac
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
- tests/unit/Camp/BuildingCardParityTest.php
- tests/unit/Camp/BuildingCardSnapshots.json

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
- `section=card`: `WebNativeScreenService` зовёт `BuildingCardService::card()` с клеткой персонажа и уже перепроверенным `b` — ядро ещё раз проверяет базу и переезд; отказ рисуется текстом без Markdown.
- Строка обзора: `BaseScreenService::overview` отдаёт `has_card`; есть карточка — «📋 Подробнее» (нативно), нет — прежний мост «⚙️ Действия» (не тупик).
- Карточка собрана из готовых классов (`play-craft-card`, `play-craft-facts`, `play-base-effect`, `play-kb-grid`) — CSS не менялся, `?v=` не нужен; пример — `ui-kit.html#play-base-building-card-demo`.
- По записке бокового агента: снимки story 01 видели только дефолты GameSettings. Добавлен прогон с флагами (стопка обороны вкл., грядки выкл.), 16 снимков сняты со старых классов, временно восстановленных из `e8a70c10`; новый код совпал.

## Findings

## Red receipt
- command: vendor/bin/phpunit --no-coverage --no-progress tests/unit/Views/PlayViewsTest.php && vendor/bin/phpunit --no-coverage --no-progress tests/database/PlayViewControllerTest.php && vendor/bin/phpstan analyse --memory-limit=512M --no-progress
- exit: 1
- tests: 3c7d21e3cd6bd85e04e5e63e0b4977e651e8b3b99d3ed46a08491bbb6fa3b9ac (tests/database/PlayViewControllerTest.php, tests/unit/Views/PlayViewsTest.php)
- impl: bd33c77a71cd0754e519bb64529f652cf04de6b4ef7298c42881f63a7ea15ac1
- stamped: 2026-11-02T09:14:19Z

```
    </div>\n
</div>\n\r
\n
<!-- DEBUG-VIEW ENDED 102 APPPATH\Views\site\_play\native_base.php -->\r\n
' [UTF-8](length: 2962) contains "data-building-card="Greenhouse"" [ASCII](length: 31).

C:\laragon\www\mmorpg\tests\unit\Views\PlayViewsTest.php:586

[37;41mFAILURES![0m
[37;41mTests: 33[0m[37;41m, Assertions: 266[0m[37;41m, Failures: 1[0m[37;41m, PHPUnit Deprecations: 1[0m[37;41m.[0m
```
