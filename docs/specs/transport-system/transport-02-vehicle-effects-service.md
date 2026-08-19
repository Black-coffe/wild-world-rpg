---
story: transport-02
spec: transport-system
status: todo
tier: 3
worker: worker-code
model: sonnet
tracer: false
wave: 0
blocked_by: []
---

# VehicleEffectsService — профиль машины и килсвитч

## Goal

Появляется тонкий ридер `App\Services\World\VehicleEffectsService`, который по ключу машины и
классу местности отдаёт **профиль** (контракт в `plan.md`), а при выключенном килсвитче или
`null`-ключе — нейтраль. Все числа пяти машин заезжают в `GameSettings` под `world.vehicle.*`
с полным rationale, килсвитч `world.vehicle.enabled=false`. Игрок не видит ничего: профиль
пока никто не запрашивает.

## Requirements

> Заворотить систему транспорта, начиная от крафта, логики, интеграции, доступности от разных уровней, и даже чтобы фракция влияла на транспорт.

## Files
- app/Services/World/VehicleEffectsService.php
- app/Database/Migrations/*SeedVehicleGameSettings.php
- tests/unit/Transport/VehicleEffectsServiceTest.php

## Non-goals
- Не читать `characters.active_vehicle_log_id` и вообще БД персонажа: ключ машины приходит параметром. Разрешение указателя — story 03, и она пишется параллельно.
- Не трогать `MarchingTaskHandler`, `MarchAction`, крафт — врезка это story 04.
- Не заводить константы с числами в PHP: любое число баланса живёт в `GameSettings` (`Config\*` для баланса запрещён).
- Не изобретать ключи и значения — таблица в `plan.md → ## Contracts` полная и обязательная.

## Map slice
`memory/map/world.md`, `memory/map/data-layer.md` (GameSettings, миграции)

## Acceptance criteria
- [ ] Конструктор `(?GameSettingsService $settings = null, ?array $overrides = null)` — весь тест идёт на `$overrides`, без БД (паттерн ADR-131/138).
- [ ] `neutralProfile()` — `key=null`, `cells_per_tick` = база `world.march.cells_per_tick`, `tired_factor=1.0`, `max_steps_per_order` = база, `cargo_share=0.0`, `wear_per_cell=0`.
- [ ] `isEnabled()==false` → `profileFor('mtb', TERRAIN_UNEXPLORED)` равен `neutralProfile()` **поэлементно** (ассерт на массив целиком).
- [ ] `profileFor(null, ...)` — нейтраль при любом состоянии килсвитча.
- [ ] Неизвестный ключ машины → нейтраль, без исключения.
- [ ] Для каждого из пяти ключей и трёх классов местности профиль равен таблице контракта (табличный тест, пять × три).
- [ ] 🔴 Ни одно значение профиля не хуже пешего: `cells_per_tick >= base` и `tired_factor <= 1.0`… кроме `snowmobile` (`1.10`) — его минус объявлен в карточке; тест фиксирует это как единственное исключение поимённо.
- [ ] `cells_per_tick` зажат сверху 5 даже при мусорном значении настройки (hard_max).
- [ ] `cargo_share` зажат сверху `world.vehicle.cargo.max_share` (0.33).
- [ ] Каждый seed-ключ несёт `rationale_text`, `effect_text`, `above_effect_text`, `below_effect_text`, `recommended_min/max`, `hard_min/max`, `default_value_text`; миграция идемпотентна по `setting_key`.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Transport/VehicleEffectsServiceTest.php`
`git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`

## Implementation notes

## Findings
