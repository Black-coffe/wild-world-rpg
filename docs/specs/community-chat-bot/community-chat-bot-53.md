---
story: community-chat-bot-53
spec: community-chat-bot
status: todo
tier: 1
worker: worker-test
tracer: false
wave: 12
blocked_by: [community-chat-bot-52]
---

# Тест не должен требовать боевого API-ключа

## Goal
Набор перестаёт зависеть от наличия `telegram.API_KEY` в окружении.

## Requirements
> чтобы он от своего имени, от бота, давал ответы на вопросы

## Files
- tests/unit/TaskHandlers/CommunityAutoReplyHandlerTest.php

## Non-goals
- Не трогать продовый код (`CommunityAutoReplyHandler`, `BaseTaskHandler`).
- Не менять смысл существующих проверок.

## В чём дефект
После story 52 тик инициализирует Telegram перед отправкой. Тест
`testGuardDenialInsertFailureRollsBackStatusInsteadOfLosingMetricSignal` (story 46) собирает
хендлер напрямую, минуя фабрику `handler()` с no-op инициализатором, поэтому доходит до реального
`BaseTaskHandler::telegram()`. Локально это проходит (в `.env` есть ключ), на CI — нет:

```
Longman\TelegramBot\Exception\TelegramException: Invalid API KEY defined!
  BaseTaskHandler.php:51
  CommunityAutoReplyHandler.php:155
```

Тест не должен зависеть от того, лежит ли на машине боевой ключ, — это ровно тот класс
«локально зелено, на CI красно», который мы уже разбирали в этой спеке дважды.

**Отдельная находка, НЕ в скоупе этой story** (общий базовый класс, за пределами спеки): аварийная
ветка `BaseTaskHandler::telegram()` при неудачной инициализации создаёт `new Telegram('invalid',
'invalid')` (`:51`) — конструктор бросает то же самое исключение, то есть «пустышка, чтобы код ниже
не падал» сама падает. Комментарий рядом обещает обратное. На проде ключ валиден, поэтому ветка
не исполняется; поднимать ли это отдельно — решение Королевы.

## Contract
- Тест использует тот же no-op инициализатор, что и остальные тесты файла.
- Набор проходит в окружении без `telegram.API_KEY`.
- Проверяемое поведение (откат статуса при сбое аудит-вставки) не меняется.

## Acceptance criteria
- [ ] Тест не создаёт реальный объект Telegram.
- [ ] Смысл проверки прежний: сбой вставки откатывает статус.
- [ ] Файл зелёный.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/TaskHandlers/CommunityAutoReplyHandlerTest.php`
