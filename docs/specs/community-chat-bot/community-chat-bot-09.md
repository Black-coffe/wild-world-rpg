---
story: community-chat-bot-09
spec: community-chat-bot
status: done
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

- `CommunityAutoReplyHandler`: собственный килсвитч (`community.enabled` +
  `community.autoreply.enabled`) через инжектируемый `$settingsGetter`-callable
  (паттерн `CommunityChatSender`/`CommunityAnswerMatcher`, не `GameSettingsService`
  напрямую — держит handler свободным от cache-состояния между тестами).
- Тик забирает `status='new' AND is_question=1` по `sent_at, id`; в памяти держит
  `$handled` — множество id, уже получивших решение в этом тике (self +
  `Decision::coveredMessageIds`), чтобы дубли не матчились повторно и не
  пересчитывались, пока их решение уже применено.
- `answer_now`/`answer_after_delay` идут одним путём (`resolveAndSend()`):
  `requires_setting` матченной записи банка читается через `CommunityAnswerModel::find()`
  (null для `Decision::escalated`-fallback на `CommunityVoice::UNKNOWN`, у него нет
  `answerId`), затем `CommunityGuard::verdict()`. `allow` → `CommunityChatSender::sendAnswer()`
  на self-строку (реплай); успех → все `coveredMessageIds` разом получают `answered`
  (или `escalated`, если `Decision::escalated===true` — честное «не знаю» ушло, но
  вопрос всё равно ждёт человека). `manual`/`deny` → все covered id `escalated`, попытка
  реакции 🤔 на self (не гейтится результатом — эскалация видна в `/admin/community`
  по статусу независимо от того, ушла ли реакция).
- `answer_after_delay`: выдержка проверяется по `sent_at + delaySeconds` относительно
  инжектируемого `$now` (сеам, как у матчера) — ПЕРЕД самой отправкой обязательный
  повторный вызов `CommunityAnswerMatcher::isCancelledByHumanReply()`; отмена метит
  всю группу `ignored` (не `new` — не откладывает, отменяет навсегда).
- `receipt_only`: статус строки остаётся `new` (вопрос всё ещё открыт для будущего
  улучшения матча); дедуп реакции 👀 — не через новую колонку (её нет в `## Files`),
  а через существующий `admin_audit_log` (`COMMUNITY_REACTION_SENT` + `target_id`) —
  тот же источник правды, которым уже пользуется сам `CommunityChatSender` для
  потолка/кулдауна ответов.
- `silent` → self-строка `ignored` (уже покрыта более ранним дублем).
- Отказ `sendAnswer()` (гейты отправителя/сеть/Telegram not-ok) НЕ считается deny
  гварда — строка остаётся `new`, следующий тик повторит попытку сам (запрос
  `status='new'` заберёт её снова).
- Тест: изолированная схема `community_messages`/`community_answers`/`admin_audit_log`
  (паттерн `CommunityAnswerMatcherTest`/`CommunityChatSenderTest`) — не общая прод-схема.
  `CommunityChatSender` инжектируется с собственным `$transport`-callable — реальный
  Bot API и `Longman\Telegram` нигде не инициализируются.

## Findings
