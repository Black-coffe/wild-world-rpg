---
story: s2-conditional-writes-06
spec: s2-conditional-writes
status: done
returned: DONE
tier: 3
worker: worker-code
wave: 2
blocked_by: []
red: required
red_reason: event delta written from a snapshot: second delta lost, or gold went negative
red_verified_at: 2026-10-28T20:59:33Z d21532fdd40eb07d07490c2d9276ec4fb2f7983b8b69cf8080437721267311c3
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
- tests/unit/Services/Events/IntentApplierTest.php

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

- `app/Services/Events/IntentApplier.php`: здоровье/усталость/атрибуты/золото → `CharacterStatsService::adjust()` (пол `min 0.01` для health/tired/атрибутов, золото — дефолтный зажим ≥ 0); сервис — необязательный 6-й аргумент конструктора (`= new CharacterStatsService()`), вызывающие не тронуты.
- Ресурсы событий: строка только находится (`first()` — какая из возможных дублей), запись — `ConditionalWriteService::increment(['id'=>…])`; строки нет — `insertUnique` с `created_at/updated_at`; `Refused` (вставил сосед) — `increment` по паре персонаж/ресурс. `amount <= 0` пропускается (примитив его отвергает).
- Метеор: величина потери прежняя (`ceil(%)`, не ниже остатка 1 от прочитанного), запись — `quantity = quantity - LEAST(quantity, ?)` по id: устаревшее чтение даёт 0, не минус.
- Решение: health/tired теперь ещё и с потолком 100 (дефолт `adjust()`); раньше `IntentApplier` потолка не ставил. `AttributeBoostEffect` и так режет до `cap_h_t_g` = 100 — расхождение только при ручном `cap_h_t_g` > 100.
- Тест `tests/database/IntentApplierRelativeDeltasTest.php`: устаревшее чтение метеора — `DBQuery`-событие после SELECT (приём `PlantCropSeedConditionalWriteTest`); недостающие колонки общих таблиц добавляются `ALTER … ADD COLUMN` (сосед `VehicleActivationNonTransportTest` оставляет `characters` из id + active_vehicle_log_id).
- Сюрприз: `red_reason` в кавычках не находился `stamp-red` — кавычки убраны (как в -02/-03/-04).
- Хвост (Non-goals, не чинил): `applyTaskExtension()` `:211-213` — та же абсолютная запись со снимка (`end_time` прочитан → `+N minutes` → записан целиком); относительная форма — `end_time = end_time + INTERVAL ? MINUTE`.

- Королева 28.10 (plan delta): baseline снят `aebbd7a3`. `## Files` расширен `tests/unit/Services/Events/IntentApplierTest.php` — он закрепляет старую запись со снимка (подмена `CharacterModel::update()`); переписать его под `adjust()`/относительную запись, сохранив смысл четырёх проверок (пол 0.01 здоровья, накопление золота, атрибуты, ресурсы), без ослабления. Прогнать его отдельно (`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Services/Events/IntentApplierTest.php`) до close-story; в `## Verification` он не добавлен — набор red-квитанции не меняется.
- `tests/unit/Services/Events/IntentApplierTest.php` переписан: двойник `CharacterStatsService` (6-й аргумент) подменяет только `mutate()`, настоящая математика `adjust()`/`clamp()` идёт над «свежими» значениями; `CharacterModel::update()` бросает исключение, если applier пишет статы по-старому. Четыре проверки сохранены; золото теперь проверяется от свежего значения (снимок 500, в БД 700, +1500 → 2200). 4/4 зелёные.
- Baseline снят Королевой в `aebbd7a3`, так что пункты из Findings про phpstan закрыты.

## Findings

- WALL по правилу плана (Assumptions, `phpstan-baseline.neon` не правит воркер): после правки phpstan красный только на baseline-записях `app/Services/Events/IntentApplier.php`, новых ошибок нет. Нужно Королеве в `chore(baseline)`:
  - удалить (`ignore.unmatched`): «Cannot access offset 'id' on non-empty-array…|object» (count 1); «Cannot access offset 'quantity' on array…|object» (count 1); «Cannot access offset 'quantity' on non-empty-array…|object» (count 1); «Parameter #1 $row of method CodeIgniter\Model::insert() expects …, array<string, mixed> given» (count 1); «Possibly invalid array key type mixed» (count 3).
  - уменьшить count (`ignore.count`): «Cannot cast mixed to int» 3 → 1; «Argument of an invalid type mixed supplied for foreach» 2 → 1; «Cannot cast mixed to float» 7 → 1; «Cannot access offset 'id' on array…|object» 2 → 1.
  После этого `close-story` перезапустить (тест `IntentApplierRelativeDeltasTest` зелёный: 5/5).
- Существующий тест красный (не правился, AC): `tests/unit/Services/Events/IntentApplierTest.php` — 3 из 4 (`testHealthDeltaCallsUpdateWithFloorOf001` failure, `testGoldDeltaAccumulates` и `testAttributeDeltasCombined` — `Undefined array key 0`): они перехватывают `CharacterModel::update()` подменой модели, а запись статов теперь идёт через `CharacterStatsService::adjust()`. Тест закрепляет старую форму записи со снимка; его нужно переписать (вне `## Files`). `EventDispatcherOneShotGuardTest`, `EventDispatcherSmokeTest` — зелёные.
- Повторный заход 2026-10-28: `phpstan-baseline.neon` всё ещё не обновлён (последний коммит по нему — 5061f962), phpstan красный теми же 9 baseline-записями `IntentApplier.php`; `IntentApplierRelativeDeltasTest` зелёный 5/5. Нужен файл вне `## Files`: `phpstan-baseline.neon` (правки — пункт выше), затем перезапуск `close-story`.

## Red receipt
- command: vendor/bin/phpunit --no-coverage --no-progress tests/database/IntentApplierRelativeDeltasTest.php && vendor/bin/phpstan analyse --memory-limit=512M --no-progress
- exit: 1
- tests: d21532fdd40eb07d07490c2d9276ec4fb2f7983b8b69cf8080437721267311c3 (tests/database/IntentApplierRelativeDeltasTest.php)
- impl: b9d711c66fd8994fc92e66052b5335b84db4672dc486b5a33667c35f56c53705
- stamped: 2026-10-28T20:59:33Z

```
C:\laragon\www\mmorpg\tests\database\IntentApplierRelativeDeltasTest.php:178

3) Tests\Database\IntentApplierRelativeDeltasTest::testMeteorOnStaleReadNeverGoesNegative
event delta written from a snapshot: second delta lost, or gold went negative
Failed asserting that 1 is identical to 0.

C:\laragon\www\mmorpg\tests\database\IntentApplierRelativeDeltasTest.php:243

[37;41mFAILURES![0m
[37;41mTests: 5[0m[37;41m, Assertions: 7[0m[37;41m, Failures: 3[0m[37;41m, PHPUnit Deprecations: 1[0m[37;41m.[0m
```
