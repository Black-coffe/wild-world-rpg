---
story: pvp-detection-clarity-15
spec: pvp-detection-clarity
status: done
tier: 1
worker: worker-test
tracer: false
wave: 6
blocked_by: []
---

# Тест расселения не мешает соседям пересоздавать карту

## Goal

После story полный набор на ПУСТОЙ базе (как его гоняет CI) остаётся зелёным: тест расселения
брошенных персонажей не оставляет после себя схему, из-за которой соседний тест не может
пересоздать `map`.

## Requirements

> BLOCK, критично #3: `tests/database/RelocateAbandonedCharactersTest.php:70` создаёт `claimed_cells` настоящей миграцией (с FK на `map`) и намеренно никогда её не дропает, а `tests/database/TowerAlertServiceTest.php:34` делает `DROP TABLE map`. Воспроизведено на пустой БД: `phpunit tests/database/RelocateAbandonedCharactersTest.php tests/database/TowerAlertServiceTest.php` → 10 errors, `Cannot drop table 'map' referenced by a foreign key constraint 'claimed_cells_map_cell_id_foreign'`. Порядок в наборе алфавитный, R идёт раньше T.

> Проверено, что это новое: те же пары с `DemolishBuildingTest`, `AchievementServiceTest`, `BuildingBaseBindingTest`, `PlayerRespawnerTest` на чистой БД зелёные.

## Files
- tests/database/RelocateAbandonedCharactersTest.php

## Non-goals
- Не трогать `tests/database/TowerAlertServiceTest.php` — он существовал до этой ветки и его поведение не регресс; чинить надо того, кто пришёл последним.
- Не трогать `app/Commands/RelocateAbandonedCharacters.php` — сама команда работает, претензия только к следам теста.
- Не «чинить» проблему переименованием тест-класса ради алфавитного порядка: порядок в наборе не контракт, и следующий сосед на `map` сломается снова.
- Не отключать FK-проверки глобально в bootstrap тестов — это спрячет класс ошибки, а не устранит его.
- Не запускать полный набор на общей `wildworld_tests`; не делать `git stash` / `git checkout`.

## Map slice

`tests/database/RelocateAbandonedCharactersTest.php:70` — создание `claimed_cells` из класса
миграции; `tests/database/TowerAlertServiceTest.php:34` — `DROP TABLE map`;
`.claude/rules/tests-db.md` — правило «DB-тест строит свою схему из настоящих классов миграций»,
которое здесь остаётся в силе и отменять его не нужно.

## Acceptance criteria
- [x] На ПУСТОЙ изолированной базе (создать и удалить самому) команда `vendor/bin/phpunit --no-coverage --no-progress tests/database/RelocateAbandonedCharactersTest.php tests/database/TowerAlertServiceTest.php` зелёная — ровно та пара и в том порядке, что дала 10 ошибок.
- [x] Тест расселения по-прежнему строит свою схему из настоящих классов миграций, а не рукописным `CREATE TABLE` — правило `.claude/rules/tests-db.md` не нарушается ради удобства.
- [x] Проверено, что зелёными остаются и остальные соседи по `map`, названные в находке: `DemolishBuildingTest`, `AchievementServiceTest`, `BuildingBaseBindingTest`, `PlayerRespawnerTest` — каждый в паре с тестом расселения.
- [x] В `## Findings` сказано, каким способом снят конфликт и почему выбран именно он, чтобы следующий DB-тест на `map` не наступил на то же.

## Verification

`vendor/bin/phpunit --no-coverage --no-progress tests/database/RelocateAbandonedCharactersTest.php tests/database/TowerAlertServiceTest.php`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes

`RelocateAbandonedCharactersTest` раньше создавал `telegram_users`/`characters`/`map`/
`claimed_cells`/`action_log` только если их не было, и никогда не дропал (комментарий класса
прямо это утверждал). `claimed_cells` создаётся настоящей миграцией `CreateClaimedCellsTable`
с реальным FK `claimed_cells_map_cell_id_foreign` на `map`. Раз оставленная лежать, эта таблица
блокирует любой сосед по набору, который переиспользует `map` через рукописный
`DROP TABLE IF EXISTS map` (так делает `TowerAlertServiceTest`).

Правка — только в `tests/database/RelocateAbandonedCharactersTest.php`:
- в `setUp()` каждое `if (! tableExists(...))` теперь пишет флаг в `array<string,bool> $created`
  ДО вызова `up()`, а не только решает, вызывать ли `up()`;
- в `tearDown()` для каждой таблицы, если `$created[<table>]` истинен, вызывается `down()` того
  же класса миграции — **не** рукописный `DROP TABLE`, значит правило `.claude/rules/tests-db.md`
  («схема строится и разбирается настоящими классами миграций») не нарушено;
- порядок `down()` — обратный FK-графу: `action_log` → `claimed_cells` → `characters` → `map` →
  `telegram_users` (`action_log`/`claimed_cells` ссылаются на `characters`, `claimed_cells` — ещё
  и на `map`, `characters` — на `telegram_users`);
- если таблица уже существовала до этого теста (например, обжитой локальный стенд или другой
  DB-тест класс её уже создал и не подчистил) — `$created[<table>] = false`, и `tearDown()` её не
  трогает. Это тот же паттерн, что уже используют `DemolishBuildingTest`/`StandoffAttackGateTest`
  (`createIfMissing()` + условный `down()`/`DROP TABLE IF EXISTS`) — не новое изобретение, а
  приведение этого теста к уже устоявшейся в кодовой базе конвенции.

Почему не другие варианты:
- Дроп `claimed_cells` только в `tearDownAfterClass()` (один раз на класс, а не на каждый тест)
  выглядел экономичнее по перформансу, но давал асимметрию с уже принятой конвенцией
  (`DemolishBuildingTest`/`StandoffAttackGateTest` дропают за тестом, не за классом) и осложнял
  бы аудит: соседний DB-тест-класс видел бы разное поведение в зависимости от того, читает он
  код `RelocateAbandonedCharactersTest` или `DemolishBuildingTest`. Per-test дроп — на 4 теста в
  классе это +4 цикла create/drop (~1.2 сек суммарно, замерено) — цена признана приемлемой ради
  единообразия.
- Переименование класса ради алфавитного порядка — прямо запрещено в `## Non-goals`, и не решает
  проблему в принципе (соседний тест на `map` сломается снова с любым другим соседом по FK).
- Глобальное отключение FK-проверок в bootstrap — прямо запрещено, прячет класс ошибки целиком.

## Findings

Конфликт снят точечно: `claimed_cells` — единственная таблица, которую этот тест создаёт
настоящей FK-миграцией и оставлял лежать навсегда. Теперь она (и остальные 4 таблицы) дропаются
`down()`-методом соответствующего класса миграции в `tearDown()`, если созданы этим же тестом
(флаг `$created[...]`), в порядке, обратном FK-графу. Для следующего DB-теста, которому нужен
`map` (или любая другая таблица с входящим FK) в рукописной DROP/CREATE-схеме: если он **создаёт**
общую таблицу через настоящую FK-миграцию (не рукописный DDL) — обязан дропать её через `down()`
той же миграции в `tearDown()`, условно по флагу «создал ли её этот тест», как показано здесь и
в `DemolishBuildingTest`/`StandoffAttackGateTest`. Иначе первый же сосед, который трогает
референсную таблицу рукописным DDL, падает на пустой БД тем же классом ошибки.

Проверено на СВЕЖИХ изолированных базах (создавались и удалялись через MySQL CLI, не переиспользовал
`wildworld_tests`): `RelocateAbandonedCharactersTest` + `TowerAlertServiceTest` — 14 тестов, 0 ошибок;
паровка с `DemolishBuildingTest` (15 тестов), `AchievementServiceTest` (26), `BuildingBaseBindingTest`
(13), `PlayerRespawnerTest` (14) — все зелёные, 0 ошибок в каждой паре. `RelocateAbandonedCharactersTest`
в одиночку на свежей базе — 4/4 зелёных, после прогона в схеме не осталось ни одной таблицы (проверено
`SHOW TABLES`).
