---
story: community-chat-bot-25
spec: community-chat-bot
status: todo
tier: 2
worker: worker-code
tracer: false
wave: 8
blocked_by: []
---

# Гейт видит все конверты, а группа получает свой лимит

## Goal
Групповой апдейт не доходит до игрового диспетчера ни в одном конверте, который Telegram
доставляет. Живой чат перестаёт делить одно rate-окно размером с лимит одного игрока.

## Requirements
> как добавить нашего же Telegram-бота, который уже есть, добавить в чат, где идет общение всех игроков

## Files
- app/Controllers/Telegram/BotController.php
- app/Filters/TelegramRateLimitFilter.php
- app/Database/Migrations/2026-08-25-120000_Adr176CommunityRateLimitSetting.php
- tests/unit/Controllers/Telegram/BotControllerChannelEnvelopeTest.php
- tests/unit/TelegramRateLimitGroupCeilingTest.php

## Non-goals
- Не менять порядок гейта относительно firehose и хуков E6/E8.
- Не трогать приём и модерацию.
- Не менять персональный лимит игрока.

## Два дефекта

**1. Конверты.** `extractChatType()` перебирает `message`, `edited_message`,
`callback_query.message`. Апдейт `channel_post` / `edited_channel_post` ключа `message` не
имеет → тип не определён → срабатывает fail-safe «считаем приватным» → апдейт уходит в
игровой диспетчер. При этом тип `channel` сознательно внесён в список групповых: намерение
было, конверт не покрыт. То же в rate-limit-фильтре.

**2. Лимит на весь чат.** Групповой ключ `tg_rate_group_{chatId}` использует тот же
числовой лимит, что персональный (60/мин). Чат на пару сотен человек в прайм-тайм
превышает его легко, лишние апдейты глотаются с `200 OK` **до контроллера**, и дальше
каскадом: отмена отложенного ответа не видит реплай человека и бот перебивает живого;
модерация не видит скам; счётчик «человек ответил человеку» занижен, то есть главная
метрика врёт в сторону «бот победил».

## Contract
- Тип чата определяется во всех конвертах, которые Telegram доставляет по текущим
  настройкам, включая `channel_post` и `edited_channel_post`. Неизвестный конверт
  по-прежнему трактуется как приватный — этот fail-safe остаётся.
- У группового ведра собственный лимит, admin-tunable ключом в `GameSettings` (категория
  `experimental`, полный набор rationale/above/below и границы). Стартовое значение
  рассчитано на чат, а не на игрока.
- Персональное окно игрока не меняется ни по ключу, ни по величине.

## Acceptance criteria
- [ ] `channel_post` из настроенного чата не доходит до игрового диспетчера.
- [ ] `edited_channel_post` — то же.
- [ ] Апдейт неизвестного конверта по-прежнему идёт приватным путём (fail-safe цел).
- [ ] Групповой лимит читается из `GameSettings` и отличается от персонального.
- [ ] Превышение группового лимита не расходует персональное окно игрока.
- [ ] Тесты story 01, 16 и 17 зелёные без правок.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Controllers/Telegram/`

## Implementation notes

## Findings
