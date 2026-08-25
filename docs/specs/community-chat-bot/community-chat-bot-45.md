---
story: community-chat-bot-45
spec: community-chat-bot
status: done
tier: 2
worker: worker-code
tracer: false
wave: 11
blocked_by: []
---

# Приём: анонимный админ остаётся человеком, обращением считается реплай Роби

## Goal
Фильтр посторонних ботов перестаёт отменять решение story 35, а реплай на чужого бота перестаёт
читаться как вопрос, адресованный Роби.

## Requirements
> когда игроки что-то спрашивают, уточняют по игре, он отвечал

## Files
- app/Services/Community/CommunityIngestService.php
- tests/unit/Services/CommunityIngestServiceTest.php

## Non-goals
- Не трогать матчер, гвард, тик, админку.
- Не заводить новых колонок и таблиц.

## В чём дефект
1. **Регрессия, внесённая нами.** Story 41 отсекает автора с `from.is_bot === true` до вставки
   (`:115`). Анонимный админ группы приходит именно так:
   `from = {id: 1087968824, is_bot: true, username: "GroupAnonymousBot"}`. Story 35 (волна 9)
   разбирала этот id поимённо и постановила обратное: его реплай — человек, он отменяет выдержку
   (`CommunityAnswerMatcher.php:69,183-218`). Теперь такая строка в таблицу не попадает вовсе,
   и ветка story 35 снова недостижима — тот самый half-state «код есть, достижимости нет»,
   ради устранения которого story 35 и писалась.

   Тесты этого не ловят по конструкции: все проверки story 35 вставляют строки напрямую, минуя
   приём. `grep 1087968824 tests/` даёт одно попадание — в тесте матчера.

   Сценарий: владелец отвечает игроку анонимно (он админ чата) → Роби реплая не видит → после
   выдержки публикует свой ответ поверх уже данного человеком. Плюс вопрос, заданный самим
   анонимным админом, теперь не принимается.

2. `repliesToBot()` (`:261-271`) возвращает `true` для реплая на сообщение ЛЮБОГО бота и ставит
   `addressed_to_bot=1`, переводя вопрос в полосу немедленного ответа. Игрок отвечает стороннему
   боту-модератору «а как получить X?» — Роби считает, что спросили его.

## Contract
- Посторонние боты не принимаются, но анонимный админ группы (`GroupAnonymousBot`) принимается
  и остаётся человеком в смысле story 35.
- Обращением к Роби считается реплай на сообщение именно Роби, а не любого бота.
- Достижимость ветки story 35 проверяется тестом НА ПРИЁМЕ, а не только на матчере.

## Acceptance criteria
- [x] Сообщение `GroupAnonymousBot` сохраняется; сообщение стороннего бота — нет.
- [x] Реплай анонимного админа отменяет выдержку (сквозной путь приём → матчер).
- [x] Реплай на сообщение стороннего бота не ставит `addressed_to_bot`.
- [x] Реплай на сообщение Роби по-прежнему ставит.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Services/CommunityIngestServiceTest.php tests/unit/Services/CommunityAnswerMatcherTest.php`

## Implementation notes
- `CommunityIngestService::handle()` — фильтр «сторонний бот не пишется» (`:115`) теперь
  делает исключение для `GROUP_ANONYMOUS_BOT_ID = 1087968824` (новая приватная константа,
  зеркало `CommunityAnswerMatcher::GROUP_ANONYMOUS_BOT_ID`): анонимный админ приходит с
  `is_bot: true`, но по story 35 остаётся человеком.
- `repliesToBot()` больше не смотрит на `is_bot` реплая вообще — сверяет
  `reply_to_message.from.username` с `$this->botUsername` (регистронезависимо), поэтому
  обращением к Роби считается реплай именно на его сообщение, а не на любого бота.
- Тесты: `testGroupAnonymousBotMessageIsStored` (приём), сквозной
  `testAnonymousAdminReplyReachesMatcherAsHumanReplyThroughIngest` (ingest → строка в
  БД → `CommunityAnswerMatcher::isCancelledByHumanReply()` на РЕАЛЬНОЙ вставленной строке,
  не на руками собранном массиве), `testReplyToOtherBotDoesNotMarkAddressedToBot`
  (негатив), `testReplyToBotMarksAddressedToBot` донастроен — реплай теперь несёт
  `username` бота, иначе новый чек его бы больше не засчитал.
- Матчер (`CommunityAnswerMatcher.php`) не тронут — это выходит за `## Files` истории.
