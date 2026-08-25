---
story: community-chat-bot-46
spec: community-chat-bot
status: todo
tier: 2
worker: worker-code
tracer: false
wave: 11
blocked_by: []
---

# Метрика отказов гварда считает каждую строку склейки и не висит на best-effort логе

## Goal
Дубликаты одного вопроса перестают выпадать из доли отказов гварда, а сбой необязательной
аудит-записи перестаёт вычитать строку из метрики.

## Requirements
> Тут нужно спланировать, как он будет отвечать: автоматически, в ручном режиме, полуавтоматически

## Files
- app/TaskHandlers/Community/CommunityAutoReplyHandler.php
- app/Controllers/Admin/CommunityController.php
- tests/unit/TaskHandlers/CommunityAutoReplyHandlerTest.php
- tests/unit/Controllers/Admin/CommunityControllerTest.php

## Non-goals
- Не трогать гвард, отправителя, матчер, чистку.
- Не менять остальные метрики экрана.

## В чём дефект
1. `logRoute()` вызывается один раз, на строку-представителя (`CommunityAutoReplyHandler.php:245`),
   а статус `escalated` получает ВСЯ склейка (`markGroup($decision->coveredMessageIds, …)`, `:244`).
   После story 39 различитель — `EXISTS(COMMUNITY_ROUTE_LOGGED)`, поэтому остальные строки склейки
   выпадают и из числителя, и из знаменателя. Это дословно тот дефект, который story 39 закрывала,
   переехавший с «упёрлись в лимит» на «дубликаты одного вопроса». При старом `NOT EXISTS` они
   считались все. Ни один из трёх тестов метрики склейку не строит.
2. `logRoute()` (`:455-482`) глушит `Throwable` на вставке и молча выходит при пустом маршруте.
   После story 39 проглоченная вставка не просто теряет подсказку, а вычитает строку из метрики.

## Contract
- Числитель и знаменатель доли отказов гварда включают КАЖДУЮ строку, которую отказ гварда
  перевёл в `escalated`, включая дубликаты склейки.
- Признак, по которому опознаётся отказ гварда, не исчезает при сбое необязательной аудит-записи.
- Тесты строят склейку из нескольких строк, а не одиночный вопрос.

## Acceptance criteria
- [ ] Склейка из N строк, отказанная гвардом, даёт N в числителе и N в знаменателе.
- [ ] Сбой аудит-вставки не меняет метрику.
- [ ] Терминальный отказ отправителя по-прежнему в числитель не попадает (регрессия story 39/32).

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/TaskHandlers/CommunityAutoReplyHandlerTest.php tests/unit/Controllers/Admin/CommunityControllerTest.php`
