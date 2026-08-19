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
