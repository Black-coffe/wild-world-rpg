---
story: beacon-install-discoverability-03
spec: beacon-install-discoverability
status: done
tier: 2
worker: worker-code
tracer: false
wave: 1
blocked_by: []
---

# Убрать из обучающих текстов кнопку, которой больше нет

## Goal

JIT-хинт `beacon_crafted` и раздел `teleport` в `/guide` обещают: «(та же кнопка есть на экране
«🧑 Я»)». С включённым `navigation.final_grid.enabled` эта кнопка с карточки персонажа удалена —
обещание стало ложью и уводит игрока на экран, где двери нет. После story обещание убрано,
а путь назван так, как он работает на самом деле после story 01.

## Requirements

> (та же кнопка есть на экране «🧑 Я»). 3️⃣ Жми *«Установить маяк здесь»*.»

> — `OnboardingHintCatalog.php:296-309`

## Files
- app/Services/Onboarding/OnboardingHintCatalog.php
- app/Services/Onboarding/GuideCatalog.php
- tests/unit/Services/Onboarding/GuideCatalogTest.php
- tests/unit/Services/Onboarding/OnboardingHintServiceTest.php

## Non-goals
- Не переписывать хинт и раздел целиком: правится ровно оговорка про экран «🧑 Я»
  и, если нужно, полстроки про то, что дверь есть и вдали от базы.
- Не вносить чисел баланса (лимит маяков, цена прыжка, заряды) — в текстах они держатся
  качественно, и это намеренно (анти-дрейф).
- Не заводить новый раздел `/guide` и новый онбординг-шаг: вердикт спеки — правим существующие.
- Не трогать `CharacterService` и сетку ADR-150 — кнопка на «🧑 Я» НЕ возвращается,
  это отвергнутый владельцем вариант.
- Не трогать советы `game_tips` (117 и 125): они про экран «🧑 Я» не говорят и после story 01
  снова верны.
- Не трогать текст завершения крафта и экран выбора устройства: они называют путь
  «📡 Маяки» → «Установить маяк здесь» без ложной оговорки.

## Map slice
`memory/map/onboarding.md` (подсказки, `/guide`, «Совет дня»).

## Acceptance criteria
- [ ] Ни в хинте `beacon_crafted`, ни в разделе `teleport` не осталось утверждения, что кнопка
      маяков есть на экране «🧑 Я».
- [ ] Пошаговая инструкция («дойди до места → открой → установи») сохранена — это то, что
      после story 01 наконец выполнимо.
- [ ] Инварианты `/guide` держатся: read-only, самодостаточный текст (media-off),
      парные `*` в markdown, ключ раздела остаётся `teleport`.
- [ ] `GuideCatalogTest` зелёный.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Services/Onboarding/GuideCatalogTest.php tests/unit/Services/Onboarding/OnboardingHintServiceTest.php`

## Implementation notes
- `OnboardingHintCatalog.php`: убрана оговорка «(та же кнопка есть на экране «{$me}»)» из шага 2️⃣
  `BEACON_CRAFTED`; заодно убрана строка `$me = ...menuLabel('me')`, ставшая неиспользуемой
  (единственный вызов был в этой оговорке).
- `GuideCatalog.php`: та же оговорка убрана из раздела `teleport`; `$me` в этом файле используется
  ещё в нескольких других разделах, переменную не трогал.
- Решил не добавлять новую фразу про «дверь работает и вдали от базы» — story 01 идёт параллельно,
  и утверждать неподтверждённую механику запрещено брифом; текст просто перестал врать, шаги
  1️⃣-3️⃣ и путь `«{$base}» → «📡 Маяки»` сохранены как есть.
- Тестовые файлы из `## Files` (`GuideCatalogTest.php`, `OnboardingHintServiceTest.php`) не
  потребовали правок — оба уже прошли зелёными на изменённых текстах.

## Findings
