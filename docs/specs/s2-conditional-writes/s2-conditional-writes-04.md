---
story: s2-conditional-writes-04
spec: s2-conditional-writes
status: done
returned: DONE
tier: 3
worker: worker-code
wave: 1
blocked_by: []
red: required
red_reason: ruin/anomaly loot issued inside the cooldown window
red_verified_at: 2026-10-28T20:53:44Z 034875445c92a46fbf3df01902470ec1261f906fec6cdb68c7bd48780a50d74a
---

# F10: кулдаун руин и аномалий резервируется условной записью до лута

## Goal

`RuinLootService.php:128-160` выдаёт лут и лишь потом `touch()` `:157` пишет `last_looted_at`; два вызова в окне
кулдауна оба проходят чтение и оба выдают. После story резерв кулдауна — одна условная запись по
`character_ruin_loot` (`UNIQUE(character_id, settlement_id)`): строка есть →
`UPDATE … SET last_looted_at = <now> WHERE character_id = ? AND settlement_id = ? AND (last_looted_at IS NULL OR
last_looted_at < <now - cd>)` + `affectedRows`; строки нет → `insertUnique`, `Refused` на дубле = кулдаун. Лут выдаётся
только после успешного резерва, иначе ответ `cooldown` (уже обрабатывается вызывающими). Аномалии пояса идут через
тот же сервис (`AnomalyLootAction.php:49`, не проверено) — story закрывает оба пути.

## Requirements

> 4. F10 — лут руин и поясных аномалий (`RuinLootService`) резервирует кулдаун условной записью ДО выдачи лута; лут — только
> F10 кулдаун руин и аномалий до лута

## Files

- app/Services/Settlement/RuinLootService.php
- app/Models/CharacterRuinLootModel.php
- app/Controllers/Telegram/Commands/Actions/AnomalyLootAction.php
- tests/database/RuinLootCooldownReserveTest.php

## Non-goals

- `AnomalyLootAction.php` правится **только** если аномалия зовёт `touch()`/лут в обход нового резерва; если она
  целиком идёт через `RuinLootService::loot()` — файл не трогается (вопрос 6 плана).
- Не менять длину кулдауна и таблицы лута; значение берётся из того же источника, что читает сервис сегодня.
  Никакого нового ключа `GameSettings`.
- Не добавлять лок: инвариант — предусловие одной строки (ADR-181 п. 4, ступень 2).
- Не трогать `tests/exploit-poc/GapAuditTest.php::testRuinLootAwardsOncePerCooldownWindow`.

## Map slice

- Recon до волны 1 (Q6, 28.10): `AnomalyLootAction.php:49` идёт целиком через `RuinLootService::loot()`; единственный `touch()` — `RuinLootService.php:157`. `CharacterRuinLootModel::touch()` — read-then-insert/update (`first()` `:39-42`, затем `update`/`insert` `:45/:50`). UNIQUE(`character_id`,`settlement_id`) есть (`2026-07-03-110000_CreateCharacterRuinLootTable.php:28`) — `insertUnique` для первого лута применим.

`memory/memory.md` → Unmapped territory (`Services/Settlement` среза не имеет — читать сервис адресно);
`memory/map/bases.md` → Gotchas («Старт стройки (ADR-181)» как образец условного списания). Контракт примитива,
инлайн-CAS по произвольному предикату и форма теста — `plan.md` → `## Contracts`.

## Acceptance criteria

- [ ] Резерв кулдауна — одна условная запись (UPDATE с предикатом окна + `affectedRows`, либо `insertUnique` при
      отсутствии строки); `touch()` после лута удалён или сведён к этому резерву.
- [ ] Лут (ресурсы, золото, что ещё выдаёт сервис) идёт только после подтверждённого резерва; при отказе — ответ
      `cooldown`, ничего не выдано. Резерв и выдача — в одной транзакции: упавшая выдача откатывает резерв.
- [ ] Часы предиката и часы записи — одни (БД `NOW()` или PHP-время, как сравнивает сервис сегодня;
      `feedback_db_clock_seed_not_php_in_time_window_tests`).
- [ ] Оба входа — руины и аномалия пояса — проходят через резерв; `AnomalyLootAction` не содержит собственного
      `touch()` в обход.
- [ ] Тест `RuinLootCooldownReserveTest`: персонаж у руин, первый лут выдан; тест ставит `last_looted_at` «только
      что» (или оставляет от первого вызова) и зовёт лут снова → `cooldown`, ресурсы и золото не изменились, строка
      лога одна. Сообщение ассерта при провале: `ruin/anomaly loot issued inside the cooldown window`.

## Verification

`vendor/bin/phpunit --no-coverage --no-progress tests/database/RuinLootCooldownReserveTest.php && vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes

- `app/Models/CharacterRuinLootModel.php`: `touch()` (read-then-insert/update) заменён на `reserveCooldown($char, $sid, $now, $readyBefore): bool` — инлайн условный `UPDATE … AND (last_looted_at IS NULL OR last_looted_at <= ?)` + `affectedRows`, при 0 строк — `ConditionalWriteService::insertUnique` (дубль = кулдаун). Других вызывающих `touch()` в `app/` и `tests/` не было (grep).
- `app/Services/Settlement/RuinLootService.php`: чтение `remainingCooldownSeconds` оставлено быстрым отказом с остатком; после проверки пустого `loot_config` — `transBegin` → резерв → выдача → `transCommit`; отказ резерва → rollback + `cooldown` с остатком; исключение выдачи → rollback и проброс; `transCommit() === false` → `reason: invalid` (существующий текст «Обыскать не вышло.»).
- Часы: PHP-время, как сравнивал сервис раньше; `$now` и `$readyBefore = $now − cooldown·3600` из одного `time()`. Граница `<=` повторяет прежнее `remaining = max(0, last + cd − now)`.
- `AnomalyLootAction` не тронут: путь в `## Files` неверен (файл лежит в `Actions/Settlement/`), аномалия целиком идёт через `RuinLootService::loot()` (`:49`), своего `touch()` нет.
- Тест: устаревший снимок — подкласс модели с `lastLootedAt() → null`, строка в БД свежая; схема `character_ruin_loot` — настоящая миграция (`require_once` + `up()`), оба входа (ruins / world.anomalies) data provider'ом.
- Сюрприз: `red_reason` в кавычках не находился `stamp-red` (`fm_raw` не снимает кавычки) — кавычки убраны.
- Крайний случай: при `loot_cooldown_hours = 0` повтор в ту же секунду даёт `cooldown` (UPDATE с тем же значением → `affectedRows = 0`), раньше проходил.

## Findings

## Red receipt
- command: vendor/bin/phpunit --no-coverage --no-progress tests/database/RuinLootCooldownReserveTest.php && vendor/bin/phpstan analyse --memory-limit=512M --no-progress
- exit: 1
- tests: 034875445c92a46fbf3df01902470ec1261f906fec6cdb68c7bd48780a50d74a (tests/database/RuinLootCooldownReserveTest.php)
- impl: 02137abb28984a9dcf591aa40b95847997bed6f60420aff6d5fb2865b7cee041
- stamped: 2026-10-28T20:53:44Z

```
C:\laragon\www\mmorpg\tests\database\RuinLootCooldownReserveTest.php:181

2) Tests\Database\RuinLootCooldownReserveTest::testLootInsideCooldownWindowIsRefusedByTheWriteNotTheRead with data set "belt anomaly" ('world.anomalies')
ruin/anomaly loot issued inside the cooldown window
Failed asserting that true is false.

C:\laragon\www\mmorpg\tests\database\RuinLootCooldownReserveTest.php:181

[37;41mFAILURES![0m
[37;41mTests: 2[0m[37;41m, Assertions: 8[0m[37;41m, Failures: 2[0m[37;41m, PHPUnit Deprecations: 1[0m[37;41m.[0m
```
