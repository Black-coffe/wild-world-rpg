---
story: pvp-detection-clarity-24
spec: pvp-detection-clarity
status: done
tier: 1
worker: worker-test
tracer: false
wave: 8
blocked_by: []
---

# Тест вышки строит схему миграциями и не сносит чужое

## Goal

После story тест Дозорной вышки строит схему из настоящих классов миграций, как остальные тесты
ветки, и не удаляет таблицы, которых сам не создавал. Проверки аудита и экранирования перестают
быть зелёными в случае, когда реальная вставка на проде отвалилась бы по констрейнту.

## Requirements

> BLOCK-3, major 2: тест вышки обязан строить схему из тех же классов миграций, что и остальные новые тесты ветки, и не удалять таблицы, которые не создавал: он `DROP TABLE` семь общих таблиц и пересоздаёт их руками, причём `telegram_users` и `action_log` в этот список добавила именно эта ветка. Рукописная `action_log` — все колонки nullable и без FK, тогда как миграция даёт `character_id`/`chat_id` NOT NULL + FK: новые тесты на аудит и HTML-экранирование останутся зелёными, даже если реальная вставка на проде отвалится по констрейнту. Плюс прогон файла на общей `wildworld_tests` эти таблицы уничтожает.

> Правило проекта: DB-тест строит свою схему из настоящих классов миграций, потому что CI работает на пустой базе (`.claude/rules/tests-db.md`).

## Files
- tests/database/TowerAlertServiceTest.php

## Non-goals
- Не менять `app/Services/PVE/TowerAlertService.php` и вообще ничего в `app/` — если после перехода на миграционную схему тест покажет РЕАЛЬНЫЙ дефект сервиса (вставка не проходит констрейнт), остановись и доложи: это находка, а не повод править сервис в тестовой story.
- Не трогать `tests/database/RelocateAbandonedCharactersTest.php` — он уже приведён в порядок story `-15`/`-22` и служит здесь образцом приёма (`$created`-флаги, `down()` в обратном порядке FK, уборка при падении `setUp()`).
- Не ослаблять существующие проверки теста ради того, чтобы он прошёл на строгой схеме: если проверка падает на NOT NULL или FK — это и есть то, ради чего story делается.
- Не запускать полный набор; не делать `DROP` / `migrate` на общей `wildworld_tests`; не делать `git stash` / `git checkout`.

## Map slice

`tests/database/TowerAlertServiceTest.php:32-70` — `DROP TABLE` семи общих таблиц и рукописное
пересоздание; `telegram_users` и `action_log` в этом списке добавлены текущей веткой;
образец правильного приёма — `tests/database/RelocateAbandonedCharactersTest.php`
(`buildSchema()`, `$created`, `dropTrackedTables()`, уборка при падении `setUp()`).

## Acceptance criteria
- [ ] Схема строится из настоящих классов миграций; рукописных `CREATE TABLE` в файле не остаётся.
- [ ] Тест удаляет только те таблицы, которые создал сам: таблица, существовавшая до него, переживает прогон. Докажи это — положи в базу постороннюю таблицу и убедись, что после прогона она на месте.
- [ ] После прогона на свежей пустой базе `SHOW TABLES` пуст — мусора не остаётся, в том числе при падении `setUp()` посередине.
- [ ] Тест зелёный в паре с `RelocateAbandonedCharactersTest` в обоих порядках и с остальными соседями по `map`, названными вторым проходом ревью (`DemolishBuildingTest`, `AchievementServiceTest`, `BuildingBaseBindingTest`, `PlayerRespawnerTest`).
- [ ] Если на строгой схеме (`character_id` / `chat_id` NOT NULL + FK) какая-то вставка перестала проходить — это НЕ чинится ослаблением теста: доложи находку, опиши, какой реальный путь отвалился бы на проде.

## Verification

`vendor/bin/phpunit --no-coverage --no-progress tests/database/TowerAlertServiceTest.php tests/database/RelocateAbandonedCharactersTest.php`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes

Схема теперь строится из настоящих классов миграций тем же приёмом, что
`RelocateAbandonedCharactersTest`/`StandoffAttackGateTest`: `telegram_users`,
`characters`, `buildings`, `factions`, `map`, `game_settings`, `character_buildings`,
`action_log` — каждая создаётся только если отсутствует (`createIfMissing`,
`$created[...]`), дропаются в `tearDown()` в обратном FK-порядке только те, что
создал сам прогон; уборка происходит и при падении `setUp()` посередине
(`try/catch` вокруг построения схемы, как в `-22`). Рукописных `CREATE TABLE` в
файле не осталось (кроме двух намеренных однострочных DDL для тестовых сценариев
самих acceptance-критериев #2/#3, не части рабочей схемы).

`character_buildings.building_type` ENUM расширен до `'defensive'` тем же raw
`ALTER TABLE ... MODIFY` DDL, что уже применяют `StandoffAttackGateTest`/
`PvpStandoffServiceTest`/`DefenseStructureServiceTest` в этой ветке — полноценная
миграция `S26AddDefensiveStructures` тянет `tasks.handler_key` →
`AddHandlerKeyToEventsTasksWorldObjects` → таблицы `events`/`world_objects`,
что вне поверхности этого файла. Содержимое строки `buildings` для WatchTower
скопировано из `S26bAddWatchTower` (реальные production-значения), GameSettings-
ключи `defense.tower.*` заведены настоящей `S26bSeedWatchTowerGameSettings` (без
посторонних зависимостей, гоняется как есть, `down()` в `tearDown()`).

Verification: `env "database.tests.database=<своя>" vendor/bin/phpunit --no-coverage
--no-progress tests/database/TowerAlertServiceTest.php` — 12/12 зелёных на свежей
пустой БД (`SHOW TABLES` пуст и до, и после). В паре с
`RelocateAbandonedCharactersTest` в обоих порядках — 17/17 зелёных, без остатка
таблиц. Полной пятёркой из ревью (`TowerAlertServiceTest`, `DemolishBuildingTest`,
`AchievementServiceTest`, `BuildingBaseBindingTest`, `PlayerRespawnerTest`) — 64/64
зелёных в обоих порядках, `SHOW TABLES` пуст после каждого прогона.
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress` — без ошибок.

## Findings

**Два реальных дефекта фикстур теста, не сервиса** (строгая схема их и должна
была вскрыть — ради этого story делалась):

1. **Владелец вышки не существовал как персонаж.** `placeTower($ownerId, ...)`
   заводил строку `character_buildings` с `character_id = $ownerId`, но нигде
   не создавал самого владельца в `characters` — на рукописной (без FK) схеме это
   молча проходило, на реальной (`character_buildings.character_id` — FK CASCADE
   на `characters.id`) вставка упала бы констрейнтом. Это дыра в фикстуре, не в
   `TowerAlertService` — сервис ничего не создаёт, только читает. Починено:
   `placeTower()` сам заводит владельца (`ensureCharacterRow`), если его ещё нет.

2. **`character_buildings.map_cell_id` — реальный FK на `map.id`, а не на
   `map.cell_number`.** `TowerAlertService::towersInBox()` джойнит
   `m.cell_number = cb.map_cell_id` — то есть код и раньше, и сейчас трактует
   `map_cell_id` как значение `cell_number`. Реальная миграция
   (`CreateCharacterBuildingsTable`) при этом объявляет FK буквально на
   `map.id`. Это не противоречие в проде: там `map.id == map.cell_number` для
   каждой строки (инвариант, уже задокументированный в
   `RelocateAbandonedCharactersTest`/`ClaimedCellModel`) — FK формально ссылается
   на `id`, а код читает то же число как `cell_number`, потому что они всегда
   совпадают. Рукописная схема теста этот инвариант не поддерживала (`map.id`
   — обычный auto_increment, не равный `cell_number`), поэтому вставка вышки на
   произвольную клетку валилась FK-констрейнтом. Починено: `placeTower()` заводит
   `map`-строку с explicit `id = cell_number`, как остальные миграционные тесты
   ветки. Сам `TowerAlertService` не трогался и трогать не нужно — его код
   корректен ровно потому, что полагается на этот же инвариант, на котором стоит
   вся остальная игра (базы, клеймы, respawn).

Оба пункта — фикстуры, не сервис; в `app/` ничего не менялось.
