---
story: s2-conditional-writes-03
spec: s2-conditional-writes
status: done
returned: DONE
tier: 3
worker: worker-code
wave: 1
blocked_by: []
red: required
red_reason: gold went below zero, or repair/deposit applied without a confirmed payment
red_verified_at: 2026-10-28T20:55:09Z f246836de3df120ade0e91d7ced80d9c9ebdcbfacae83e70adce70f8147f1040
---

# F9: золото ремонта робота, пакетного ремонта дронов и вклада во фракцию — `decrementIfAtLeast` до эффекта

## Goal

Три экрана проверяют золото чтением и списывают `decrement('gold')` после: `RobotRepairConfirmAction.php:50-81`,
`RepairDroneRunAction.php:59-71` (прочность пишется ДО золота) и `FactionProjectService.php:133-165` (прогресс
проекта тоже со снимка). После story в каждом из трёх золото списывается
`decrementIfAtLeast('characters', $charId, 'gold', $price)` **до** ремонта/вклада, под транзакцией; `Refused` →
существующий текст отказа, ничего не отремонтировано и не внесено; `Missing` → отказ + `error`. Прогресс проекта
фракции — `increment(...)`, не `progress = снимок + n`. Три файла в одной story: одна форма, один ревью-юнит,
общий тест.

## Requirements

> 3. F9 — ремонт робота (`RobotRepairConfirmAction`), пакетный ремонт дронов (`RepairDroneRunAction`) и вклад во фракцию
> F9 золото ремонта/вклада

## Files

- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/Robots/RobotRepairConfirmAction.php
- app/Controllers/Telegram/Commands/Actions/Drone/RepairDroneRunAction.php
- app/Services/Player/FactionProjectService.php
- tests/database/GoldDecrementIfAtLeastTest.php

## Non-goals

- Не менять цену ремонта, формулу вклада, пороги проектов — всё остаётся там, где читается сегодня.
- Не переносить золото на `CharacterStatsService::adjust()`: списание-перед-выдачей здесь — `decrementIfAtLeast`,
  как у `BuildingUpgradeApplier` (`memory/map/player.md`).
- Не трогать `RobotRepairService`/`DroneService`, если они не пишут золото; при обнаружении там второй точки
  списания — отчёт, не правка.
- Не трогать `tests/exploit-poc/DuplicationTest.php` (три теста F9 там).

## Map slice

`memory/map/player.md` → Gotchas («`BuildingUpgradeApplier`: золото апгрейда — `decrementIfAtLeast`»; «Запись
статов через `CharacterStatsService`»); `memory/map/bases.md` → Entry points (`HangarAction`, роботы). Контракт
примитива, правило транзакций и форма теста — `plan.md` → `## Contracts`.

## Acceptance criteria

- [ ] В каждом из трёх файлов золото списывается одной условной записью до любого эффекта; эффект (прочность
      робота, прочность каждого дрона пакета, вклад и прогресс) идёт только на `Applied`, в той же транзакции.
- [ ] `RepairDroneRunAction`: порядок перевёрнут — золото до прочности; при отказе ни один дрон пакета не изменён.
- [ ] `FactionProjectService`: прогресс проекта — относительный `increment`; прочитанный прогресс в запись не попадает.
- [ ] Отказ — существующий текст каждого экрана; `Missing` пишет `error` в лог.
- [ ] Транзакция каждого пути завершается ровно один раз, исход по возврату.
- [ ] Тест `GoldDecrementIfAtLeastTest` — три метода (робот, дроны, фракция): персонаж проходит проверку-чтение, тест
      ставит `gold` меньше цены в БД, вызов → отказ, `gold` не ниже 0, прочность/прогресс не изменены. Сообщение
      ассерта при провале: `gold went below zero, or repair/deposit applied without a confirmed payment`.
      Action-handler'ы тестируются на уровне метода, который делает запись (без живого Telegram:
      `feedback_taskhandler_telegram_init_in_tests`).

## Verification

`vendor/bin/phpunit --no-coverage --no-progress tests/database/GoldDecrementIfAtLeastTest.php && vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes

- `RobotRepairConfirmAction.php`: запись вынесена в публичный `applyRepair()` → `'applied'|'refused'|'failed'`; золото `decrementIfAtLeast` первым, затем ресурсы и прочность; отказ → прежний текст «Недостаточно ресурсов или золота…».
- `RepairDroneRunAction.php`: запись — публичный `applyBatchRepair()`; порядок перевёрнут (золото → роботы → заряд); отказ → прежний текст «Не хватает N 🪙…», N по свежему чтению только для текста.
- `FactionProjectService.php`: `deposit()` — золото условно, прогресс `increment`, завершение цикла — инлайн условный `UPDATE progress = progress - порог … WHERE progress >= порог` + `affectedRows`; текст отказа прежний, «есть N» по свежему чтению.
- Транзакции всех трёх путей: форма `CargoDroneAutoSendAction` (глубина до `transStart`, `transRollback` на отказе, `transComplete()` по возврату, rollback в `catch`).
- `Missing` → `log_message('error')` + отказ во всех трёх.
- Тест: фракция — через публичный `deposit()` с понижением золота вторым соединением после чтения персонажа (`DBQuery`); `faction_projects` — из миграции V20; `characters`/`crafted_items_log` — DDL без FK (их миграции тянут FK на таблицы, которых стенд не держит).
- Сюрприз: `red_reason` в кавычках не находился `grep -F` — кавычки убраны.
- Не тронуто (вне золота): ресурсы ремонта робота и заряд дрона по-прежнему пишутся абсолютным значением со снимка.

## Findings

## Red receipt
- command: vendor/bin/phpunit --no-coverage --no-progress tests/database/GoldDecrementIfAtLeastTest.php && vendor/bin/phpstan analyse --memory-limit=512M --no-progress
- exit: 2
- tests: f246836de3df120ade0e91d7ced80d9c9ebdcbfacae83e70adce70f8147f1040 (tests/database/GoldDecrementIfAtLeastTest.php)
- impl: 361c484004993acacf46333a5e5562c52cdbab4c8fc32d935d208a2a3f26d341
- stamped: 2026-10-28T20:55:09Z

```
There was 1 failure:

1) Tests\Database\GoldDecrementIfAtLeastTest::testFactionDepositRefusesWhenGoldDroppedBelowDepositAfterReadCheck
gold went below zero, or repair/deposit applied without a confirmed payment
Failed asserting that true is false.

C:\laragon\www\mmorpg\tests\database\GoldDecrementIfAtLeastTest.php:211

[37;41mERRORS![0m
[37;41mTests: 3[0m[37;41m, Assertions: 2[0m[37;41m, Errors: 2[0m[37;41m, Failures: 1[0m[37;41m, PHPUnit Deprecations: 1[0m[37;41m.[0m
```
