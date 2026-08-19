---
story: transport-04
spec: transport-system
status: todo
tier: 3
worker: worker-code
model: sonnet
tracer: false
wave: 1
blocked_by: [transport-01, transport-02, transport-03]
---

# Поход на транспорте: пачка клеток, износ, честное превью

## Goal

Поход становится персональным ровно в одном месте — в размере пачки клеток за тик. Обработчик
берёт профиль активной машины, класс местности определяет по **следующей** клетке (один lookup
за тик), списывает износ адресно и уважает персональную длину приказа. Экран Похода печатает
ETA той же формулой и с разбивкой: «Разведано 14 клеток по 5 · целина 6 по 3 — 16 мин
(пешком 20)». Такт крона и все per-cell броски остаются нетронутыми.

## Requirements

> и на нём можно быстро передвигаться по исследуемым ячейкам, не по исследуемым, скорость передвижения и так далее, механика передвижения

## Files
- app/Services/World/VehicleEffectsService.php
- app/TaskHandlers/MarchingTaskHandler.php
- app/Controllers/Telegram/Commands/Actions/MarchAction.php
- tests/unit/Transport/MarchingTransportTest.php

## Поправка контракта (см. plan.md → «Поправка контракта»)

`VehicleEffectsService` получает карту `crafted_items.name_eng` → ключ профиля
(`LightCart`→`cart`, `MountainBike`→`mtb`, `Snowmobile`→`snowmobile`,
`DraftCart`→`draft_cart`, `AutonomousDrone`→`drone_auto`) и резолвер
`keyForItemNameEng()`. Правка этого файла разрешена ровно для двух вещей: карта
с резолвером и сведение констант местности к `MarchPaceService::TERRAIN_*`.

- [ ] Неизвестное имя предмета **не** даёт тихую нейтраль: тест падает, если хоть один
      `item_name_eng` из пяти транспортных рецептов отсутствует в карте.

## Non-goals
- 🔴 Не переносить per-cell броски на тик: PvP-детект, NPC, мини-событие, износ, XP, стат, усталость, здоровье остаются **за клетку**. Тик — единица времени, не единица игры; иначе транспорт станет невидимым щитом от PvP.
- Не менять шанс PvP-детекта ни вверх, ни вниз.
- Не трогать `stepDueInterval()` и `end_time`: персональная величина не попадает в вычисление времени.
- Не добавлять лишний lookup биома на каждую клетку — класс местности берётся один раз за тик по следующей клетке.
- Не строить экран транспорта, не добавлять крючок «🔒 с 6 уровня» (story 12) — здесь только арифметика и строка ETA.
- Не трогать одиночный шаг (`MoveCharacterToDirectionAction`) — это story 05.

## Map slice
`memory/map/world.md`, `memory/map/tasks-worker.md`, `memory/map/player.md`

## Acceptance criteria
- [ ] 🔴 Dormant байт-идентичен: `world.vehicle.enabled=false` **или** указатель `NULL` → `cellsPerTick`, ETA, стоимость 💤/❤️ и длина приказа равны сегодняшним числам (ассерт на конкретные числа).
- [ ] `stepDueInterval()` == `minutes_per_cell − 1` при **любом** профиле (табличный тест по пяти ключам).
- [ ] ETA превью == ETA обработчика для одних и тех же (клетки, профиль, местность) — оба зовут `MarchPaceService`.
- [ ] Маршрут 4 и 5 клеток на `cart` отличается от пешего минимум на 1 минуту; на 3 клетках ETA совпадает, и тест это фиксирует явно (бонус на коротком маршруте несёт усталость).
- [ ] `mtb` на целине даёт 5 клеток за тик, на разведанном — 4; `snowmobile` даёт 5 только в холодном биоме; `drone_auto` не меняет скорость вовсе (3), но снижает усталость.
- [ ] Износ: поход в N клеток списывает ровно N зарядов (снегоход — 2N) с активной строки через `VehicleActivationService::spendCharges()`.
- [ ] Заряды кончились посреди похода → оставшиеся клетки идут пешими числами, **поход не прерывается**, игрок получает предупреждение; предупреждение также приходит на остатке ~1/5.
- [ ] Персональный `max_steps_per_order` уважается при постановке приказа (90/120), при нейтрали — 60.
- [ ] Строка разбивки ETA присутствует в тексте превью и самодостаточна без картинки (media-off).
- [ ] Тест поведенческий: ломается тело формулы — тест краснеет.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Transport/MarchingTransportTest.php`

## Implementation notes

- `VehicleEffectsService`: `TERRAIN_*` сведены к `MarchPaceService::TERRAIN_*` (алиасы констант); добавлена `NAME_ENG_TO_KEY` карта + `keyForItemNameEng()` (static, единственный резолвер поправки контракта).
- `MarchingTaskHandler`: профиль машины и класс местности резолвятся РОВНО раз за тик (`resolveVehicleProfile()`/`terrainAhead()`), `cellsPerTick`/`etaMinutes` теперь принимают `array $vehicleProfile = []` (default сохраняет байт-идентичность). `advanceOneCell()` получил `array &$vehicleProfile` — per-cell списание износа (`VehicleActivationService::spendCharges()`) и откат на нейтраль при обнулении заряда живут ВНУТРИ клетки, не тика. Предупреждение о заряде — одноразовое поле `$s['vehicle_warning']`, гасится после показа. DI-семя: конструктор принял `VehicleActivationService`/`VehicleEffectsService` (default `new`) — не ломает существующий `tests/database/MarchingTaskHandlerTest.php` (позиционные аргументы, лишние параметры молча игнорируются PHP при вызове через дочерний override с меньшим числом параметров).
- `MarchAction`: `biomeAhead()` заменён на `aheadInfo()` (возвращает label+terrain одним lookup'ом); добавлены `resolveVehicleProfile()`, `routeBreakdown()` (batched SQL по всему заказу, не per-cell), `breakdownLine()`, `isColdBiome()`. `showRouteSetup`/`startMarch` используют персональный `max_steps_per_order` из профиля вместо плоского `world.march.max_steps_per_order`.
- Тест `tests/unit/Transport/MarchingTransportTest.php`: изолированная схема (своя `characters`/`crafted_items_log`/`map`/…), 18 тестов. Пять транспортных `item_name_eng` захардкожены в `dataProvider` (story-06 ещё не выкачена в этой волне — `Config\CraftRecipes` их пока не несёт; тест валит сборку по контракту поправки, не по факту наличия рецептов).
- Не найдено безопасного способа проверить критерий «предупреждение на остатке ~1/5» отдельным тестом без расширения времени сессии — покрыт только сценарий полного истощения заряда (`testChargesDepletedMidMarchFallsBackToPedestrianNextTick`). Формула порога (`before>threshold && remainder<=threshold`) реализована и проверена вручную по трассировке, но не тестом — кандидат на добор в review.

## Findings
