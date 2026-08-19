---
story: drone-discoverability-06
spec: drone-discoverability
status: done
tier: 1
worker: worker-code
tracer: false
wave: 2
blocked_by: [drone-discoverability-01]
---

# Одна дверь — одна подпись: «🤖 Ангар»

## Goal
Кнопка, ведущая на callback `hangar`, называется одинаково во всех местах кода: «🤖 Ангар».
Расхождение с «🚁 Ангар» в списке крафт-страховки устранено.

## Requirements
> Одна и та же дверь `hangar` подписана в коде двумя разными эмодзи: «🤖 Ангар»
> на экране базы и в онбординг-подсказке против «🚁 Ангар» в списке крафт-страховки.
> Игрок читает это как две разные кнопки. Канон — «🤖 Ангар».

## Files
- app/Controllers/Telegram/Commands/Actions/Craft/Insurance/CraftInsuranceListAction.php

## Non-goals
- НЕ трогать `callback_data` — строка `hangar` остаётся ровно такой.
- НЕ трогать `BaseServiceMessageFormatter`, `OnboardingHintCatalog`, `GuideCatalog` —
  там уже канон «🤖 Ангар».
- НЕ переставлять кнопки и не менять состав рядов клавиатуры.
- НЕ переименовывать сам экран Ангара.

## Map slice
`app/Controllers/Telegram/Commands/Actions/Craft/Insurance/CraftInsuranceListAction.php:113`.

## Acceptance criteria
- [ ] На строке ~113 подпись стала «🤖 Ангар».
- [ ] `grep -rn "Ангар'" app --include=*.php` не находит ни одного `'🚁 Ангар'`
      среди кнопок с `callback_data => 'hangar'`.
- [ ] `callback_data` не изменился.

## Verification
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress app/Controllers/Telegram/Commands/Actions/Craft/Insurance/CraftInsuranceListAction.php`

## Implementation notes
Строка 113: `'🚁 Ангар'` → `'🤖 Ангар'`, `callback_data` не тронут; grep по всему `app/` подтвердил, что остальные три кнопки на `callback_data => 'hangar'` уже канон.
