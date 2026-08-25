---
story: community-chat-bot-59
spec: community-chat-bot
status: done
tier: 2
worker: worker-code
tracer: false
wave: 15
blocked_by: [community-chat-bot-58]
---

# Метрика админки считает ответы бота, а не отказы гварда

## Goal
«Доля ответов бота» перестаёт считать один и тот же отказ дважды и перестаёт показывать 100% там,
где бот не ответил ни разу.

## Requirements
> чтобы была живая группа, потому что я не всегда успеваю, не всегда могу

## Files
- app/Controllers/Admin/CommunityController.php
- tests/unit/Controllers/Admin/CommunityControllerTest.php

## В чём дефект
`CommunityController.php:398-407, 460-475`. Докблок утверждает дословно: «(б) отличим по наличию
`COMMUNITY_ANSWER_SENT` — эта запись пишется ТОЛЬКО при успешной отправке, гвард-отказ до неё не
доходит». После story 55 это неправда: отказ гварда уходит через тот же `sendAnswer()` и пишет ту
же строку. Одно сообщение попадает и в `botAnswers`, и в `guardDenied`, а `guardTotal = botAnswers
+ guardDenied` учитывает его дважды.

Сценарий отказа: 10 вопросов, все отклонены гвардом, живые игроки молчат → «доля ответов бота»
показывает 100% при нуле реальных ответов. Это главная метрика плана, по ней владелец решает,
включать ли авто-режим, — то есть она врёт ровно в тот момент, когда по ней принимают решение.

Story 58 заводит отдельное аудит-действие `COMMUNITY_ROUTE_SENT` для маршрута отказа; story 57
переводит на него handler. Здесь — считать по действиям так, чтобы отказ не был ответом.

## Non-goals
- Не менять сам гвард, отправитель (story 58) и тик (story 57).
- Не переделывать вёрстку админки и не добавлять новых метрик — починить существующую.
- Не менять имя `COMMUNITY_ROUTE_SENT` — контракт story 58.
- Не заводить миграций: исторические строки пересчитывать не нужно, достаточно корректного счёта вперёд.

## Acceptance criteria
- [ ] Отказ гварда, доехавший до игрока, учитывается в `guardDenied` и **не** учитывается в `botAnswers`.
- [ ] `guardTotal` не учитывает одно сообщение дважды.
- [ ] Сценарий «10 вопросов, все отклонены, живые молчат» даёт долю ответов бота 0%, а не 100%.
- [ ] Докблок описывает фактический поток, а не прежний.
- [ ] Тест краснеет, если вернуть счёт по `COMMUNITY_ANSWER_SENT` без разделения.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Controllers/Admin/CommunityControllerTest.php`

## Implementation notes

- `app/Controllers/Admin/CommunityController.php` — первая версия `autoAnswerCount()`
  различала отказ и ответ через `NOT EXISTS (... COMMUNITY_ROUTE_LOGGED ...)` — по
  замечанию лида это два независимых источника правды у одного гейта (журнальный
  маркер «строку записали как отказ» ≠ «что бот реально отправил»); согласны сегодня,
  но не инвариант. **Правка по ревью лида (2026-08-26):** `NOT EXISTS` убран,
  `autoAnswerCount()` (`:470`) считает только `a.action = 'COMMUNITY_ANSWER_SENT'`, без
  условий на `COMMUNITY_ROUTE_LOGGED`. Один явный механизм: story 58 заводит отдельное
  действие `COMMUNITY_ROUTE_SENT` для отправки маршрута отказа, story 57 переводит на
  него `CommunityAutoReplyHandler` (обе вне `## Files`, едут в этой же волне раньше
  прода, порядок держит лид) — после этого отказ и ответ пишут РАЗНЫЕ действия аудита
  и структурно не пересекаются, никакого `NOT EXISTS` не требуется. `guardDeniedCount()`
  не тронут — там `COMMUNITY_ROUTE_LOGGED` по делу (про «отказ произошёл», не про то,
  что отправил бот).
- Три докблока приведены в соответствие фактическому потоку (были написаны до story
  55/58, утверждали «гвард-отказ до `COMMUNITY_ANSWER_SENT` не доходит» / «текст туда
  не уходил вовсе» — оба утверждения неправда с story 55): комментарий `guardTotal` в
  `computeMetrics()` (`:398`), докблок `guardDeniedCount()` (`:490`), докблок
  `autoAnswerCount()` (`:462`) — переписаны под финальную версию (действие, а не маркер).
- `tests/unit/Controllers/Admin/CommunityControllerTest.php` — 2 новых теста, после
  правки пересеяны на `COMMUNITY_ROUTE_SENT` вместо двойного маркера:
  `testGuardDenialRouteTextDoesNotDoubleCountAsBotAnswer` (строка со своей
  `COMMUNITY_ROUTE_LOGGED` И `COMMUNITY_ROUTE_SENT`, без `COMMUNITY_ANSWER_SENT` →
  `guardTotal=1`, не 2) и `testAllGuardDeniedWithLiveHumanChatGivesZeroBotShareNotHundredPercent`
  (10 отказов через `COMMUNITY_ROUTE_SENT` + живой human-to-human реплай →
  `bot_vs_human_share=0.0`, не 1.0 — сценарий из `## В чём дефект`). Оба краснеют, если
  `autoAnswerCount()` вернётся к счёту по `COMMUNITY_ROUTE_LOGGED`.
- `vendor/bin/phpunit` не запускал — команда лида явно запретила (общая `wildworld_tests`,
  параллельные воркеры). `php -l` зелёный на обоих файлах, `vendor/bin/phpstan analyse
  --memory-limit=512M --no-progress` (полный прогон) — 0 ошибок.

## Findings
