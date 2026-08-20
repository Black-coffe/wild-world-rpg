---
paths: ["app/Database/Migrations/**", "app/Models/**", "app/Config/WipeManifest.php"]
---
# Схема и данные

- Миграция, создающая таблицу или добавляющая player-связанную колонку, **обязана** сопровождаться
  классификацией в `App\Config\WipeManifest` (`KEEP` / `PLAYER_DATA` / `TRANSIENT` /
  `CHARACTER_RESET` / `IDENTITY_RESET` / `SEED_RESET`). Иначе падает
  `tests/unit/Config/WipeManifestCoverageTest` и блокирует деплой.
- Новая колонка обязана попасть в `$allowedFields` модели — без этого CI4 молча её не пишет.
- Колонки с эмодзи — `utf8mb4`, иначе INSERT рвётся только на живом рендере.
- Enum-колонки в STRICT-режиме валят INSERT при значении вне списка.
- Миграции исключены из phpstan: синтаксис проверяется `php -l` отдельной строкой.
- `first()` без `orderBy` возвращает произвольную строку, когда подходящих несколько.
- Builder CI4 держит состояние между вызовами: `where()->first()` в цикле накапливает условия —
  брать свежий `new Model()`.
- Seed-миграции советов и контента идемпотентны по английскому ключу (`title_en`).

## Имена сущностей: четыре разных конвенции в одной схеме

Колонки с названием сущности **не унифицированы**, и чтение строки БД по чужому ключу даёт тихий
`?? fallback` — игрок видит английское имя или пустую строку, тест зелёный, лог молчит
(инцидент 2026-08-20: экран верстака писал «BlastFurnace» вместо «Доменная печь»).

| Таблица | Русское имя | Английское имя |
|---|---|---|
| `buildings` | `name_ru` | `name_en` |
| `crafted_items` | `name_rus` | `name_eng` |
| `npcs` | `npc_name_ru` | `npc_name_en` |
| `events` | `name` | `name_english` |
| `tasks` | `name` | `name_rus` (перевёрнуто!) |
| `resources`, `weapons`, `outfits`, `world_objects` | `name` | `name_en` |
| `settlements` | `name_ru` | — (английского нет) |
| `biomes`, `factions`, `collections`, `titles` | `name` | — |

- 🔴 В `Config\Buildings` то же поле зовётся **`name_rus`**, а в таблице — **`name_ru`**. Строку
  `buildings` читать только через `BuildingModel::rusName($row, $nameEn)` — он знает оба ключа.
- Для остальных таблиц ключ подбирать по этой таблице, а не по памяти; появилось английское имя
  в player-facing тексте — это баг-сигнал тихого fallback'а, а не «забыли перевести».
- Новая таблица с названием сущности — брать `name_ru` / `name_en` (конвенция большинства),
  не плодить пятый вариант.
