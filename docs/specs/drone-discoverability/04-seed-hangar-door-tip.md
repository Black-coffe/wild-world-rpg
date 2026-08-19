---
story: drone-discoverability-04
spec: drone-discoverability
status: done
tier: 1
worker: worker-code
tracer: false
wave: 1
blocked_by: []
---

# Совет: где живёт техника и как её запустить

## Goal
В `game_tips` появляется совет от лица Роби, который называет ТОЧНЫЙ путь к дрону:
🏠 База → 🤖 Ангар → 🚁 Разведчик, и упоминает вторую дверь — кнопку «🚁 Дрон» на карте.
Идемпотентная seed-миграция по уникальному `title_en`.

## Requirements
> «да» — совет о том, где живёт техника (категория `общие`),
> идемпотентная seed-миграция по `title_en`. Повод в том, что путь к дрону
> оказался неочевиден живому игроку.

## Files
- app/Database/Migrations/2026-08-19-210000_Adr058SeedDroneDoorTip.php

## Non-goals
- НЕ трогать `GameTipsModel`, `TipService`, `DailyTipBroadcastHandler` и настройки рассылки.
- НЕ заводить новую таблицу и не менять схему — только INSERT в `game_tips`.
- НЕ писать в совет числа баланса (радиус/заряд/минуты) — они дрейфуют; совет про
  навигацию и понятия.
- НЕ дублировать существующий совет: сперва прочитать соседние seed-миграции советов про
  дронов, и если совет про дрон-разведчика уже есть — новый писать под другим ракурсом
  (где дверь), не повторяя его.

## Map slice
Образцы паттерна: `app/Database/Migrations/2026-05-29-800000_W2SeedDroneScoutTip.php`,
`app/Database/Migrations/2026-08-19-140100_Adr172SeedDroneInsuranceTip.php`.

## Acceptance criteria
- [ ] `up()` идемпотентен: повторный прогон не создаёт дубль (проверка по `title_en`).
- [ ] `down()` удаляет строго свою строку по `title_en`.
- [ ] `tip_type` — валидное значение из 14 ENUM (`общие`).
- [ ] Текст markdown-safe: все `*` парные, ничего не ломает Telegram-render.
- [ ] Путь в тексте сверен с кодом: `hangar` → `droneScoutList` существуют в
      `Config\CallbackRoutes`, кнопка «🤖 Ангар» — в `BaseServiceMessageFormatter`.
- [ ] Совет самодостаточен в тексте (media-off).

## Verification
`php -l app/Database/Migrations/2026-08-19-210000_Adr058SeedDroneDoorTip.php`

## Implementation notes
Создан `2026-08-19-210000_Adr058SeedDroneDoorTip.php` (`title_en=DroneDoorHangar`,
`tip_type=общие`), идемпотентен по `title_en`, `down()` удаляет только свою строку.
Путь сверен: `hangar`/`droneScoutList` в `Config\CallbackRoutes`, кнопка «🤖 Ангар» —
`BaseServiceMessageFormatter.php:213`. `php -l` зелёный.
Правка (team-lead): фраза «после хода / если заряжен» заменена на «пока дрон есть на
руках, кнопка видна на карте» — story 02 показывает «🚁 Дрон» уже на первом рендере
(`MoveSurfaceService::buildDirectionsKeyboard()`), заряд не условие показа. `php -l` зелёный.
