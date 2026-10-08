---
story: hotfix-trade-race-01
spec: hotfix-trade-race
status: todo
returned:
tier: 1
worker: worker-code
model: sonnet
wave: 1
blocked_by: []
---

# Склад и продажа сырья: условное списание до начисления

## Goal
«🎒 Забрать всё» идёт через `BaseStorageService::withdrawAll()` (условный `withdraw()` по видам; ядро из
`6f6f4414`). `ResourceTradeService::sellResource()` и `bulkSellResources()` сначала условно списывают ресурс
(`ConditionalWriteService::decrementIfAtLeast` по id строки), потом начисляют золото и банк — всё одной
транзакцией; проигравший гонку получает отказ, ничего не начислено.

## Files
- app/Services/Bases/BaseStorageService.php
- app/Controllers/Telegram/Commands/Actions/Storage/BaseStorageListAction.php
- app/Controllers/Telegram/Commands/Actions/Storage/BaseStorageDepositAction.php
- tests/database/BaseStorageServiceTest.php
- tests/database/BaseStorageBotParityTest.php
- tests/unit/Storage/BaseStorageDepositTest.php
- tests/unit/Storage/BaseStorageRetrieveTest.php
- app/Services/Player/Trade/ResourceTradeService.php
- tests/database/ResourceTradeGoldRaceTest.php
- tests/database/BulkSellResourcesTest.php
- phpstan-baseline.neon

## Non-goals
- Не трогать экраны продажи и тексты сообщений, цены, банк, покупку (она уже условная).
- Не делать модели экранов магазина — это W2.N6 story 02.

## Map slice
`memory/map/player.md`, ноты `tech-writing/services/{ResourceTradeService,BaseStorageService}.md`

## Acceptance criteria
- [ ] Продажа: если ресурс уже списан другим запросом между чтением и записью — отказ, золото не начислено (тест с подменой порядка).
- [ ] Опт: если хоть одна строка не списалась условно — откат всей сделки, золото не начислено (тест).
- [ ] Строка, ушедшая в 0, удаляется (экраны считают строки).
- [ ] Существующие тесты продажи и опта зелёные; паритет склада зелёный.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes

## Findings
