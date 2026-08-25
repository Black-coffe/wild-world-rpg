---
story: community-chat-bot-39
spec: community-chat-bot
status: done
tier: 2
worker: worker-code
tracer: false
wave: 10
blocked_by: []
---

# Доля отказов гварда опознаётся по своей записи, а не по чужой давности

## Goal
Метрика перестаёт занижать гвард из-за отказа отправителя, случившегося когда угодно раньше.

## Requirements
> Тут нужно спланировать, как он будет отвечать: автоматически, в ручном режиме, полуавтоматически

## Files
- app/Controllers/Admin/CommunityController.php
- tests/unit/Controllers/Admin/CommunityControllerTest.php

## Non-goals
- Не трогать тик, гвард, отправителя, чистку.
- Не менять остальные метрики экрана.

## В чём дефект
`guardDeniedCount()` (`:487-505`) отличает отказ гварда от отказа гейта отправителя через
`NOT EXISTS (... COMMUNITY_ANSWER_REJECTED ...)`, но подзапрос не ограничен ни окном, ни попыткой.

Сценарий: сообщение упирается в `topic_rate_limit` — отправитель пишет `_REJECTED`, строка
возвращается в `new`. Позже её денит гвард — строка становится `escalated` без `SENT`, но давняя
`_REJECTED` у неё уже есть. Строка выпадает и из числителя, и из знаменателя: доля отказов гварда
занижается ровно на тех вопросах, что прошли через временный лимит.

## Contract
- Отказ гварда опознаётся по его СОБСТВЕННОЙ положительной записи (`COMMUNITY_ROUTE_LOGGED` уже
  пишется тиком) либо по записи гейта в том же окне/попытке — но не по факту «когда-либо отказывал
  отправитель».
- Знаменатель и числитель считаются по одному и тому же множеству строк.

## Acceptance criteria
- [ ] Строка, отказанная гвардом после давнего `topic_rate_limit`, попадает в числитель.
- [ ] Терминальный отказ отправителя по-прежнему в числитель не попадает.
- [ ] Тест воспроизводит именно последовательность «сначала лимит, потом гвард».

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Controllers/Admin/CommunityControllerTest.php`

## Implementation notes
- `guardDeniedCount()` теперь опознаёт отказ гварда через `EXISTS (COMMUNITY_ROUTE_LOGGED WHERE target_id=cm.id)`
  вместо `NOT EXISTS (COMMUNITY_ANSWER_SENT) AND NOT EXISTS (COMMUNITY_ANSWER_REJECTED)`. `logRoute()`
  (`CommunityAutoReplyHandler::resolveAndSend()`) пишет `COMMUNITY_ROUTE_LOGGED` ровно и только в момент
  отказа гварда, до вызова `sendAnswer()` — своя положительная запись, не зависящая от давней истории строки.
- Обновлены существующие тесты `testGuardRejectionRateIsShareOfEscalatedAmongAnsweredAndEscalated` и
  `testGuardRejectionRateExcludesSenderGateTerminalRejections` — «настоящий» отказ гварда теперь явно
  снабжён `COMMUNITY_ROUTE_LOGGED` (раньше отказ гварда распознавался по умолчанию отсутствием чужих записей).
- Добавлен `testGuardRejectionRateCountsGuardDenialAfterEarlierRateLimitRejection`: воспроизводит
  последовательность «сначала терминальный `topic_rate_limit` (давняя `COMMUNITY_ANSWER_REJECTED`), потом
  гвард денит ту же строку (`COMMUNITY_ROUTE_LOGGED`)» — строка попадает в числитель.
