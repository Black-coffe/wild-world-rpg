---
story: craft-shortfall-buy-fix-11
spec: craft-shortfall-buy
status: done
tier: 3
worker: worker-code
tracer: false
wave: 6
blocked_by: []
---

# Экран: строка позиции должна сходиться арифметически, а выключатель — выключать

## Goal
Распределение наценки по строкам сделало показ несогласованным: строка печатает цену за штуку и
итог, которые не сходятся умножением. А значение лимита «ноль», обещанное в админке как
выключатель фичи, экран прижимает к единице и продолжает рисовать кнопку.

## Requirements
> и процент налога за опт указываем в тексте, игрок должен видеть и понимать чего не хватает и сколько за это придлется заплатить!

## Files
- app/Services/Craft/CraftShortfallBuyService.php
- app/Services/Craft/CraftShortageService.php
- tests/unit/Services/Craft/CraftShortfallBuyServiceTest.php
- tests/unit/Services/Craft/CraftShortageServiceTest.php

## Non-goals
- Не трогать обработчик сделки — он в соседней story.
- Не менять формулу наценки и не отключать распределение итога по строкам без нужды.
- Не менять порядок экрана.

## Map slice
memory/map/craft.md; `.claude/rules/telegram-ux.md`, `.claude/rules/balance.md`.

## Acceptance criteria
- [ ] 🔴 Числа строки позиции согласованы: напечатанное «цена за штуку × количество» сходится с напечатанным итогом строки, а наценка фигурирует ровно один раз и не вшита молча в строку.
- [ ] Сумма строк по-прежнему равна списываемому итогу.
- [ ] 🔴 При `max_units_per_purchase = 0` экран и сделка ведут себя одинаково: кнопки нет вовсе, а не кнопка, отвечающая «докупка выключена». Тест на это значение.
- [ ] Причина `price_unknown` на экране называется своими словами, а не отсылкой к позиции, которой нет.
- [ ] Прежние тесты ремонта остаются зелёными.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Services/Craft/`

## Notes
Повторное ревью: M1/E (арифметика строки) и M2/C (семантика нуля) — обе нашли оба ревьюера независимо; плюс m2 первого ревьюера про текст причины.

## Implementation notes
- `CraftShortfallBuyService::distributeTotalAcrossLines()` теперь пересчитывает `unitPrice` каждой строки из уже распределённого (с наценкой) `lineTotal` (`lineTotal/gap`), а не оставляет сырую цену — «цена × gap» на экране снова сходится с «итого».
- `CraftShortageService::shortfallLineText()` переименовал ярлык «у торговца» → «с наценкой», честно называя, что цена строки уже включает наценку (наценка упоминается один раз на строку; общий `%` — отдельно, один раз, в резюме блока).
- `CraftShortageService::maxUnitsPerPurchase()` больше не прижимает `0` к `1` (`max(0, $val)`, как в `CraftShortfallBuyAction::resolveMaxUnits()`); `shortfallBuyBlock()` при `maxUnits<=0` возвращает пустой блок ДО вызова `quote()` — иначе `CraftShortfallBuyService::quote()` сам клампит qty к 1 и вернул бы доступную сделку.
- `CraftShortageService::shortfallSummaryText()` получил отдельную ветку для `REASON_PRICE_UNKNOWN` — раньше падал в default («причина у позиции выше»), хотя эта причина не привязана ни к одной строке.
- Тесты: `CraftShortfallBuyServiceTest::testLineUnitPriceMatchesLineTotalAfterMarkupDistribution`, `CraftShortageServiceTest::testShortfallBuyBlockHiddenWhenMaxUnitsIsZero`, `CraftShortageServiceTest::testShortfallBuyBlockExplainsPriceUnknownRefusalWithItsOwnWords`.
- Файлы правки: `app/Services/Craft/CraftShortfallBuyService.php`, `app/Services/Craft/CraftShortageService.php`, `tests/unit/Services/Craft/CraftShortfallBuyServiceTest.php`, `tests/unit/Services/Craft/CraftShortageServiceTest.php` — ровно `## Files`.
- В рабочем дереве уже лежали несвязанные незакоммиченные правки другой story (`craft-shortfall-buy-fix-09/10`: `app/Config/CraftRecipes.php`, `CraftShortfallBuyAction.php` и их тесты) — не трогал, вне `## Files`.

## Findings
- Ремонт по итогам приёмки: `testShortfallBuyBlockLocksWhenRecipeKeyUnknownToHandler` был красным из-за привязки к конкретному рецепту (`DrifterClothes2`), который параллельная story `fix-09` регистрирует в `Config\CraftRecipes` по решению владельца — вывод «не моё, пре-существующее» был неточен: тест не выдержал бы содержательного пополнения контента, а не только грязного дерева. Починено — тест переведён на синтетический ключ `NoSuchLegacyRecipeXyz`, которого в `Config\CraftRecipes` нет и не будет (не имя настоящего рецепта), с обновлённым комментарием, объясняющим почему; `app/Config/CraftRecipes.php` не тронут (чужая story).
