---
story: community-chat-bot-17
spec: community-chat-bot
status: todo
tier: 1
worker: worker-code
tracer: false
wave: 5
blocked_by: [community-chat-bot-10, community-chat-bot-16]
---

# Связка: модерация вызывается на входящем сообщении

## Goal
`CommunityModerationService::evaluate()` перестаёт быть недостижимым кодом и вызывается
на каждом групповом апдейте рядом с приёмом. Третий случай в этой спеке, когда сервис
написан, покрыт тестами и не подключён ни к чему — и последний.

## Requirements
> чтобы была живая группа, потому что я не всегда успеваю, не всегда могу

> добавить в чат, где идет общение всех игроков

## Files
- app/Controllers/Telegram/BotController.php
- tests/unit/Controllers/Telegram/BotControllerModerationWiringTest.php

## Non-goals
- Не трогать `CommunityModerationService` — он готов (story 10).
- Не менять гейт по типу чата и его положение относительно firehose и хуков E6/E8.
- Не трогать связку приёма из story 16 и её тесты.
- Не добавлять модерации новых правил: только вызов.

## Map slice
`app/Controllers/Telegram/BotController.php` — `handleCommunityUpdate()` после story 16
делегирует в `CommunityIngestService::handle()`. Вход модерации:
`App\Services\Community\CommunityModerationService::evaluate(array $update): void`.

## Почему в вебхуке, а не в тике планировщика
Модерация в боевом режиме удаляет сообщение. Минута задержки до следующего тика — это
минута, которую скам-ссылка висит в живом чате и её успевают увидеть. Приём и модерация
смотрят на один и тот же апдейт, и обоим нужен он целиком.

## Contract
- Порядок: сначала приём (`CommunityIngestService::handle()`), потом модерация. Модерация
  считает стаж автора по `community_messages`, и её сервис уже умеет исключать текущую
  строку — но порядок «сначала записали, потом судим» делает поведение однозначным.
- Оба вызова независимы: падение модерации не мешает приёму и наоборот. Отдельные
  `try/catch` с `log_message('error', ...)`, а не один общий на оба.
- Ответ на групповой апдейт остаётся `200` с пустым телом при любом исходе.

## Acceptance criteria
- [ ] Групповой апдейт доходит до `CommunityModerationService::evaluate()` ровно один раз.
- [ ] Приватный апдейт до модерации не доходит.
- [ ] Исключение в модерации не мешает приёму записать сообщение.
- [ ] Исключение в приёме не мешает модерации отработать.
- [ ] Ни одно исключение не меняет ответ вебхука: `200`, пустое тело, ошибка в логе.
- [ ] Тесты story 01 и story 16 остаются зелёными без правок.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Controllers/Telegram/`

## Implementation notes

## Findings
