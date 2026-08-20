---
story: transport-17
spec: transport-system
status: done
tier: 2
worker: worker-code
model: sonnet
tracer: false
wave: 3
blocked_by: []
---

# Автономный дрон Инженеров заряжается на базе, а не чинится как колесо

## Goal
`concept-final.md` §1 обещает Инженерам единственное механическое отличие их машины:
**«заряд на базе, не ремонт»** — это и есть канон фракции («зависимость от энергии»,
ADR-060). В коде обещание не выполнено: `DroneRechargeCron` перечисляет только
`DroneScout` / `DroneCargo` / `DroneRepair` / `DroneCombat`; `AutonomousDrone` там
отсутствует, поэтому транспортный дрон восстанавливается общим ремонтом
(`RepairToolsListAction` / NPC-мастер) наравне с повозкой и снегоходом.

Фракционное отличие Инженеров сегодня не существует. Story его включает.

## Requirements
> «Инженеры — зависимость от энергии»: дрон несёт рюкзак рядом с выжившим,
> **заряжается на базе**, а не ремонтируется (concept-final.md §1, ADR-174)

## Context
Механика зарядки уже написана и работает для четырёх других дронов: цикл
`run()` → `rechargeType()` в `app/TaskHandlers/Drone/DroneRechargeCron.php`
(строки ~65-100) — для каждого типа берётся тройка `enabled` / `max` / `rate`
из `DroneService`, персонаж должен находиться на своей базе, `durability_count`
растёт на `rate × intervalMinutes` с клипом по `max`. Добавляется ПЯТЫЙ тип,
новая механика не пишется.

Значения `max` для транспортного дрона уже живут в балансе:
`world.vehicle.drone_auto.charges_full` (на проде 350) — брать оттуда, не заводить
второй источник правды. Ставка заряда — новый ключ, её в игре ещё нет.

🔴 **ADMIN-TUNABLE BALANCE:** ставка заряда — это игровое число (время
восстановления), поэтому она ОБЯЗАНА появиться в `GameSettings` через seed-миграцию
с полными `rationale_text` / `effect_text` / `above_effect_text` / `below_effect_text`
и границами. Hardcoded константы в коде — отказ в мердже. Ключ:
`world.vehicle.drone_auto.charge_per_minute`. Killswitch отдельный не нужен —
роль killswitch'а играет `world.vehicle.enabled` (транспорт целиком).

## Files
- app/TaskHandlers/Drone/DroneRechargeCron.php
- app/Services/Player/DroneService.php
- app/Database/Migrations/2026-12-03-100000_SeedDroneAutoChargeRate.php
- tests/database/Transport/DroneAutoRechargeTest.php

## Acceptance
- [ ] `DroneService` отдаёт три значения для транспортного дрона: включённость
      (через `world.vehicle.enabled`), `max` (из `world.vehicle.drone_auto.charges_full`)
      и ставку (из нового ключа `world.vehicle.drone_auto.charge_per_minute`).
- [ ] `DroneRechargeCron::run()` обрабатывает `AutonomousDrone` пятым типом по тому же
      контракту, что остальные четыре — своей ветки-исключения не появляется.
- [ ] Персонаж НА своей базе с изношенным дроном → `durability_count` растёт на
      `rate × intervalMinutes`, зажат сверху `charges_full`.
- [ ] Персонаж НЕ на базе → строка дрона не тронута.
- [ ] `world.vehicle.enabled = false` → ни одна строка дрона не тронута (транспорт
      выключен целиком).
- [ ] Ставка ≤ 0 → тип пропускается, как и у остальных дронов (существующий гейт).
- [ ] Seed-миграция идемпотентна и несёт полный набор rationale/effect/above/below +
      recommended/hard границы. Без них запись не имеет права появиться.
- [ ] Остальные четыре типа дронов не задеты — их тесты остаются зелёными.

## Non-goals
- Не трогать ремонт: `RepairToolsListAction` продолжает видеть транспорт (дрон
  остаётся чинибельным, зарядка — дополнительный путь, а не замена). Снятие дрона
  из ремонта — отдельное решение владельца, не эта story.
- Не менять `wear_per_cell`, скорость, груз и прочие параметры дрона.
- Не трогать `VehicleEffectsService` и экран «Мой транспорт».

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/database/Transport/DroneAutoRechargeTest.php`
и затем ПОЛНЫЙ `vendor/bin/phpunit --no-coverage --no-progress` — сверять наличие
итоговой строки `Tests: N`, а не exit code.

## Map slice
`memory/map/` — дроны и транспорт; `mmorpg-vault/decisions/ADR-174-Transport-system.md`

## Implementation notes
- `app/Services/Player/DroneService.php` — три новых метода по образцу cargo/repair/combat:
  `droneAutoIsEnabled()` (читает общий `world.vehicle.enabled`, не отдельный killswitch),
  `droneAutoBatteryMax()` (переиспользует уже посеянный `world.vehicle.drone_auto.charges_full`),
  `droneAutoChargeRatePerMinute()` (новый ключ `world.vehicle.drone_auto.charge_per_minute`,
  хранится напрямую как rate, а не как `base_charge_minutes_per_full`, т.к. «минут до полного»
  для транспорта нигде не существовало). В отличие от `isEnabled/batteryMax`, ставка НЕ
  подстраховывается дефолтом при ≤0 — иначе гейт `rate <= 0.0 → continue` в кроне никогда бы не
  сработал; fallback на 1.2 срабатывает только при нечисловом значении.
- `app/TaskHandlers/Drone/DroneRechargeCron.php` — пятая запись в массиве `$types`
  (`AutonomousDrone`), сам `rechargeType()` не тронут (уже generic).
- `app/Database/Migrations/2026-12-03-100000_SeedDroneAutoChargeRate.php` — idempotent seed
  одного ключа `world.vehicle.drone_auto.charge_per_minute`, дефолт 1.2 заряда/мин: при потолке
  350 полный заряд с нуля ≈4ч52м — дольше scout (2ч/100), в диапазоне repair/combat (4-6ч/100).
  Полный набор rationale/effect/above/below + recommended(0.5-3.0)/hard(0.1-10.0) границ.
  `game_settings` уже KEEP в WipeManifest — новых таблиц/колонок нет, манифест не трогался.
- `tests/database/Transport/DroneAutoRechargeTest.php` — 5 тестов: заряд на базе (rate×interval),
  клип к charges_full, не на базе → не тронут, `world.vehicle.enabled=false` → не тронут,
  rate=0 → пропуск (гейт крона).
- Прогоны: свой файл 5/5, `tests/database/DroneRechargeCronTest.php` (регресс остальных четырёх
  типов) 7/7 без изменений, полный `vendor/bin/phpunit --no-coverage --no-progress` →
  `Tests: 3174, Assertions: 27619, Skipped: 8` без ошибок/фейлов (только известные PHPUnit
  deprecations). `phpstan analyse level 9` → No errors.
- Non-goals соблюдены: `RepairToolsListAction`/ремонт не тронуты, `wear_per_cell`/скорость/груз
  не менялись, `VehicleEffectsService` и экран «Мой транспорт» не тронуты.
