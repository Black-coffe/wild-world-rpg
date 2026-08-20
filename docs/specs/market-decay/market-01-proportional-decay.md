---
story: market-01
spec: market-decay
status: done
tier: 2
worker: worker-code
model: sonnet
tracer: false
wave: 1
blocked_by: []
---

# Состаривание рыночных счётчиков становится пропорциональным

## Goal
`ResourceBankUpdateHandler` состаривает `resources_purchased` / `resources_sold` на **−1 за
тик**, а на проде эти счётчики — миллионы (до 4.56 млн). Возврат к базовой цене занял бы
5–9 лет, то есть цены заморожены навсегда: новая сделка добавляет единицу к миллиону и
ratio не двигает. Story заменяет вычитание единицы на пропорциональное затухание с потолком
счётчиков — обе величины admin-tunable, под килсвитчем.

## Requirements
> разберись с затоваренным рынком

Подробности аудита и обоснование решения — `docs/specs/market-decay/brief.md`.

## Context
`app/TaskHandlers/ResourceBankUpdateHandler.php::process()` — единственное место, где
счётчики стареют. Сегодня (строки ~36-67): читает банк, считает
`ratio = (purchased + 1) / (sold + 1)`, зажимает в `[0.35, 3.5]`, пишет
`buy_price = round(base × ratio × 1.05, 2)` и `sell_price = round(base × ratio × 0.95, 2)`,
затем `purchased = max(0, purchased - 1)`, `sold = max(0, sold - 1)`.

**Формулу цены не трогаем вовсе** — меняется только шаг состаривания.

Класс не наследует `BaseTaskHandler`; для чтения настроек подключи
`App\Services\GameSettings\GameSettingsReaderTrait` (даёт `gsBool` / `gsInt` / `gsFloat`) —
тем же способом, что и остальные потребители баланса.

Крон: `app/Config/Tasks.php:56`, `everyMinute()`, `singleInstance()`. Сигнатура
`process()` сегодня без параметров — добавь необязательный `int $intervalMinutes = 1`,
чтобы тест мог прогнать длинный интервал без ожидания (тот же приём, что у
`DroneRechargeCron::run(int $intervalMinutes = 1)`).

## Files
- app/TaskHandlers/ResourceBankUpdateHandler.php
- app/Database/Migrations/2026-12-04-100000_SeedMarketDecayGameSettings.php
- app/Database/Migrations/2026-12-04-110000_SeedMarketDynamicPricesTip.php
- tests/database/Economy/MarketDecayTest.php

## Acceptance
- [ ] Новые ключи `GameSettings`, все три с полным набором
      `rationale_text` / `effect_text` / `above_effect_text` / `below_effect_text` и границами:
      - `economy.market.proportional_decay_enabled` — bool, **default false** (килсвитч);
      - `economy.market.half_life_hours` — float, период полураспада счётчиков, default 48;
      - `economy.market.counter_cap` — int, потолок счётчика, default 2000.
- [ ] **Килсвитч выключен → поведение байт-идентично сегодняшнему**: `−1` за тик, пол в нуле,
      цены те же. Это отдельный тест, а не утверждение в комментарии.
- [ ] Килсвитч включён, и хотя бы один счётчик строки выше потолка → оба счётчика
      масштабируются вниз **с сохранением пропорции** (делятся на один и тот же множитель
      `cap / max(purchased, sold)`), после чего максимум из двух равен потолку.
- [ ] Втягивание не меняет цену на этом же тике: цена считается из счётчиков **до**
      масштабирования (порядок «посчитал цену → состарил» сохраняется). Тест сравнивает
      цену до и после втягивания на одной и той же строке.
- [ ] Килсвитч включён → счётчики умножаются на `2 ** (-intervalMinutes / (half_life_hours × 60))`.
      Тест: при `half_life_hours = 1` и `intervalMinutes = 60` счётчик 1000 становится ≈500
      (допуск ±1 на округление).
- [ ] Счётчики никогда не уходят в минус; значение меньше 1 после затухания становится 0
      (никакого бесконечного хвоста дробей — колонки bigint).
- [ ] Строки без записи в банке по-прежнему пропускаются (существующее поведение `continue`).
- [ ] Формула цены и спред не изменились: `clamp(ratio, 0.35, 3.5)`, `×1.05` / `×0.95`,
      `round(..., 2)`.
- [ ] Обе seed-миграции идемпотентны. Совет — по образцу `*Seed<Что>Tip.php`, идемпотентность
      по `title_en`, категория из 14 разрешённых ENUM (`ресурсы`), текст самодостаточен в
      media-off, markdown-safe, тон Роби, без точных чисел баланса.

## Non-goals
- Не менять клампы `0.35` / `3.5`, спред `1.05` / `0.95` и саму формулу ratio.
- Не трогать `current_quantity` и запись счётчиков в `ResourceTradeService` / `ResourcesBankModel`.
- Не сбрасывать счётчики миграцией вручную — втягивание делает крон на первом же тике
  после флипа килсвитча.
- Не трогать `WipeManifest` (таблица уже классифицирована `SEED_RESET`).

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/database/Economy/MarketDecayTest.php`
и затем ПОЛНЫЙ `vendor/bin/phpunit --no-coverage --no-progress` — сверять наличие итоговой
строки `Tests: N`, а не exit code. Плюс
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`.

## Map slice
`app/TaskHandlers/ResourceBankUpdateHandler.php`, `app/Models/ResourcesBankModel.php`,
`app/Services/Player/Trade/ResourceTradeService.php`, `app/Services/GameSettings/`

## Implementation notes

- `ResourceBankUpdateHandler` подключил `GameSettingsReaderTrait`, добавил `process(int $intervalMinutes = 1)`
  (нет тестовых подклассов со старой сигнатурой — grep пуст). Формула цены не тронута, порядок
  «посчитал цену из счётчиков до → потом состарил» сохранён буквально.
- Килсвитч `economy.market.proportional_decay_enabled` (default false) ветвит на старое
  `max(0, x-1)` или новый `decayCounters()`. Затухание и втягивание в потолок — одна и та же
  операция «умножить оба счётчика на общий множитель», сначала множитель полураспада
  `2 ** (-intervalMinutes / (half_life_hours × 60))`, затем (если максимум всё ещё выше
  `counter_cap`) множитель `cap / max(decayedPurchased, decayedSold)` — оба шага коммутативны
  и сохраняют пропорцию purchased/sold. Финальное значение — `floor(x + 1e-9)` (эпсилон против
  погрешности `2 ** float`), поэтому дробный хвост < 1 гарантированно уходит в 0, а не
  округляется вверх.
- Дефолты: `half_life_hours=48` (рынок «забывает» за ~2 суток — не дёргается от одной сделки,
  но недельный простой заметно отпускает цену), `counter_cap=2000` (на порядок больше суточного
  оборота одним ресурсом, но на порядки меньше прод-миллионов — первый тик после флипа
  килсвитча сразу выводит счётчик в рабочий диапазон, не обнуляя рынок). Обе — `category: resources`
  (сайдбар «Ресурсы и редкость»), с полным rationale/effect/above/below и hard-границами.
- Seed-миграция совета `MarketDynamicPrices` (категория `ресурсы`) — про понятие «цена реагирует
  на торговлю», без чисел баланса; идемпотентна по `title_en`.
- Тест `tests/database/Economy/MarketDecayTest.php` — 8 тестов: килсвитч-off байт-идентичен
  старому (-1, пол в нуле, цена не изменилась), полураспад ополовинивает счётчик за 1 период
  (±1 допуск), почти мгновенный half_life уводит в 0 без минуса, потолок сохраняет пропорцию
  (4M:2M → 1000:500) и не меняет цену на этом же тике (числа подобраны так, чтобы
  `(purchased+1)/(sold+1)=1.5` совпадало с малым сценарием — на больших числах `+1`-офсет
  ratio-формулы не совпадает с "чистой" пропорцией purchased:sold, которую использует
  затухание/cap, поэтому это два разных инварианта и тесты их разделяют), строки без записи
  в банке по-прежнему пропускаются.
- Прогоны: `vendor/bin/phpunit --no-coverage --no-progress tests/database/Economy/MarketDecayTest.php`
  → `Tests: 8, Assertions: 16` (OK, but there were issues — 1 unrelated PHPUnit deprecation,
  не про этот файл). Полный `vendor/bin/phpunit --no-coverage --no-progress` →
  `Tests: 3182, Assertions: 27635, Skipped: 8` (OK, но есть issues — 32 деприкейшна, не мои,
  без фаталов/failures). `vendor/bin/phpstan analyse --memory-limit=512M --no-progress` → No errors.
