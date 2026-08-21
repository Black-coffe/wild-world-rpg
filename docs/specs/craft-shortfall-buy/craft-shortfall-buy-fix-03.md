---
story: craft-shortfall-buy-fix-03
spec: craft-shortfall-buy
status: done
tier: 3
worker: worker-code
tracer: false
wave: 1
blocked_by: []
---

# Сервис расчёта: честный пол наценки, вменяемый процент, правдивая причина отказа

## Goal
Три дефекта расчёта, каждый из которых виден игроку числом или текстом: усечённый пол наценки,
абсурдный процент на копеечных суммах и отказ гейта рентабельности, называющий причину, которой
сервис не вычислял.

## Requirements
> если есть в наличии то считаем общую сумму и накидываем сверху процент за оптовую поставку
> система считает по текущему курсу, сколько будет столить купить у продлавца на рынке
> Проверка централизованная, тем же сервисом, что считает цену на экране.

## Files
- app/Services/Craft/CraftShortfallBuyService.php
- tests/unit/Services/Craft/CraftShortfallBuyServiceTest.php

## Non-goals
- Не трогать экран и сделку — это fix-01 и fix-02.
- Не менять сигнатуру `quote()` и состав полей DTO: против них уже написаны две story.
- Не включать гейт рентабельности по умолчанию — решение владельца оставить его выключенным в силе.

## Map slice
memory/map/craft.md; `.claude/rules/balance.md`.

## Acceptance criteria
- [ ] Пол наценки не усекается: итог никогда не оказывается ниже `base + min_markup_gold`. Тест на дробную базу, где усечение раньше давало меньше пола.
- [ ] Показанный процент остаётся осмысленным на копеечных суммах: игрок не читает «наценка 2900%».
- [ ] Строка позиции и итог не расходятся: не бывает «0 💰» в строке при ненулевом итоге.
- [ ] 🔴 При включённом гейте рецепт, цену которого определить нельзя, либо имеет источник цены, либо отказывает текстом, называющим настоящую причину, а не выдаёт неизвестность за подсчитанную невыгодность. Тест на такой рецепт.
- [ ] Прежние тесты гейта остаются зелёными: он по-прежнему умеет и заблокировать, и пропустить.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Services/Craft/`

## Notes
Полный разбор находок — `docs/specs/craft-shortfall-buy/review-findings.md`. Читай его, прежде чем править. Крупная 8, мелкие 12, 13.

## Implementation notes

- `app/Services/Craft/CraftShortfallBuyService.php`:
  1. **Пол наценки (мелкая 12):** `total` теперь `(int) max(ceil($base + $minMarkupGold), ceil($base * (1 + $markupFraction)))` — каждое слагаемое `max()` округляется вверх ДО сравнения, `(int)`-каст больше не отрезает дробную часть пола. `base=1.6, min=1` даёт `3`, не `2` (тест `testMarkupFloorNeverTruncatesBelowPromisedMinimum`).
  2. **Абсурдный процент (мелкая 13, часть 1):** `markupPct` считается от `max($base, 1.0)`, а не от сырой дробной `base` — копеечная позиция (`base=0.1, min=3`) даёт `390%` вместо `2900–3900%` (тест `testMarkupPctStaysSaneOnPennyPosition`). Выбор: 1 — минимальная единица золота, которую вообще видит игрок; не GameSettings-параметр (это математическая привязка к целочисленному отображению, не игровой баланс).
  3. **«0 💰» при ненулевом итоге (мелкая 13, часть 2):** новый приватный метод `distributeTotalAcrossLines()` — метод наибольшего остатка распределяет весь `total` (уже с наценкой и полом) по покупаемым строкам пропорционально их сырому вкладу в `base`. На частом случае «не хватает одной позиции» вся сумма уходит в единственную строку — `lineTotal === (float) total`, никакого расхождения со строкой (тест `testLineTotalMatchesQuoteTotalWhenSinglePaidLine`).
  4. **Гейт называет неверную причину (крупная 8):** добавлена `REASON_PRICE_UNKNOWN = 'price_unknown'`, отдельная от `REASON_PROFIT_GATE`. `recipeRevenue() === null` (нет `item_name_eng`, рецепт не найден в `crafted_items` — это ~70 из 105 значений, output_type weapon/outfit/resource продаются иначе, — `price` не число) → `price_unknown`; только когда выручка ДЕЙСТВИТЕЛЬНО посчитана и `total > revenue` → `profit_gate`. Экран (`CraftShortfallBuyAction::REASON_TEXT`, story 08, не в Files этой story) не знает текста для `price_unknown` и печатает безопасный дефолт «Докупка сейчас недоступна.» (строка 367 того файла, `?? 'Докупка сейчас недоступна.'`) — честно, без утверждения о невыгодности. Решение: не стал искать альтернативный источник цены для weapon/outfit/resource-рецептов (табличная сверка ценников оружия/брони — отдельная content-задача с миграциями и вне Files этой story); честный текст «недоступно» — минимальный безопасный фикс в рамках разрешённых файлов. Гейт остался выключенным по умолчанию (не трогал).
- Профит-гейт тест `testProfitGateFailsClosedWhenItemPriceCannotBeResolved` обновлён: reason теперь `price_unknown`, не `profit_gate`; поведение (`available=false`) не изменилось. Добавлен `testProfitGateReturnsPriceUnknownWhenRecipeNotInCraftedItemsTable` — прямое покрытие реального случая (`item_name_eng` есть, строки в `crafted_items` нет).
- phpstan L9 на файле сервиса: 0 ошибок (падал на генерике list-shape при точечной мутации `$lines[$i]['lineTotal'] = ...` внутри `distributeTotalAcrossLines()` — исправлено через read-modify-write всей строки, не точечную запись по вложенному ключу).

## Findings
