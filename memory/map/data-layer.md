<!-- Срез-указатель, а не копия территории. Подробность — в mmorpg-vault; здесь только то,
     что нужно, чтобы понять, куда идти, и не вляпаться. Посеян обследованием дерева репозитория
     и конституцией проекта 2026-08-19; углубляется /vulyk-map <path> через drone-scout. -->
last-verified: 2026-08-19

# Scout report: Слой данных (модели, миграции, сущности)

## Purpose
Персистентность: 80 моделей CI4, 524 миграции, Entity-объекты, репозиторий, wipe-манифест.

## Entry points
- `app/Models/` — 80 моделей; `app/Entities/` — `CharacterEntity`, `BiomeEntity`, `ResourceEntity`,
  `BattleCharacter`, `BattleLogEntity`, `User`, `Traits/`.
- `app/Repositories/CI4CharacterRepository.php` + `Contracts/` — единственный репозиторий (raw SQL,
  без префиксов таблиц).
- `app/Database/Migrations/` — 524 файла; исключены из classmap и из phpstan.
- `app/Config/WipeManifest.php` — стратегия вайпа для каждой таблицы.

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
- В тестах время сеять как `NOW() - INTERVAL`, а не `date()` в PHP — иначе окна плывут.
- Миграции phpstan не смотрит: синтаксис проверяется `php -l` (см. `## Commands` в конституции).
- Локальная база поднимается **дампом с testbot**: прогон миграций с нуля не проходит.
- `tests/exploit-poc/` (testsuite `exploit-poc`, `phpunit.xml.dist`) — 8 файлов делят таблицы
  общей `wildworld_tests`; никогда не `DROP` таблицу, которую не создавал сам файл; гонять
  по одному файлу (`--testsuite App` её исключает); схема — из миграций, не вручную (реальные
  `characters` не несут `armor`/`max_health`).

## Vault
`mmorpg-vault/tech-writing/models/` · `mmorpg-vault/tech-writing/db/` · ADR-087
