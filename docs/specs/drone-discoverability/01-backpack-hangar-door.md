---
story: drone-discoverability-01
spec: drone-discoverability
status: done
tier: 1
worker: worker-code
tracer: false
wave: 1
blocked_by: []
---

# Рюкзак перестаёт быть тупиком: дверь «🚁 Ангар»

## Goal
На экране созданных предметов (`resourcesCrafting`) появляется безусловная кнопка
«🚁 Ангар» (`callback_data: hangar`), стоящая в одном ряду с существующей одиночной
«🔧 Ремонт инструментов». Игрок, который видит на полке «🛸 Дроны» свой
«📦 Дрон-разведчик», получает дверь к технике прямо с этого экрана.

## Requirements
> [19.08.2026 20:54] Анжела: «Как запустить дрон разведчик? Лежит в рюкзаке заряженный, кнопки нет»

> **Рюкзак — тупик.** `CraftedResourcesAction` показывает полку «🛸 Дроны» с
> «📦 Дрон-разведчик | N шт.» и ни одной кнопки к технике. Игрок видит предмет
> и не видит двери — ровно то, во что упёрлась Анжела.

## Files
- app/Controllers/Telegram/Commands/Actions/CraftedResourcesAction.php

## Non-goals
- НЕ делать кнопку условной («есть ли у игрока дрон/робот») — `HangarAction` сам
  рендерит lock-state, безусловная дверь и есть требование конституции.
- НЕ добавлять вторую дверь прямо на `droneScoutList` — вход в технику один: Ангар.
- НЕ трогать текст экрана, полки типов, сортировку и `line()`.
- НЕ рефакторить `MediaSender::editTextOrSend` и `navTarget()`.

## Map slice
memory/map/ — секция про Telegram action-handlers инвентаря/крафта (если есть).

## Acceptance criteria
- [ ] В `reply()` ряд с «🔧 Ремонт инструментов» содержит ДВЕ кнопки: ремонт и «🚁 Ангар».
- [ ] `callback_data` кнопки Ангара — ровно `hangar` (совпадает с `Config\CallbackRoutes:335`).
- [ ] Ни одна строка клавиатуры экрана не содержит ровно одну кнопку (правило «ноль одиночек в ряду»).
- [ ] Кнопка появляется при ЛЮБОМ режиме сортировки (`sort_type|name|qty|value`).

## Verification
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress app/Controllers/Telegram/Commands/Actions/CraftedResourcesAction.php`

## Implementation notes
- `reply()`: в ряд с «🔧 Ремонт инструментов» добавлена вторая кнопка `['text' => '🚁 Ангар', 'callback_data' => 'hangar']`, ряд рендерится безусловно при любом `$mode` сортировки. phpstan (L9, только этот файл) — чисто.
- Правка после ревью: подпись приведена к канону «🤖 Ангар» (совпадает с `BaseServiceMessageFormatter.php:213` и `OnboardingHintCatalog.php:232`), `callback_data` не менялся. phpstan снова чисто.
