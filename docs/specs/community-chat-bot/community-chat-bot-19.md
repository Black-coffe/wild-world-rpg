---
story: community-chat-bot-19
spec: community-chat-bot
status: todo
tier: 1
worker: worker-code
tracer: false
wave: 7
blocked_by: [community-chat-bot-18]
---

# Кнопка «Одобрить» входит в открытую дверь

## Goal
`CommunityController` переключает две отправки на `sendManualAnswer()`. После этой story
обещание «при выключенных автоответах отвечает владелец вручную» становится правдой
end-to-end, а не только на уровне отправителя.

## Requirements
> Тут нужно спланировать, как он будет отвечать: автоматически, в ручном режиме, полуавтоматически

> чтобы была живая группа, потому что я не всегда успеваю, не всегда могу

## Files
- app/Controllers/Admin/CommunityController.php
- tests/unit/Controllers/Admin/CommunityControllerTest.php

## Non-goals
- Не трогать `CommunityChatSender` — ручной путь там готов и покрыт (story 18).
- Не менять логику одобрения, отзыва, стирания и метрик: меняются ровно две отправки.
- Не снимать перепроверку `CommunityGuard` при одобрении.
- Не ломать атомарность: при отказе отправки статус по-прежнему не меняется.

## Почему это отдельная story
Четвёртый случай в этой спеке, когда механизм построен, а вызвать его некому — и он
целиком на совести планирования, а не воркеров. Story 18 по своим `## Files` не имела
права трогать контроллер, story 12 к моменту её появления была закоммичена. Шов без
владельца остаётся швом без владельца.

## Место дефекта
`CommunityController.php:252` (`approveAnswer`) и `:307` (`revokeAnswer`) зовут
`sendAnswer()` — автоматический путь с гейтом `community.autoreply.enabled`. Нужен
`sendManualAnswer()`.

## Contract
- Обе отправки переходят на `sendManualAnswer()`.
- Поведение при успехе и отказе прежнее: отказ отправки не меняет статус.
- Тест обязан прямо утверждать сквозное свойство: **при `community.autoreply.enabled=false`
  одобрение отправляет ответ и переводит строку в `approved`.** Именно этого сквозного
  утверждения не хватало, чтобы дефект не появился.

## Acceptance criteria
- [ ] При `community.autoreply.enabled=false` одобрение отправляет ответ и меняет статус.
- [ ] При `community.autoreply.enabled=false` отзыв отправляет поправку и меняет статус.
- [ ] При `community.enabled=false` не проходит ни одобрение, ни отзыв.
- [ ] Отказ отправки по-прежнему оставляет статус нетронутым (атомарность).
- [ ] Перепроверка `CommunityGuard` при одобрении сохранена.
- [ ] Остальные тесты story 12 зелёные без правок.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Controllers/Admin/`

## Implementation notes

## Findings
