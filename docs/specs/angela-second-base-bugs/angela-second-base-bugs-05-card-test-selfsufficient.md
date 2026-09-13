---
story: angela-second-base-bugs-05
spec: angela-second-base-bugs
status: done
tier: 3
worker: worker-test
tracer: false
wave: 2
blocked_by: []
---

# Тест карточек зданий строит свою схему сам и не шимит `fopen` всему процессу

## Goal
`tests/unit/Camp/BuildingCardBaseScopeTest.php` перестаёт быть доказательством ни о чём: он
проходит при ОДИНОЧНОМ прогоне, а не только когда нужные таблицы случайно создал кто-то другой
в полном наборе, и перестаёт подменять `fopen()` в пространстве `Longman\TelegramBot` на весь
процесс PHPUnit. После истории доказательство истории 02 (четырнадцать карточек читают свою базу)
снова существует.

## Requirements
> Экран-карточка здания на базе показывает уровень и состояние постройки ТОЙ базы, в клетке которой стоит игрок. Проверяемо: при одном типе здания на двух базах карточка на каждой базе показывает уровень строки `character_buildings` с соответствующим `map_cell_id`, а не чужой.
> Зелёные гейты: `vendor/bin/phpunit --no-coverage --no-progress` и `vendor/bin/phpstan analyse --memory-limit=512M --no-progress`. Tech-writing ноты в `mmorpg-vault/tech-writing/` обновлены для каждой тронутой сущности.

## Files
- tests/unit/Camp/BuildingCardBaseScopeTest.php
- tests/unit/Player/BuildingUpgradeBaseScopeTest.php

## Что сломано (проверено прогоном главной сессии, искать не надо)
- `vendor/bin/phpunit --no-coverage --no-progress tests/unit/Camp/BuildingCardBaseScopeTest.php`
  в одиночку даёт 3 ошибки из 4: `Unknown column 'locale'` в `characters`,
  `Unknown column 'name_ru'` в `buildings`. Причина — `createTableIfMissing`: таблица с таким
  именем в общей тест-БД уже есть (её создал чужой тест со своей, другой схемой), и метод
  принимает чужую таблицу за свою. Зелёным файл бывает только внутри полного набора.
- Шим `namespace Longman\TelegramBot { function fopen(...) }` объявлен на уровне файла и живёт
  до конца процесса: любой тест, загруженный после этого файла и дошедший до
  `Request::encodeFile()` с http-URL, получает подмену.
- Попутно (дёшево, поэтому здесь, а не отдельной историей): в
  `tests/unit/Player/BuildingUpgradeBaseScopeTest.php` заявлено «схема 1:1 с миграциями», но это
  не так — живой `character_buildings.building_type` это ENUM со значением `'defensive'` и
  `NOT NULL`. Привести схему к миграции либо убрать неверное заявление из комментария.

## Как чинить (границы решения)
- Схему брать ИЗ миграций (`app/Database/Migrations/*CreateCharacterBuildingsTable*`,
  `*CreateClaimedCellsTable*`, `characters`, `buildings` и их `Add*`-довески), а не писать
  по памяти: ручной `CREATE TABLE` уже расходился с продом и держал семь тестов зелёными зря.
- Гарантировать свою схему. Рабочий образец уже есть в этой же спеке — приватный префикс
  таблиц (`bubs_*` в `BuildingUpgradeBaseScopeTest`), полностью изолированный от общих таблиц.
  Если по каким-то причинам берутся общие имена — тест ОБЯЗАН проверить пригодность чужой
  таблицы и упасть с внятной диагностикой («таблица X существует, но в ней нет колонки Y —
  её создал другой тест»), а не молча работать с чужой схемой.
- Шим `fopen` ограничить этим тестом: либо перестать ходить в `encodeFile` вовсе (подменить
  транспорт/отправку), либо доказать безразличие для остальных, назвав поимённо тесты, которые
  тоже идут через `Request::encodeFile()` с http-URL. Формулировка «наверное, никто больше» —
  не доказательство.

## Non-goals
- Не трогать прод-код: ни один файл под `app/` в `## Files` не входит. Если тест показывает
  дефект прод-кода — это находка для `## Findings` и сообщение Queen, а не правка здесь.
- Не ослаблять проверки, чтобы стало зелёно: тест бьёт по реальному `handle()` четырнадцати
  карточек, а не по подстроке в исходнике. Скан исходника оставит сломанный метод зелёным.
- Не закреплять в тесте текст отказа для случая «у персонажа НЕТ активных баз»: этот текст
  меняет история 07. Текст отказа для случая «баз несколько, игрок не на базе» закреплять можно —
  он остаётся прежним.
- Не переносить и не переименовывать файлы тестов, не трогать `phpunit.xml.dist`.
- Не «чинить заодно» чужие тесты, которые тоже страдают от общей тест-БД: здесь ровно два файла.

## Map slice
`memory/map/tests.md` (стенд, общая тест-БД), `memory/map/bases.md` (`character_buildings`,
`claimed_cells`). Правила: `.claude/rules/db-schema.md` (DB-тест строит схему сам, CI гоняет
на ПУСТОЙ базе без миграций).

## Acceptance criteria
- [x] `vendor/bin/phpunit --no-coverage --no-progress tests/unit/Camp/BuildingCardBaseScopeTest.php`
      зелёный при ОДИНОЧНОМ прогоне, на машине, где общую тест-БД до этого никто не трогал.
- [x] Тот же файл зелёный и внутри полного набора.
- [x] Если в БД оказалась чужая таблица с неподходящей схемой — тест падает с сообщением,
      называющим таблицу и недостающую колонку, а не с `Unknown column` из недр драйвера.
- [x] Подмена `fopen()` не действует на тесты, загруженные после этого файла (либо в story
      названы поимённо все тесты, идущие через `Request::encodeFile()` с http-URL, и показано,
      почему подмена для них безразлична).
- [x] Проверка сути истории 02 сохранена: две базы, одно и то же здание разных уровней,
      карточка на каждой базе показывает свой уровень.
- [x] Заявление про «схему 1:1 с миграциями» в `BuildingUpgradeBaseScopeTest.php` стало правдой
      (`building_type` — ENUM с `'defensive'`, `NOT NULL`) либо снято.
- [x] `vendor/bin/phpunit --no-coverage --no-progress` зелёный целиком.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`

## Предупреждения воркеру
- Не трогать `phpstan-baseline.neon`.
- НИКОГДА `git stash` / `git checkout`; старый вариант файла — `git show HEAD:<path>`.
- Не запускать полный набор параллельно с другими агентами волны — DB-тесты дерутся за общие
  таблицы, и чужой прогон даст сотни ложных ошибок.
- Никаких деструктивных операций с общей локальной тест-БД: ни `DROP DATABASE`, ни
  `php spark migrate`, ни массового `DROP TABLE` «чтобы стало чисто». Своя схема — свои имена.

## Implementation notes

**Корневая причина, подтверждённая прогоном (не гипотеза):** на этой машине `wildworld_tests`
уже нёс мусор от прошлых оборванных прогонов — реальные `telegram_users`/`buildings`/
`character_buildings` с чужой схемой (без `locale` у `characters`, без `name_ru` у `buildings`).
`createTableIfMissing()` видел их через `tableExists($t, false)` как «уже есть» и тихо работал
поверх чужой схемы → `Unknown column` из недр драйвера уже на первом INSERT, дальше — каскад
(«`buildings` doesn't exist» на второй тест-метод — побочный эффект оборванного первого).

**Фикс — приватный префикс через `BaseConnection::setPrefix()`, а не смена имён таблиц.**
`HandPumpHandler`/`WarehouseHandler` идут через реальные модели с зашитыми именами
(`characters`, `buildings`, ...) — переименовать их в тесте нельзя (не то что в
`BuildingUpgradeBaseScopeTest`, где модели подставные, table подменяется через анонимный
подкласс). Вместо этого `setUp()` вызывает `$this->db()->setPrefix('bcbs_')` на group `tests` —
CI4 query builder ЛЮБОЙ запрос `->table('characters')->insert(...)` (и внутри хендлера, и внутри
хелперов теста) транслирует в `bcbs_characters` на уровне SQL, без единой правки в моделях/
хендлерах. `tearDown()` возвращает исходный префикс. `tableExists()`/сырой `query()` (DDL, DROP)
префикс сами не подставляют (проверено локальным прогоном — `_listTables()` использует
буквальное имя), поэтому DDL и дроп используют явное `self::PREFIX . $table`.

Раз `bcbs_*` — имя, которым не пользуется больше ни один тест, «чужой таблицы с несовместимой
схемой» здесь структурно не может возникнуть: `createOwnTable()` всегда `DROP TABLE IF EXISTS` +
`CREATE` (единственный риск — мусор от аварийно прерванного прошлого прогона ЭТОГО ЖЕ файла, что
безопасно дропнуть). После создания каждой таблицы — самопроверка обязательных колонок
(`getFieldNames()` vs список, который реально трогает `handle()`); расхождение — `RuntimeException`
с именем таблицы и недостающей колонки, а не сырой `Unknown column` из драйвера (AC3 выполняется
конструктивно, а не только «на всякий случай»).

**Шим `fopen()` не убран (не может быть — PHP регистрирует namespaced-функцию на весь процесс
после первого `require` файла, объявление нельзя ни удалить, ни ограничить областью видимости),
но его ПОВЕДЕНИЕ гейтится статическим флагом** `BuildingCardBaseScopeTest::$fopenShimActive`,
включаемым в `setUp()` и выключаемым в `tearDown()` (в `finally`). Пока флаг `false` — функция
прозрачно делегирует в `\fopen()`, неотличимо от отсутствия шима: тесты, загруженные до/после/
вперемешку с этим файлом, не видят подмены.

**`BuildingUpgradeBaseScopeTest`:** `building_type` ENUM `bubs_character_buildings` был без
`'defensive'` и без `NOT NULL`, хотя `2026-05-20-600000_S26AddDefensiveStructures.php` уже
`ALTER MODIFY ... NOT NULL` с `defensive` в живой миграции. Приведено к факту (ENUM с
`defensive` + `NOT NULL`), комментарий над классом дополнен ссылкой на эту миграцию.

**Верификация:** `BuildingCardBaseScopeTest` — зелёный standalone 4 раза подряд (включая три раза
до финальной фиксации в рамках отладки корневой причины) и внутри `tests/unit/Camp/
BuildingCardBaseScopeTest.php tests/unit/Player/BuildingUpgradeBaseScopeTest.php` вместе (11
тестов, 31 assertion). Полный набор — `vendor/bin/phpunit --no-coverage --no-progress`: 4107
tests, 32640 assertions, 0 failures/errors (только 10 skipped + deprecations), exit code 0, один
прогон (полный набор дорог и делит DB с параллельными агентами волны — прогнан один раз).
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress` (без явных путей — штатный гейт,
`paths: [app]`, `tests/` вне анализа) — чисто.

## Findings

Диагностический временный файл `tests/unit/Camp/ZZZDebugScratchTest.php` создавался для
локализации корневой причины (реальные табличные состояния БД внутри процесса PHPUnit) и удалён
до финальной фиксации — не входит в `## Files`, не коммитится.
