---
story: transport-03
spec: transport-system
status: todo
tier: 3
worker: worker-code
model: sonnet
tracer: false
wave: 0
blocked_by: []
---

# Указатель активного транспорта и его жизненный цикл

## Goal

У персонажа появляется один nullable указатель `characters.active_vehicle_log_id` на строку
`crafted_items_log`, и сервис `App\Services\Player\VehicleActivationService`, который умеет
его ставить, снимать, безопасно читать (анти-IDOR + самолечение висячего указателя), списывать
износ адресно и «разбивать» машину при смерти. Инвариант «активна максимум одна машина»
держится **структурно**: одно поле — одно значение.

## Requirements

> Заворотить систему транспорта, начиная от крафта, логики, интеграции, доступности от разных уровней, и даже чтобы фракция влияла на транспорт.

## Files
- app/Services/Player/VehicleActivationService.php
- app/Database/Migrations/*AddActiveVehicleLogIdToCharacters.php
- app/Models/CharacterModel.php
- app/Config/WipeManifest.php
- app/Services/Admin/WipeService.php
- tests/unit/Transport/VehicleActivationServiceTest.php

## Non-goals
- Не строить экран и не регистрировать callback — это story 10.
- Не звать `LootProcessor` и не менять смерть: story 09 вызовет готовый `breakActive()`.
- Не заводить новую таблицу: владение остаётся в `crafted_items_log` (ADR-174 §1, отвергнутые варианты).
- Не вешать FK с каскадом на `crafted_items_log`: висячий указатель лечится чтением, а не схемой.
- Не искать строку по `crafted_item_id` (`first()` без `orderBy` при дублях вернёт произвольную) — адресация только по `id`.

## Map slice
`memory/map/player.md`, `memory/map/data-layer.md` (миграции, WipeManifest), `memory/map/craft.md` (`crafted_items_log`, `effectiveCharges()`)

## Acceptance criteria
- [ ] Миграция добавляет nullable `active_vehicle_log_id` в `characters`; колонка внесена в `$allowedFields` `CharacterModel` (иначе запись молча не сохранится).
- [ ] `WipeManifest`/`WipeService`: при `CHARACTER_RESET` указатель обнуляется — поведенческий тест на сброс персонажа проверяет `NULL` после вайпа, `WipeManifestCoverageTest` зелёный.
- [ ] `activate()` с `log_id` **чужого** персонажа → `false`, запись не произошла (IDOR).
- [ ] Повторная активация другой машины **переставляет** указатель: после двух вызовов активна ровно одна, первая — не активна.
- [ ] Строка `crafted_items_log` удалена → `resolveActive()` возвращает `null`, указатель в БД стал `NULL`, исключения нет (самолечение).
- [ ] `spendCharges()` на N клеток списывает ровно N (или N×`wear_per_cell`) зарядов **с той самой строки по id**; соседняя строка того же предмета не тронута.
- [ ] Заряды читаются через `effectiveCharges()` и зажимаются `min(dur, base)` при каждом чтении: строка с историческим мусором `durability_count=100` при базе 300 не даёт 100 больше базы и не даёт отрицательного остатка.
- [ ] На нуле зарядов `resolveActive()` всё ещё возвращает строку, но её профиль потребитель обязан считать нейтральным — тест фиксирует `charges===0` и отсутствие исключения (поход не блокируется).
- [ ] `breakActive()` ставит износ активной строки в 0, обнуляет указатель и **оставляет строку `crafted_items_log` на месте** (`quantity` не изменился) — решение владельца «разбивается, но не пропадает».
- [ ] `deactivate()` работает из любого состояния, в том числе когда указатель уже `NULL`.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Transport/VehicleActivationServiceTest.php`
`git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`

## Implementation notes

## Findings
