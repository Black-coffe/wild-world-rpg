---
story: hotfix-bank-pump-f6-01
spec: hotfix-bank-pump-f6
status: done
returned: DONE
tier: 1
worker: worker-code
model: sonnet
wave: 1
blocked_by: []
---

# Банк не выкупает сырьё дороже, чем продаёт

## Goal
`ResourceBankUpdateHandler::process()` считает цену покупки от множителя не ниже 1, а цену выкупа — от множителя
не выше 1: `buy = base×max(1, factor)×1.05`, `sell = base×min(1, factor)×0.95`. Круг «купил → тик → сдал» всегда
в минус; спрос по-прежнему поднимает цену покупки, избыток — опускает цену выкупа.

## Requirements
> 1. Отдельный хотфикс F6 тегом на прод. По вашему правилу «дыра не ждёт фичу» он идёт первым.

## Files
- app/TaskHandlers/ResourceBankUpdateHandler.php
- tests/database/Economy/MarketDecayTest.php
- tests/exploit-poc/EconomyLimitsTest.php

## Non-goals
- Не трогать затухание ADR-175, клампы 0.35/3.5 и спред 1.05/0.95 (долг трека D), экраны, тексты, сделки.
- Не трогать караван (F15) и прочие пункты бэклога аудита.
- Не изымать золото и запасы у игроков — решение владельца.

## Map slice
`memory/map/player.md`; ноты `tech-writing/tasks/economy/ResourceBankUpdateHandler.md` (или где лежит нота крона), `tech-writing/services/ResourceTradeService.md`

## Acceptance criteria
- [ ] PoC `EconomyLimitsTest::testSingleUnguardedPurchasePumpsSellPriceAboveOriginalBuyPrice` зелёный.
- [ ] Тест-инвариант: по сетке счётчиков (0, 1, 500, 2000, 10⁶ с обеих сторон; клампы 0.35 и 3.5) после тика `sell ≤ base×0.95 < base×1.05 ≤ buy`.
- [ ] Тест «круг в минус»: покупка в залежавшемся состоянии по цене до тика, памп, тик, продажа — выручка меньше затрат.
- [ ] Живость: при `purchased ≫ sold` цена покупки = `base×3.5×1.05`; при `sold ≫ purchased` цена выкупа = `base×0.35×0.95`.
- [ ] Старые тесты `MarketDecayTest` с ожиданием «формула не изменена» переписаны на новые ожидания с объяснением.
- [ ] phpstan чист.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes
- `ResourceBankUpdateHandler::process()`: `buyFactor = max(1.0, priceFactor)`, `sellFactor = min(1.0, priceFactor)`;
  `buy = base×buyFactor×1.05`, `sell = base×sellFactor×0.95`. Отношение счётчиков, клампы и затухание не тронуты.
- Тесты инварианта легли в `MarketDecayTest` (его фикстура уже строит `resources`/`resources_bank`), а не в новый
  файл: ещё один тест, сносящий общие таблицы, — лишний (долг изоляции DB-тестов). Файл
  `BankSpreadInvariantTest.php` из `## Files` не создан.
- Два старых ассерта «формула не изменена» (`sell=14.25` при ratio 1.5) переписаны на `9.5`: спрос больше не
  поднимает выкуп.
- Доказательство «тест достаёт до правки»: на старом хэндлере `MarketDecayTest` — 5 падений (2 старых ассерта + 3
  новых теста), на новом — 11/11.
- Вердикты: /guide — нет, совет — нет (ask 6). Нота `tech-writing/tasks/economy/ResourceBankUpdateHandler.md` обновлена.

## Evidence (ask 5)
- PoC `EconomyLimitsTest::testSingleUnguardedPurchasePumpsSellPriceAboveOriginalBuyPrice`: до — RED (95 → 332.50), после — GREEN.
- `MarketDecayTest::testBuybackNeverExceedsBasePriceAndPurchaseNeverDropsBelowIt` — сетка 6×6 счётчиков (0…10⁶), оба клампа.
- `testBuyPumpTickSellRoundTripAlwaysLoses` — сам круг с прода (залежалось → покупка 10⁶ → тик → продажа) в минус.
- Preprod: см. smoke после деплоя.
