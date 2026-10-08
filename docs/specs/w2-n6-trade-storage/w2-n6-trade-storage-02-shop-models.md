---
story: w2-n6-trade-storage-02
spec: w2-n6-trade-storage
status: todo
returned:
tier: 2
worker: worker-code
model: sonnet
wave: 2
blocked_by: [w2-n6-trade-storage-01]
---

# Модели экранов магазина сырья поверх ResourceTradeService

## Goal
Нейтральный сервис `App\Services\Player\Trade\ResourceShopScreenService` отдаёт модели: хаб магазина
(4 входа: продать и купить сырьё нативно; продать и купить крафт маршрутом бота), выбор редкости для продажи
со стоимостью, список ресурсов редкости, карточку ресурса (цена продажи и покупки за 1 шт., пресеты количества,
потолок `max_qty`), покупку, опт (проценты, превью `bulkSellPreview`, флаг `KEY_ENABLED`). Сделки идут через
существующие `ResourceTradeService::sellResource/buyResource/bulkSellResources`, потолок количества проверяется в ядре.
`ShopAction`, `SellAction`, `SellResourceAction`, `BuyResourceAction`, `BulkSellAction` рисуют из моделей; тексты и
кнопки не меняются. Ввод своего числа через ForceReply (`handleTradeReply`) идёт в то же ядро.

## Requirements
> 1. В /play есть «🛒 Магазин» с сырьём: продать или купить по редкости, оптом долю всех ресурсов. Количество задаётся кнопками или своим числом, цена видна до сделки. Крафт и снаряжение у торговца пока через мост.
> 3. Сделки и проверка «на базе» — в ядре для обоих клиентов. Повтор формы или двойной тап не продаёт и не перекладывает дважды.
> 4. Бот рисует магазин сырья и склад из тех же моделей. Тексты и кнопки не меняются (паритет тестом), своё число через ответ на сообщение работает как раньше.

## Files
- app/Services/Player/Trade/ResourceShopScreenService.php
- app/Services/Player/Trade/ResourceTradeService.php
- app/Controllers/Telegram/Commands/Actions/ShopAction.php
- app/Controllers/Telegram/Commands/Actions/Sell/SellAction.php
- app/Controllers/Telegram/Commands/Actions/Sell/SellResourceAction.php
- app/Controllers/Telegram/Commands/Actions/Sell/BuyResourceAction.php
- app/Controllers/Telegram/Commands/Actions/Sell/BulkSellAction.php
- app/Controllers/Telegram/Commands/SystemCommands/GenericmessageCommand.php
- tests/database/ResourceShopScreenServiceTest.php
- tests/database/ResourceShopBotParityTest.php
- tests/database/ResourceTradeGoldRaceTest.php
- tests/unit/Services/Player/Trade/ResourceTradeServiceTest.php
- phpstan-baseline.neon

## Non-goals
- Не трогать `Sell*Craft*`, `SellGear*`, `BuyCraft*`, `CraftShortfallBuyService`, `SettlementShop*` (это N6b и вне трека).
- Не менять цены, спред, наценки, лимиты: только читать то, что уже читает бот.
- `ResourceTradeService` трогать, только если тест покажет двойную сделку при повторе; иначе нужен тест-доказательство.

## Map slice
`memory/map/player.md` (инвентарь), ноты `tech-writing/services/{ResourceTradeService,TradePricingService}.md`

## Acceptance criteria
- [ ] Модели не зависят от `chat_id` и Telegram; персонаж приходит массивом или id.
- [ ] Бот: хаб, продажа по редкости, карточка, покупка, опт (превью и итог) дают тот же текст и кнопки (снимки).
- [ ] `qty` выше потолка: отказ ядра, золото и ресурсы не тронуты (тест).
- [ ] Два подряд вызова продажи последнего остатка не продают больше, чем было (тест).
- [ ] ForceReply «SELL:/BUY:» продаёт и покупает как раньше (тест на `handleTradeReply` или его ядро).

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes

## Findings
