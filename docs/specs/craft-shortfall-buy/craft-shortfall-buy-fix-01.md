---
story: craft-shortfall-buy-fix-01
spec: craft-shortfall-buy
status: done
tier: 3
worker: worker-code
tracer: false
wave: 1
blocked_by: []
---

# Сделка: списывать обещанное, не платить впустую, не платить дважды

## Goal
Сделка докупки начинает брать ту сумму, которую показала игроку, перестаёт списывать деньги за
сборку, которая не стартует, и перестаёт проводить вторую покупку по повторному нажатию. Сейчас
всё три неверны одновременно, и каждая бьёт по кошельку игрока.

## Requirements
> если есть в наличии то считаем общую сумму и накидываем сверху процент за оптовую поставку
> и процент налога за опт указываем в тексте, игрок должен видеть и понимать чего не хватает и сколько за это придлется заплатить!
> сделать возможность крафтить даже если недостаточно какого-то материала или компонента для крафта вещи

## Files
- app/Controllers/Telegram/Commands/Actions/Craft/CraftShortfallBuyAction.php
- tests/unit/Controllers/Telegram/CraftShortfallBuyActionTest.php

## Non-goals
- Не трогать `CraftShortfallBuyService` — формула и DTO чинятся отдельной story fix-03.
- Не трогать `CraftShortageService` — кнопка входа и количество чинятся в fix-02.
- Не менять контракт `callback_data` из `## Contracts` плана: против него строится fix-02.
- Не переписывать `ResourceTradeService`: покупка по-прежнему идёт через него.

## Map slice
memory/map/craft.md, memory/map/telegram.md; `.claude/rules/telegram-ux.md`.

## Acceptance criteria
- [x] 🔴 Списанная за докупку сумма равна `quote()->total`, показанному на подтверждении, включая наценку и `min_markup_gold`. Утверждает это тест на **денежный** путь: он краснеет, если списывать базовую цену.
- [x] 🔴 Проверка возможности старта учитывает золото, которое уйдёт на докупку: после успешной покупки старт не может отказать по деньгам. Тест на рецепт с `gold_required > 0`, где золота хватает на докупку, но не на обе траты сразу.
- [x] 🔴 Повторное нажатие (повтор вебхука или двойной тап) не проводит вторую покупку той же недостачи и не ставит второй крафт в очередь. Замка на связке персонаж+рецепт достаточно; тест краснеет при снятии замка.
- [x] Отсутствие закэшированной суммы трактуется как «цена не подтверждена» — переспросить, а не проводить сделку.
- [x] Откат частично проведённой покупки покрыт тестом, который краснеет при удалении отката. Заглушка `executePurchase` в тестах больше не скрывает этот путь.
- [x] Сбой внутри транзакции завершается явным откатом и честным ответом игроку, а не исключением наверх.
- [x] Ключ рецепта из пользовательского ввода экранируется перед подстановкой в Markdown-ответы.
- [x] Отказы шлются с клавиатурой: игрок не остаётся без кнопки назад.
- [x] Значение `max_units_per_purchase = 0` ведёт себя так, как обещает его подпись в админке.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Controllers/Telegram/`

## Notes
Полный разбор находок — `docs/specs/craft-shortfall-buy/review-findings.md`. Читай его, прежде чем править. Критические 2 и 3, крупные 4, 5, 9, мелкие 13, 15, 18.

## Implementation notes
- `executePurchase()` разбит на orchestration (`runPurchaseTransaction()`) + переопределяемые DB-touching seam'ы (`buyLine`, `chargeExtraGold`, `beginPurchaseTransaction`/`commitPurchaseTransaction`/`rollbackPurchaseTransaction`, `acquireLock`/`releaseLock`) — тот же приём, что `CraftPoolConsumptionTest` уже применяет к `GenericCraftActionStart`. Оркестрация в тестах настоящая, стубятся только leaf-вызовы.
- Наценка (находка 2): `markupGold = quote->total - Σ базовых цен строк`; списывается отдельным `chargeExtraGold()` в той же транзакции — фактически списанное всегда равно показанному `total`.
- Гейт старта (находка 3): новый `characterAfterPurchase()` — снапшот персонажа с золотом `gold - quote->total` (не ниже 0) — передаётся в `canStartError()` во всех трёх местах вызова (`showConfirmation`, `confirmAndBuy` price-changed-ветка, `attemptStartCraft`). `GenericCraftActionStart` не тронут (вне Files).
- Замок сделки (находка 4): cache-замок `craft_shortfall_purchase_lock_<charId>_<recipeKey>` (TTL 15с), единственный choke-point — оборачивает весь `executePurchase()` (обе кнопки `craftBuyGo`/`craftBuyOnly` идут через него), поэтому и повторная покупка, и повторная постановка в очередь блокируются одним замком.
- `priceChanged()` (находка 5) инвертирован: `cachedTotal === null` теперь тоже «цена изменилась» → переспрос вместо тихого прохода по свежей цене без подтверждения.
- `executePurchase()` ловит `\Throwable` (находка 6): откат + honest-сообщение, ничего не летит наверх.
- `sendError()` получил `?array $recipe` + `errorKeyboard()` (находка 15) — кнопка «Назад» на рецепт либо на общий `craft`; все вызовы в файле, где рецепт уже известен, его передают.
- `resolveMaxUnits()` (находка 18) больше не форсит `max(1, …)` — 0 остаётся 0, `handle()` рано отказывает с текстом killswitch, до расчёта `quote()`.
- Ключ рецепта в «Неизвестный рецепт: …» экранируется через `safe()` (находка 13).
- По просьбе координатора (не в исходном review-findings, поверх story): `REASON_TEXT` дополнен записью для `CraftShortfallBuyService::REASON_PRICE_UNKNOWN` (эта причина уже существует в сервисе — правка fix-03, сервис не трогал).
- phpstan-ревью координатора: убран мёртвый `is_numeric()` на уже-`int` значении; `beginPurchaseTransaction`/`commitPurchaseTransaction`/`rollbackPurchaseTransaction` больше не гоняют `mixed $tx` — они без аргументов и без возврата, дважды зовут `\Config\Database::connect()` (CI4 кэширует соединение по группе, второй вызов возвращает тот же handle).
- Файлы: `app/Controllers/Telegram/Commands/Actions/Craft/CraftShortfallBuyAction.php`, `tests/unit/Controllers/Telegram/CraftShortfallBuyActionTest.php`.

## Findings
