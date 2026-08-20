---
story: transport-02
spec: transport-system
status: done
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

- `App\Services\World\VehicleEffectsService` создан с нуля: конструктор `(?GameSettingsService, ?array $overrides)` (паттерн ADR-131/138, как `EarlyProgressionService`). Терраин-константы `TERRAIN_EXPLORED/UNEXPLORED/COLD` объявлены локально в сервисе (строки `'explored'/'unexplored'/'cold'`) — `MarchPaceService` (story 01) в `## Files` этой story не входит и на момент работы в репозитории отсутствовал; story 01 может переиспользовать те же строковые значения.
- `profileFor()` зажимает `cells_per_tick` в `[base..5]` и `cargo_share` в `[0..world.vehicle.cargo.max_share]`; при killswitch off, `null`-ключе или неизвестном ключе — всегда `neutralProfile()`, без исключений.
- Seed-миграция `2026-11-28-100000_SeedVehicleGameSettings.php` сеет 52 ключа (2 глобальных + 5 машин × 10 полей, включая `required_level`/`required_faction`, которые `profileFor()` не читает — задел под story 06/07/11) с полным rationale/effect/above/below, идемпотентна по `setting_key`. `game_settings` уже классифицирован KEEP в `WipeManifest` — новых таблиц/колонок нет, манифест не трогали.
- Тест — табличный `@dataProvider` (5×3=15 кейсов) + отдельные тесты на нейтраль/killswitch/unknown-key/clamp/«снегоход — единственное исключение».

## Findings
