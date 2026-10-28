---
story: s2-conditional-writes-04
spec: s2-conditional-writes
status: todo
returned:
tier: 3
worker: worker-code
wave: 1
blocked_by: []
red: required
red_reason: "ruin/anomaly loot issued inside the cooldown window"
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

## Findings
