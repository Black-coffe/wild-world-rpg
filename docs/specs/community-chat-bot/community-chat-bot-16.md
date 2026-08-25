---
story: community-chat-bot-16
spec: community-chat-bot
status: todo
tier: 1
worker: worker-code
tracer: false
wave: 3
blocked_by: [community-chat-bot-01, community-chat-bot-05]
---

# Связка: вебхук наконец зовёт приёмник

## Goal
`BotController::handleCommunityUpdate()` перестаёт быть пустым методом и вызывает
`CommunityIngestService`. До этой story приём написан, покрыт тестами и **не вызывается
ниоткуда** — классический BUILT-BUT-DEAD.

## Requirements
> добавить в чат, где идет общение всех игроков

> собирал информацию и чтобы где-то ее агрегировал, сохранял

## Files
- app/Controllers/Telegram/BotController.php
- tests/unit/Controllers/Telegram/BotControllerCommunityWiringTest.php

## Non-goals
- Не трогать `CommunityIngestService` — он готов и покрыт (story 05).
- Не менять гейт по типу чата и порядок его срабатывания (story 01): гейт стоит до
  firehose и до хуков E6/E8, и это должно остаться так.
- Не добавлять отправку: бот после этой story по-прежнему нем.
- Не расширять `handleCommunityUpdate()` логикой — только делегирование.

## Map slice
`app/Controllers/Telegram/BotController.php:64-67` (гейт и ранний возврат `200`),
`:264` (`handleCommunityUpdate()`, сейчас пустой), `:276` (`dispatchToTelegram()` —
образец protected-шва, которым story 01 сделала контроллер тестируемым).
Сигнатура приёмника: `App\Services\Community\CommunityIngestService::handle(array $update): void`.

## Почему это отдельная story
Дефект планирования, а не работы воркеров. Story 01 создала точку расширения пустой,
story 05 по Non-goals не имела права трогать контроллер, и связку не получил никто.
Найдено на приёмке story 05 по её собственной оговорке.

## Contract
- Вызов делегирующий: `handleCommunityUpdate()` создаёт сервис и зовёт `handle($update)`.
- Исключение из сервиса **не должно ломать вебхук**: обёрнуто в `try/catch` с
  `log_message('error', ...)`, как соседние E6/E8-хуки. Упавший приём чата не имеет права
  уронить обработку игровых апдейтов.
- Ответ на групповой апдейт остаётся `200` с пустым телом при любом исходе приёма.

## Acceptance criteria
- [ ] Групповой апдейт доходит до `CommunityIngestService::handle()` ровно один раз.
- [ ] Приватный апдейт до сервиса не доходит вовсе.
- [ ] Исключение внутри сервиса не меняет ответ вебхука: по-прежнему `200`, пустое тело,
      ошибка в логе.
- [ ] Гейт по-прежнему срабатывает ДО `PlayerActionLogger::begin()` и до хуков E6/E8 —
      регрессионный тест на порядок, а не только на факт вызова.
- [ ] Тесты story 01 остаются зелёными без правок.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Controllers/Telegram/`

## Implementation notes

## Findings
