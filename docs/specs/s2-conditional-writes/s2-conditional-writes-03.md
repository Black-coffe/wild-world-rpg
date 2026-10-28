---
story: s2-conditional-writes-03
spec: s2-conditional-writes
status: todo
returned:
tier: 3
worker: worker-code
wave: 1
blocked_by: []
red: required
red_reason: "gold went below zero, or repair/deposit applied without a confirmed payment"
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

## Findings
