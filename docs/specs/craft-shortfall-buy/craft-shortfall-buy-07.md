---
story: craft-shortfall-buy-07
spec: craft-shortfall-buy
status: done
tier: 1
worker: worker-code
tracer: false
wave: 1
blocked_by: []
---

# Настройки баланса craft.shortfall_buy.* в админке

## Goal
Шесть ключей докупки заводятся в `game_settings` идемпотентной seed-миграцией с полным обоснованием, границами и Reset-to-default, и появляются в админке в категории «крафт».

## Requirements
> Обе части — в GameSettings с rationale.
> От конвейера защищает не доля, а лимит штук: в первой версии докупка только на 1 шт., ручка в админке.

## Files
- app/Database/Migrations/2026-08-21-100000_SeedCraftShortfallBuyGameSettings.php

## Non-goals
- Не читать эти ключи из кода — чтением занимается story 06.
- Не заводить ключи с префиксом `economy.bulk_sell.*` и вообще не использовать слово «опт»: префикс занят оптовой ПРОДАЖЕЙ (ADR-096).
- Не трогать `Config\WipeManifest` — новых таблиц и player-колонок нет.

## Map slice
memory/map/admin.md — GameSettings; образец `2026-12-04-100000_SeedMarketDecayGameSettings.php`.

## Acceptance criteria
- [ ] Шесть ключей из `## Contracts` плана заведены с дефолтами: килсвитч выключен, лимит штук 1.
- [ ] У каждого ключа непустые `rationale_text`, `effect_text`, `above_effect_text`, `below_effect_text` — без них запись не имеет права существовать.
- [ ] У `base_markup_pct` задан `hard_min` = 5. Якорь считается так: крон пересчитывает
      `buy_price = base × factor × 1.05`, а `sell_price = base × factor × 0.95`, то есть покупка
      уже на 10.5% хуже продажи добытого даже при нулевой наценке. Пол в 5 пунктов оставляет
      запас, при котором строка экрана «Добыть почти всегда дешевле, чем купить» остаётся правдой
      при любом допустимом значении. Этот вывод записывается в `rationale_text`, чтобы следующая
      балансная волна не сдвинула число вслепую.
- [ ] `above_effect_text` у `max_units_per_purchase` прямо говорит: выше 1 докупка перестаёт быть доводкой одной сборки и становится закупкой партии.
- [ ] Миграция идемпотентна по `setting_key` — повторный прогон ничего не дублирует.
- [ ] `category = 'craft'`, ключи видны в админке без правок контроллера.

## Verification
`php -l app/Database/Migrations/2026-08-21-100000_SeedCraftShortfallBuyGameSettings.php`

## Implementation notes

- `app/Database/Migrations/2026-08-21-100000_SeedCraftShortfallBuyGameSettings.php` — 6 ключей
  `craft.shortfall_buy.*`, category `craft`, идемпотентно по `setting_key` (тот же паттерн, что
  `SeedMarketDecayGameSettings`).
- `base_markup_pct`: `hard_min = 5`, обоснование запаса (10.5% встроенных в buy/sell ×1.05/×0.95
  + 5 пунктов) записано в `rationale_text` дословно из акцептанс-критерия.
- `max_units_per_purchase`: `above_effect_text` прямо называет переход «докупка → закупка партии»
  при значении выше 1; `hard_max = 10` как в контракте плана.
- Слово «опт» нигде не использовано; префикс `economy.bulk_sell.*` не заведён.
- `WipeManifest` не тронут — `game_settings` уже KEEP, новых таблиц/колонок нет.

## Findings
