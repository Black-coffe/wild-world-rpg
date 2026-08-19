---
story: transport-05
spec: transport-system
status: done
tier: 2
worker: worker-code
model: sonnet
tracer: false
wave: 1
blocked_by: [transport-02, transport-03]
---

# Одиночный шаг: множитель усталости и конец магических чисел

## Goal

Синхронный шаг по карте получает из профиля только множитель усталости (ускорять мгновенное
действие нечего), а три hardcoded-константы стоимости шага (`baseHealthCost=0.1`,
`baseTiredCost=3.35`, danger-надбавка `+1.15`) уезжают в `GameSettings` под `world.move.*`
с дефолтами, равными нынешним значениям. Оставлять магическое число рядом с новым множителем —
хуже, чем 30 строк обратимой правки.

## Requirements

> механика передвижения

## Files
- app/Controllers/Telegram/Commands/Actions/MoveCharacterToDirectionAction.php
- app/Database/Migrations/*SeedWorldMoveGameSettings.php
- tests/unit/Transport/SingleStepMoveCostTest.php

## Non-goals
- Не давать одиночному шагу скорость/ускорение — у него нет длительности.
- Не трогать `EarlyProgressionService::moveCostFactor()` (×0.8 новичкам): множители перемножаются, порядок сохраняется, поведение новичка без транспорта — байт-идентично.
- Не менять состав проверок шага (вода, край мира, чужие `claimed_cells`, полы ❤️/💤).
- Не трогать Поход — это story 04.
- Не списывать износ на одиночном шаге, если это ломает «N клеток = N зарядов»: одиночный шаг — одна клетка, ровно один заряд, не больше.

## Map slice
`memory/map/world.md`, `memory/map/player.md`, `memory/map/data-layer.md`

## Acceptance criteria
- [x] Стоимость шага вынесена в чистый публичный метод (без БД и Telegram), который принимает настройки, флаг опасного биома, профиль и фактор новичка — тест зовёт именно его.
- [x] Байт-идентичность: килсвитч off / нет транспорта → стоимость шага равна сегодняшней при дефолтах `0.1` / `3.35` / `+1.15` (ассерт на числа).
- [x] Три ключа `world.move.health_cost_base`, `world.move.tired_cost_base`, `world.move.danger_tired_surcharge` заведены с полным rationale/effect/above/below и дефолтами, равными нынешним; миграция идемпотентна по `setting_key`.
- [x] Профиль `draft_cart`/`drone_auto` (`tired_factor=0.75`) даёт строго меньшую усталость шага, чем пеший; `snowmobile` (`1.10`) — строго большую; здоровье профилем не меняется.
- [x] Комбинация «новичок + транспорт» считается один раз и не даёт двойного округления в ноль.
- [x] Тест краснеет при подмене любого из трёх дефолтов.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Transport/SingleStepMoveCostTest.php`
`git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`

## Implementation notes

- `MoveCharacterToDirectionAction::computeStepCost()` — новый чистый `public static` метод (без БД/Telegram).
  Реализация делегирует в уже существующий `MarchPaceService::healthCostPerCell()`/`tiredCostPerCell()`
  (одна формула на весь транспорт, не дубль), добавляя danger-надбавку к здоровью и early-фактор к
  усталости одним умножением (`tired_cost_base × tired_factor × earlyFactor`, без промежуточных round()).
- Килсвитч-надбавка danger применяется к ❤️ здоровью (не к усталости), хотя ключ называется
  `world.move.danger_tired_surcharge` — так было в исходном hardcoded-коде (`$healthCost += 1.15;`),
  переименовывать семантику эта story не вправе (байт-идентичность важнее интуитивного имени).
- Добавлен приватный `resolveVehicleProfile()`: читает `VehicleActivationService::resolveActive()`,
  переводит `name_eng` → ключ профиля через `VehicleEffectsService::keyForItemNameEng()`, при
  `charges<=0` или отсутствии активной машины отдаёт нейтраль (killswitch/отсутствие машины уже
  гарантирует нейтраль внутри `VehicleEffectsService::profileFor()`). Terrain для одиночного шага
  зафиксирован `TERRAIN_EXPLORED` — `tired_factor` от terrain не зависит (только `cells_per_tick`,
  которого у мгновенного шага нет).
- Три константы (`0.1`/`3.35`/`+1.15`) переехали в новую seed-миграцию `SeedWorldMoveGameSettings`
  под `world.move.*` (idempotent по `setting_key`, паттерн 1:1 с `SeedVehicleGameSettings`).
- `phpstan-baseline.neon` содержит 2 устаревшие записи `missingType.property` для удалённых
  `$baseHealthCost`/`$baseTiredCost` — не тронуты (не в `## Files` этой story). Целостный
  `vendor/bin/phpstan analyse app` сейчас красный из-за них + из-за параллельной сессии
  (`MarchingTaskHandler`, `DeathService`, ~10 ошибок) — вне зоны этой story.
- Tips/guide-вердикт: механика не меняет discoverability/caption/кнопки одиночного шага —
  только внутренние числа. Вердикт Редколлегии: **не нужен новый совет/раздел** — «стоимость шага»
  не появляется как новая player-facing поверхность, это рефакторинг существующей и уже
  задокументированной механики (жив совет `MarchSpeed`, story transport-13 обновляет его отдельно
  под весь транспорт).

## Findings
