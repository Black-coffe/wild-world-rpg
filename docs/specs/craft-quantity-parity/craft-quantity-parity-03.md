---
story: craft-quantity-parity-03
spec: craft-quantity-parity
status: done
tier: 1
worker: worker-code
tracer: false
wave: 1
blocked_by: []
---

# Совет: инструменты крафтятся партией

## Goal
В `game_tips` появляется один совет в категории «крафт», говорящий игроку, что на карточке инструмента
можно выбрать количество и скрафтить сразу несколько штук. Совет приезжает идемпотентной seed-миграцией.

## Requirements
> Tips-вердикт: да — один совет в категории «крафт» о том, что инструменты крафтятся партией.

## Files
- app/Database/Migrations/2026-09-06-120000_SeedCraftQuantityTip.php

## Non-goals
- НЕ создавать таблиц и НЕ добавлять колонок — только вставка строки, `Config\WipeManifest` трогать не нужно.
- НЕ писать в совет чисел баланса (стоимость, время, ступени количества): они дрейфуют, совет про навигацию.
- НЕ заводить второй совет и не переписывать существующие советы про крафт — если близкий по смыслу
  совет уже есть, дополни его формулировку, а не плоди дубль (проверить SELECT'ом по `game_tips` до вставки).
- НЕ трогать `GuideCatalog`: guide-вердикт по этой спеке — «нет».

## Map slice
`memory/map/onboarding.md` — «Совет дня», `TipService`, seed-паттерн советов; `memory/map/data-layer.md` — миграции.

## Acceptance criteria
- [ ] Миграция идемпотентна по уникальному английскому ключу `title_en` — повторный прогон не плодит строк.
- [ ] `tip_type` — валидное значение `крафт` из ENUM (иначе валидатор модели отклонит запись).
- [ ] Текст самодостаточен без картинки (media-off), markdown-safe: звёздочки парные, спецсимволы не ломают Telegram.
- [ ] Тон — Роби: коротко, по делу, без маркетинга; говорит, ГДЕ нажать, а не «стало удобнее».
- [ ] Эмодзи в тексте (если есть) безопасны для колонки — она utf8mb4, но проверить фактом, а не памятью.

## Verification
`git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`

## Implementation notes
- Файл: `app/Database/Migrations/2026-09-06-120000_SeedCraftQuantityTip.php`, класс `SeedCraftQuantityTip`, idempotent по `title_en='CraftQuantityBatch'`, `tip_type='крафт'`.
- Проверил существующие крафт-советы SQL-запросом к `game_tips` (локальная БД) — про выбор количества/партийный крафт ничего не найдено, дубля нет, добавил новый совет.
- Местный `game_tips.content` в моей локальной копии БД показал `utf8mb3` (`SHOW FULL COLUMNS`), но миграция `2026-05-22-300000_TipsAExtendCategories.php` уже конвертирует колонку в `utf8mb4` — локальная БД просто устаревший дамп (см. memory `reference_local_db_bootstrap_from_testbot`), на testbot/проде колонка уже utf8mb4.
- `GuideCatalog` не трогал (guide-вердикт «нет» из brief.md).

## Findings
