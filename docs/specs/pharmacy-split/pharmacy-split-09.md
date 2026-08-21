---
story: pharmacy-split-09
spec: pharmacy-split
status: done
tier: 1
worker: worker-code
tracer: false
wave: 5
blocked_by: [pharmacy-split-04]
---

# Совет и справочник перестают обещать строку не на том месте

## Goal
Оба обучающих текста описывают экран таким, каким игрок его увидит.

## Requirements
> **Tips-вердикт:** ДА, категория `персонаж`.
> Совет с магическими числами баланса / смыслом «в картинке» — не делай

## Files
- app/Database/Migrations/2026-08-21-210000_SeedPharmacyShelfTip.php
- app/Services/Onboarding/GuideCatalog.php

## Что не так

Оба текста говорят, что снимаемая рана написана «прямо под названием». В
`ConsumableShelfService::itemLine()` строка «🩺 *Снимает:*» идёт **последней** в блоке
предмета — после доз, годности и «Баф». Мелочь, но это ровно тот сорт неточности, из-за
которого игрок ищет строку не там и решает, что её нет.

Замени привязку к месту на привязку к содержанию: у лекарства, которое снимает рану, эта
рана **названа в его строке в списке** — без обещания конкретной позиции. Сверься с
фактическим выводом `itemLine()`, прежде чем формулировать.

🔴 Миграция уже применена на testbot (`2026-08-21 22:38`). Менять тело `up()` мало —
существующая строка в `game_tips` не обновится, потому что вставка идемпотентна по `title_en`.
Добавь в `up()` ветку обновления: если строка с этим `title_en` уже есть — обнови ей `content`
(и `updated_at`), а не выходи молча. Так текст доедет и на testbot, и на прод.

## Non-goals
- Не заводить второй совет и не менять `title_en` (это сломает идемпотентность).
- Не менять категорию и не добавлять чисел баланса.
- Не трогать код экранов.

## Map slice
`app/Services/Player/ConsumableShelfService.php` — метод `itemLine()`, порядок строк.

## Acceptance criteria
- [ ] Ни один из двух текстов не утверждает, что строка идёт «под названием».
- [ ] Повторный прогон миграции на уже засеянной базе ОБНОВЛЯЕТ текст, а не пропускает его.
- [ ] Дублей строк в `game_tips` по этому `title_en` не появляется.
- [ ] `vendor/bin/phpunit --no-coverage --no-progress tests/unit/Services/Onboarding` зелёный.

## Verification
`php -l app/Database/Migrations/2026-08-21-210000_SeedPharmacyShelfTip.php > /dev/null && vendor/bin/phpunit --no-coverage --no-progress tests/unit/Services/Onboarding`

## Implementation notes

- `SeedPharmacyShelfTip::up()`: убрал ранний `return` при найденной строке — теперь при
  существующем `title_en='PharmacyShelfSplit'` делает `UPDATE content/updated_at`, при
  отсутствии — прежний `INSERT`. Текст в `content` и в docblock переписан: вместо «прямо под
  названием» — «в их строке в списке» (сверено с фактическим порядком строк в
  `ConsumableShelfService::itemLine()`, где `curedLine` идёт последней).
- `GuideCatalog.php` (раздел про раны/аптечку): та же правка формулировки — «в их строке в
  списке аптечки» вместо «прямо под названием».
- Отдельную миграцию не заводил (как и просила story) — ветка обновления живёт в уже
  существующем файле `2026-08-21-210000_SeedPharmacyShelfTip.php`.

## Findings
