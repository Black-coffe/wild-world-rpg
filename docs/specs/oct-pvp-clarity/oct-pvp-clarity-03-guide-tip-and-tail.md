---
story: oct-pvp-clarity-03
spec: oct-pvp-clarity
status: done
returned: DONE
tier: 2
worker: worker-code
model: sonnet
wave: 2
blocked_by: [oct-pvp-clarity-01]
---

# Раздел «🛡 Нападение на базу» в `/guide` + совет Роби + хвост про потолки

## Goal
Раздел `standoff` в `GuideCatalog` объясняет, что решает исход у чужой базы, что южная зона закрыта для
нападений и где смотреть блок «почему так вышло». Новый совет Роби в категории «бой». Хвост «перенос
потолков PvP в GameSettings» — в `mmorpg-vault/wiki/hot.md`.

## Requirements
> По Q1–Q3 решить, нужны ли раздел в /guide и совет.
> 4. Раздел «🛡 Нападение на базу» расширен: что решает исход, южная зона, где смотреть блок.
> 5. Один совет Роби в категории «бой».
> 6. Перенос потолков в GameSettings записан хвостом в hot.md для отдельной спеки.

## Files
- app/Services/Onboarding/GuideCatalog.php
- app/Database/Migrations/2026-12-20-100000_SeedPvpBaseOutcomeTip.php
- tests/unit/Services/Onboarding/GuideCatalogTest.php

## Non-goals
- Нового раздела `/guide` не заводить — расширить `standoff`; раздел `teleport` про маяки не трогать (там уже «перехватить»).
- Никаких чисел баланса в тексте раздела и совета (ни 40 %, ни «Сила 100»).
- Не обещать «ломать чужие маяки» — в игре их перехватывают.
- Не трогать `combat` и другие разделы.

## Map slice
`memory/map/onboarding.md` → `/guide`, «Совет дня».

## Acceptance criteria
- [ ] Раздел `standoff`: абзац «что решает исход у чужой базы» (стена и ограда защитника, удачный удар более слабого, высокий уровень не гарантия), строка «в южной зоне нападать нельзя — и на базы тоже», указатель «📜 Разбор боя → 🧩 Почему так вышло». Markdown парный, ключ не меняется, read-only-инварианты `GuideCatalogTest` зелёные.
- [ ] Миграция совета идемпотентна по `title_en`, категория `бой`, тон Роби, без чисел баланса, не дублирует существующие советы про оборону (`Adr102SeedDefenseStackTip` и соседей проверить перед записью); `php -l` чист.
- [ ] `mmorpg-vault/wiki/hot.md`: хвост «PvP: потолки Силы (+10 % на 100) и разницы уровней (±5) в `Config\GameBalance` → GameSettings отдельной спекой, решение с кривой побед».
- [ ] Вердикт онбординга «нет — новой механики нет» записан в Implementation notes.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress && vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
`git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`

## Implementation notes

- **`/guide` — да, раздел `standoff` расширен** (ключ прежний): абзац «*Что решает исход у чужой базы.*» (стена,
  колючая ограда, вышка; высокий уровень не гарантия — Сила и разница уровней сверх потолков не прибавляют; удачный
  удар младшего), южная зона («если ты или противник стоите там, драки не будет — и на базы там тоже не нападают»,
  сверено с `PvPRestrictionService`: запрет, если любой из двоих в зоне), указатель «*📜 Разбор боя → 🧩 Почему так
  вышло*» — бот, `⚔️ Бои` на сайте и страница боя в хронике (с поправкой владельца про публичную страницу). Без цифр,
  слова «ломать» нет; `teleport` и `combat` не тронуты.
- **Совет — да, категория «бой»:** `SeedPvpBaseOutcomeTip`, `title_en = PvpBaseOutcome`, «🏰 Почему проиграл у чужой
  базы». Взгляд нападающего — соседи `DefenseStacking` (как строить оборону), `BaseStandoffAlert` (тревога),
  `BattleJournal` (где журнал) про другое, не дубль. Без цифр, `*` парные, без `_`; `php -l` чист. Новой таблицы нет —
  `game_tips` уже `KEEP` в `WipeManifest`. Применится `post-deploy.sh` на preprod.
- **Онбординг — нет:** новой механики нет, новое только объяснение на существующих экранах (разбор боя, `/guide`, совет).
- **hot.md:** хвост `todo_pvp_caps_to_gamesettings` — потолки Силы и уровней, удачный удар и удар насмерть из
  `Config\GameBalance`/кода → `GameSettings` отдельной спекой с кривой побед.
- Тесты: `GuideCatalogTest` +2 (раздел standoff: все формулировки, без цифр; миграция совета: категория,
  идемпотентность, без цифр, парные `*`).

## Findings
