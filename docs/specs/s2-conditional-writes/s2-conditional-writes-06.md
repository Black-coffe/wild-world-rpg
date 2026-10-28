---
story: s2-conditional-writes-06
spec: s2-conditional-writes
status: todo
returned:
tier: 3
worker: worker-code
wave: 2
blocked_by: []
red: required
red_reason: "event delta written from a snapshot: second delta lost, or gold went negative"
---

# F16: `IntentApplier` — статы и золото через `adjust()`, ресурсы и метеор относительной записью

## Goal

`IntentApplier.php:52-76` пишет золото (`:63`), здоровье/усталость (пол 0.01) и атрибуты абсолютно со снимка
`$character`; ресурсы событий (`:143-151`) и потеря от метеора (`:166-178`) — тоже read-then-write. Два события от
одного снимка теряют дельту; минус золота больше баланса уводит в отрицательное. После story (решение владельца,
Answers 2): здоровье, усталость, атрибуты и золото — `CharacterStatsService::adjust($charId, $deltas, $floors)` с
полом 0.01 для первых трёх и дефолтным зажимом золота ≥ 0; ресурсы событий — `increment`/`insertUnique`; потеря
от метеора — относительное списание, зажатое на уровне SQL (`quantity - LEAST(quantity, ?)` или
`decrementIfAtLeast` по фактическому остатку в `WHERE`), не `снимок - n`. Только крон.

## Requirements

> 6. F16 — мировые события (`IntentApplier`) применяют дельты здоровья, усталости, атрибутов и золота через
> F16 дельты крона событий

## Files

- app/Services/Events/IntentApplier.php
- tests/database/IntentApplierRelativeDeltasTest.php

## Non-goals

- Не менять величины эффектов, тиры уровней, список получателей и политику уведомлений (`EffectResolver`,
  `EventRecipientFinder`, `NotificationPolicy`) — только форма записи.
- Не трогать `CharacterStatsService`: если `adjust()` не принимает нужный пол/колонку — `NEEDS_CONTEXT`, не правка сервиса.
- Продление задачи `:211-213` — вне ask; если это та же абсолютная запись со снимка, отметить в отчёте как хвост,
  не чинить молча.
- Отрицательное золото в БД у существующих персонажей не «чинить» миграцией — это не задача story.

## Map slice

`memory/map/quests-events-npc.md` → Entry points (События), Gotchas (one-shot enforced, дефолт ≠ прод);
`memory/map/player.md` → Gotchas («Запись статов идёт через `CharacterStatsService` с `FOR UPDATE`»). Контракт
`adjust()` и форма теста — `plan.md` → `## Contracts`.

## Acceptance criteria

- [ ] Ни одна запись `IntentApplier` не вычисляет новое абсолютное значение из `$character`/прочитанной строки:
      статы и золото — `adjust()` с дельтой, ресурсы — относительный инкремент/вставка, метеор — относительное
      списание, зажатое в SQL.
- [ ] Пол 0.01 для здоровья/усталости/атрибутов сохранён (тест на край: здоровье 0.5, дельта −1 → 0.01).
- [ ] Золото: баланс 10, дельта −30 → 0, не −20.
- [ ] Тест `IntentApplierRelativeDeltasTest`: один загруженный снимок персонажа, две дельты подряд (+5 золота,
      +5 золота; +1 ресурс, +1 ресурс) → в БД сумма обеих; минус золота больше баланса → 0; метеор на ресурс с
      остатком 1 при потере 3 → 0, не −2. Сообщение ассерта при провале:
      `event delta written from a snapshot: second delta lost, or gold went negative`. Крон-путь — без Telegram.
- [ ] Существующие тесты событий (symbol-map: `tests textual 3`) не правятся; если один из них красный после
      правки — это находка в отчёт.

## Verification

`vendor/bin/phpunit --no-coverage --no-progress tests/database/IntentApplierRelativeDeltasTest.php && vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes

## Findings
