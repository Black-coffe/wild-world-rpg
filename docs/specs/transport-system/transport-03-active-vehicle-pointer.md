---
story: transport-03
spec: transport-system
status: done
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

- Миграция `2026-08-19-231500_AddActiveVehicleLogIdToCharacters.php`: nullable `INT UNSIGNED`, без FK (Non-goal), `after node_announce_enabled`.
- `CharacterModel::$allowedFields` += `active_vehicle_log_id`; `WipeManifest::$characterResetValues` += `active_vehicle_log_id => null` (прогресс, не преференс) — `characters` уже `CHARACTER_RESET`, отдельная запись таблицы не нужна.
- `VehicleActivationService` (`app/Services/Player/VehicleActivationService.php`): все чтения владения — `WHERE id=? AND character_id=?` через `fetchOwnedRow()`. `resolveActive()`/`spendCharges()`/`breakActive()` читают указатель из `characters.active_vehicle_log_id` напрямую (raw builder, не `CharacterModel`, чтобы не тащить Entity/allowedFields-цикл в сервис).
- Решение по «нулю зарядов» (расходится с `CraftedItemsLogModel::effectiveCharges()`): у модели пол `max(1, ...)` — корректно для «последней дозы» медикамента, но противоречит acceptance-критерию story (`charges===0` обязан быть достижим для полностью изношенного транспорта). `spendCharges()` использует `effectiveCharges()` только для чтения ТЕКУЩЕГО остатка перед списанием (защита от исторического мусора), а сам остаток после вычитания — `max(0, $current - $spend)`, пишется как есть. `resolveActive()` для отображения использует собственный локальный `clampCharges()` (`min(dur, base)` без пола) — не переиспользует `CraftedItemsLogModel::effectiveCharges()` для финального значения именно из-за этого пола. Задокументировано в phpdoc метода.
- `key` в `resolveActive()` = `crafted_items.name_eng` как есть (join `crafted_items_log`→`crafted_items`). Маппинг на ключи профиля `world.vehicle.*` (`cart`/`mtb`/…) — не в scope этой story (рецепты появятся в transport-06); открытый вопрос уже зафиксирован в `plan.md`.
- Тест (`tests/unit/Transport/VehicleActivationServiceTest.php`) — изолированная схема (паттерн `LootProcessorTest`): свои `characters`/`crafted_items`/`crafted_items_log` в `wildworld_tests`. Пришлось добавить ещё и фикстуру `map` — `WipeService::resetCharacter()` безусловно вызывает `spawnCells()`, а в `wildworld_tests` таблицы `map` нет вообще (известный факт проекта, "мир не рендерится в PHPUnit"); без фикстуры `resetCharacter()` падал с "Table 'wildworld_tests.map' doesn't exist". Продакшн-код `WipeService` не менялся — это фикстура теста, `WipeManifest`/`WipeService`-манифест в самом тесте — анонимный подкласс `WipeManifest` с минимальным набором таблиц, не production-манифест (иначе `set()` на изолированной `characters`-таблице не найдёт ~28 продовых колонок).
- phpstan-грабля: raw `->get()` типизирован `ResultInterface|false` — `getRowArray()` напрямую на нём падает `method.nonObject`; поправлено явной проверкой `=== false` перед вызовом (везде в сервисе).

## Findings

Не потребовались — все три ревью-раунда (миграция/модель/манифест, сервис, тест) сошлись без стены.
