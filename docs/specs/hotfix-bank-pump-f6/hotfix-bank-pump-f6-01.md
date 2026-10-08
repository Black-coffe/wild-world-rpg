---
story: hotfix-bank-pump-f6-01
spec: hotfix-bank-pump-f6
status: todo
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
- tests/database/Economy/BankSpreadInvariantTest.php
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

## Evidence (ask 5)
