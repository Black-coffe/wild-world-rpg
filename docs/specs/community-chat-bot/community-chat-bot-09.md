---
story: community-chat-bot-09
spec: community-chat-bot
status: todo
tier: 2
worker: worker-code
tracer: false
wave: 4
blocked_by: [community-chat-bot-06, community-chat-bot-08]
---

# Task-handler авто-ответа: выдержка, отправка, квитанция

## Goal
Связывается вся цепочка: фоновый тик берёт свежие сообщения, спрашивает матчер, проводит
текст через гвард и отдаёт отправителю. Здесь же реализуется выдержка полосы B — то, чего
нельзя сделать синхронно в вебхуке. После этой story бот впервые может заговорить.

## Requirements
> чтобы была живая группа, потому что я не всегда успеваю, не всегда могу

> чтобы он от своего имени, от бота, давал ответы на вопросы

## Files
- app/TaskHandlers/Community/CommunityAutoReplyHandler.php
- app/Config/Tasks.php
- tests/unit/TaskHandlers/CommunityAutoReplyHandlerTest.php

## Non-goals
- Не дублировать логику матча и гварда — только оркестрация трёх готовых сервисов.
- Не отправлять напрямую через `Request::sendMessage` в обход `CommunityChatSender`.
- Не трогать `Controllers/Worker.php` и обработку `character_tasks`: это чистый
  `Tasks.php`-хендлер, не игровая задача персонажа.
- Не делать отложенную публикацию черновиков из очереди модерации — она в story 12
  (одобрение отправляет само).

## Map slice
`memory/map/tasks-worker.md` §Scheduler. `app/Config/Tasks.php` — почти все хендлеры
`everyMinute()->singleInstance()`; образец соседнего хендлера — любой в
`app/TaskHandlers/Tips/DailyTipBroadcastHandler.php`.

Ловушка (`feedback_completion_handler_no_in_work_guard`): Worker выставляет статус ДО
`handle()`. Здесь она неприменима — это `Tasks.php`, не `character_tasks`, — но при
падении посреди пачки повторный тик не должен отвечать дважды.

## Contract (из plan.md)
- Регистрация: `everyMinute()->singleInstance()`, как соседи.
- Тик: выбрать `community_messages` со `status='new'` и `is_question=1`, отсортировав по
  `sent_at`; для каждого — `CommunityAnswerMatcher::match()`.
- `answer_now` → гвард → `sendAnswer()`; `answer_after_delay` → отправить, только если
  `sent_at + delay_seconds` уже прошло И в треде нет ответа человека; `receipt_only` →
  `react('👀')` один раз; `silent` → пометить `ignored`.
- Вердикт гварда `manual`/`deny` → строка помечается `escalated`, ответ не уходит, при
  необходимости ставится реакция 🤔.
- 🔴 **Перед публикацией `answer_after_delay` ОБЯЗАН вызываться
  `CommunityAnswerMatcher::isCancelledByHumanReply($message)`.** Решение матчера — снимок
  на момент матча, а человек мог ответить в тред за время выдержки. Метод существует
  специально для этого и вызвать его больше некому: не вызовешь — получится ровно тот
  BUILT-BUT-DEAD, из-за которого пришлось заводить story 16.
- Решение матчера несёт `coveredMessageIds` (склейка дублей). Все перечисленные там
  сообщения закрываются ОДНИМ ответом — не пятью подряд.
- Строка помечается `answered` **только после подтверждённой отправки** — иначе следующий
  тик промолчит там, где сообщение не ушло.
- Один тик не отправляет больше, чем позволяют потолки; остальное ждёт следующего тика.
- Ничего не делает при `community.enabled=false` или `community.autoreply.enabled=false`
  — молча, без записей об ошибке.

## Acceptance criteria
- [ ] При выключенном `community.autoreply.enabled` тик не отправляет ничего, но приём
      (story 05) продолжает работать.
- [ ] `answer_after_delay`: до истечения выдержки ответа нет, после — есть.
- [ ] Появившийся в треде ответ человека отменяет отложенную отправку навсегда, не
      откладывает её.
- [ ] Строка получает `answered` только если `sendAnswer()` вернул успех; при отказе
      Telegram остаётся `new` и повторяется на следующем тике.
- [ ] Повторный тик не отправляет второй ответ на уже отвеченное сообщение.
- [ ] Вердикт гварда `deny` → `escalated`, ноль отправок.
- [ ] Реакция 👀 ставится один раз, а не на каждом тике.
- [ ] Тест не инициализирует Telegram eagerly (`feedback_taskhandler_telegram_init_in_tests`).

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/TaskHandlers/`

## Implementation notes

## Findings
