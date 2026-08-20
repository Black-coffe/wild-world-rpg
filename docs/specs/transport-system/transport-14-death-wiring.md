---
story: transport-14
spec: transport-system
status: done
tier: 2
worker: worker-code
model: sonnet
tracer: false
wave: 2
blocked_by: [transport-03, transport-09]
---

# Смерть действительно разбивает машину (подключение к живому потоку)

## Goal
`LootProcessor::breakActiveVehicleOnDeath()` реализован и покрыт тестами в story 09,
но не вызывается ниоткуда: `DeathService` не входил в её `## Files`. Сегодня транспорт
при смерти не теряется (это верно) и не разбивается (это дыра) — обещанный игроку
sink не срабатывает вообще. Story подключает вызов к реальному потоку смерти и
доносит факт до игрока текстом.

## Requirements
> Заворотить систему транспорта, начиная от крафта, логики, интеграции, доступности от разных уровней

## Context
Воркер story 09 отказался трогать `DeathService.php` вне своего `## Files` и честно
пометил разрыв — это правильное поведение, а не недоработка. Здесь разрыв закрывается.

Это ровно тот класс дефекта, который в проекте называется BUILT-BUT-DEAD: способность
есть, вызова нет, тесты зелёные. Приёмка story — не «метод существует», а «персонаж
умер → износ активной машины ноль → игрок это прочитал».

## Files
- app/Services/Player/DeathService.php
- tests/database/Transport/DeathWiringTest.php

## Acceptance
- [ ] Персонаж с активной машиной умирает (реальный путь `DeathService`, не прямой
      вызов метода) → `durability_count` строки транспорта == 0, `quantity` не изменился,
      указатель `active_vehicle_log_id` обнулён.
- [ ] Персонаж без активной машины умирает → ни одна строка транспорта не тронута,
      исключения нет.
- [ ] Машина уже с нулевым износом → повторная смерть ничего не ломает (идемпотентность).
- [ ] Игрок получает текст о том, что машина разбита и нужен ремонт: самодостаточный
      в media-off, markdown-safe, ≤ 1024 знаков вместе с остальным сообщением о смерти.
- [ ] Существующие наборы тестов смерти остаются зелёными — доля −3%/−50% и дробный
      бросок ADR-172 не тронуты.

## Non-goals
- Не менять `LootProcessor` (story 09 — его единственный владелец).
- Не трогать долю потерь остальных предметов и логику страховки.
- Не заводить экран ремонта — он в story 10.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/database/Transport/DeathWiringTest.php`

## Map slice
`memory/map/pve-pvp.md` (смерть, `DeathService`), `memory/map/player.md`

## Implementation notes

- `DeathService::handlePlayerDeathAndReward()` теперь зовёт `LootProcessor::breakActiveVehicleOnDeath($loserId)`
  как шаг 5b — после списания у проигравшего, до передачи победителю (транспорт из передачи
  исключён ещё story 09, порядок значения не имеет).
- Доставка текста игроку — отдельное самодостаточное сообщение (не вклейка в `DeathMessageBuilder`,
  который вне `## Files` этой story), по образцу `LevelUpNotifier`: `chatIdFor()` читает
  `telegram_users` напрямую через `Database::connect()`, `sendVehicleBrokenMessage()` — protected
  seam для тестов, лениво инициализирует `Telegram`/`Request`, никогда не бросает наружу.
- Тест `tests/database/Transport/DeathWiringTest.php` идёт через реальный `handlePlayerDeathAndReward()`
  на изолированной схеме (9 таблиц: characters/character_resources/crafted_items_log/crafted_items/
  claimed_cells/map/events/active_events/telegram_users) — подменён только сетевой шов
  (`TestableDeathService::sendVehicleBrokenMessage`), поиск чата и вся DB-логика боевые.
- phpstan: единственный оставшийся error на `DeathService.php` (строка про `computeCraftLoss`,
  `argument.type`) — подтверждён pre-existing (`git stash` на неизменённый файл даёт ту же ошибку).
