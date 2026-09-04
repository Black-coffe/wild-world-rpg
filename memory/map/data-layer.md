<!-- Срез-указатель, а не копия территории. Подробность — в mmorpg-vault; здесь только то,
     что нужно, чтобы понять, куда идти, и не вляпаться. Посеян обследованием дерева репозитория
     и конституцией проекта 2026-08-19; углубляется /vulyk-map <path> через drone-scout. -->
last-verified: 2026-09-04

# Scout report: Слой данных (модели, миграции, сущности)

## Purpose
Персистентность: 80 моделей CI4, 524 миграции, Entity-объекты, репозиторий, wipe-манифест.

## Entry points
- `app/Models/` — 80 моделей; `app/Entities/` — `CharacterEntity`, `BiomeEntity`, `ResourceEntity`,
  `BattleCharacter`, `BattleLogEntity`, `User`, `Traits/`.
- `app/Repositories/CI4CharacterRepository.php` + `Contracts/` — единственный репозиторий (raw SQL,
  без префиксов таблиц).
- `app/Database/Migrations/` — 524+ файла; исключены из classmap и из phpstan.
- `app/Config/WipeManifest.php` — стратегия вайпа для каждой таблицы.
- **`app/Services/Db/`** (2026-09, ADR-181) — `ConditionalWriteService` (`decrementIfAtLeast`,
  `transitionIfCurrent`, `increment`, `insertUnique` — три исхода `WriteOutcome`, не `bool`) и
  `NamedLock` (`GET_LOCK` non-blocking, `withLock()`). Единый дом правила «списание — условный
  `UPDATE` с проверкой `affectedRows`, не read-then-write». Подробность —
  `mmorpg-vault/tech-writing/services/ConditionalWriteService.md`.

## Key types / contracts
Шесть стратегий вайпа: `KEEP`, `PLAYER_DATA`, `TRANSIENT`, `CHARACTER_RESET`, `IDENTITY_RESET`,
`SEED_RESET`. Каждая таблица ровно в одной.

## Dependencies
inbound: все сервисы и handler'ы.
outbound: MySQL / MariaDB.

## Gotchas
- **Новая колонка обязана попасть в `$allowedFields`** — иначе CI4 молча её не пишет.
- Builder держит состояние: `where()->first()` в цикле накапливает условия — брать `new Model()`.
- `increment()` на кэше CI4 продлевает TTL: окно, отсчитываемое по TTL, не закрывается никогда.
- Эмодзи-колонки обязаны быть `utf8mb4`.
- `first()` без `orderBy` врёт, когда подходящих строк несколько.
- **`strictOn = false`** (`app/Config/Database.php`, группы `default` и `tests`) — CI4 снимает
  `STRICT_TRANS_TABLES`/`STRICT_ALL_TABLES` из `sql_mode` на каждом коннекте, даже когда сервер
  строгий. Пропущенная `NOT NULL`-колонка без `DEFAULT` в приложении не бросает исключение — MySQL
  молча подставляет implicit default. Проектный дефект вне exploit-fix (хвост в `FIX-BACKLOG.md`);
  `ConditionalWriteService::insertUnique()` документирует это явно в своём докблоке.
- **`ConditionalWriteService::insertUnique()` — self-reference на первую колонку `$row`**, не на
  литеральный `id`: таблицы без суррогатного `id` (например `telegram_updates_seen`) иначе получили
  бы `Unknown column 'id'`. `ON DUPLICATE KEY UPDATE` на уже существующей строке берёт X-lock и
  сжигает `AUTO_INCREMENT` — не для горячего пути «строка почти всегда уже есть».
- Дубль внутри чужой транзакции у `insertUnique()` **не** портит `transStatus` (форма ODKU, не
  перехват 1062-исключения) — иначе штатный `Refused` незаметно обрекал бы всю транзакцию вызывающего
  на откат в `transComplete()`.
- В тестах время сеять как `NOW() - INTERVAL`, а не `date()` в PHP — иначе окна плывут.
- Миграции phpstan не смотрит: синтаксис проверяется `php -l` (см. `## Commands` в конституции).
- Локальная база поднимается **дампом с testbot**: прогон миграций с нуля не проходит.
- `tests/exploit-poc/` (testsuite `exploit-poc`, `phpunit.xml.dist`) — 8 файлов делят таблицы
  общей `wildworld_tests`; никогда не `DROP` таблицу, которую не создавал сам файл; гонять
  по одному файлу (`--testsuite App` её исключает); схема — из миграций, не вручную (реальные
  `characters` не несут `armor`/`max_health`).
- Упавший запрос ВНУТРИ уже открытой транзакции (`transDepth>0`) CI4 не бросает исключением —
  глотает молча и выставляет `transStatus=false` на ОБЩЕМ дефолтном соединении теста (кэшируется
  по группе). `transBegin()` не сбрасывает `transStatus` при старте нового блока — флаг держится
  `false` до конца процесса PHPUnit, и любой следующий тест того же файла/процесса, идущий через
  `transStart()/transComplete()`, тихо уходит в rollback-ветку. Фикстуры обязаны строить каждую
  таблицу, которую трогают (включая ту, что кажется «наверняка уже есть» на CI-базе).
- Миграция `2024-03-21-224528_CreateResourcesTable.php` не проходит на пустой БД: `biome_id`
  объявлен `TEXT` + `unsigned=>true`, CI4 `Forge` безусловно приписывает `UNSIGNED` любому типу —
  `TEXT UNSIGNED` MySQL 8 отклоняет. Падает и на голом `php spark migrate`, не только в тестах.
- **CI4 `transRollback()` на глубине >1 только декрементирует счётчик — нет savepoint'ов.**
  Вложенный `transBegin()` внутри уже открытой транзакции вызывающего не создаёт точку отката:
  откат внутренней «транзакции» физически не отменяет уже выполненные `UPDATE`/`INSERT` этого
  вложенного блока, только уменьшает `transDepth`. `resetTransStatus()` поэтому обязан звать
  **только владелец транзакции верхнего уровня** (глубина была 0 до своего `transBegin()`/
  `transStart()`) — иначе вложенный вызов стирает флаг отказа, принадлежащий внешней транзакции,
  которая ещё не решила, откатываться ей или нет (`ResourceBankUpdateHandler::process()` —
  exploit-fix-35/39, `ResourceTradeService::buyResource()` — exploit-fix-40, оба cargo-дрона —
  exploit-fix-36/41: везде один и тот же паттерн — `$topLevel = ($db->transDepth === 0)` до
  открытия своей транзакции, `resetTransStatus()` только `if ($topLevel)`).

## Vault
`mmorpg-vault/tech-writing/models/` · `mmorpg-vault/tech-writing/db/` · ADR-087 · ADR-181
(`mmorpg-vault/tech-writing/services/ConditionalWriteService.md`,
`mmorpg-vault/tech-writing/db/{telegram_updates_seen,resources_bank}.md`)
