---
story: community-chat-bot-40
spec: community-chat-bot
status: done
tier: 2
worker: worker-code
tracer: false
wave: 10
blocked_by: []
---

# Тик: транзакция отказывает закрыто, часы проверяются по-настоящему, глушёный топик не копится у владельца

## Goal
Гарантия «всё-или-ничего» перестаёт зависеть от того, стартовала ли транзакция, тест часов
начинает уметь краснеть, а намеренно заглушённый топик перестаёт превращаться в ручную работу.

## Requirements
> чтобы он от своего имени, от бота, давал ответы на вопросы

> чтобы была живая группа, потому что я не всегда успеваю, не всегда могу

## Files
- app/TaskHandlers/Community/CommunityAutoReplyHandler.php
- tests/unit/TaskHandlers/CommunityAutoReplyHandlerTest.php

## Non-goals
- Не трогать гвард, отправителя, матчер, админку, чистку.
- Не менять состав `TERMINAL_GATE_REASONS`, кроме того, что требует пункт 3.

## В чём дефект
1. `:321` — возврат `transBegin()` игнорируется. Если транзакция не стартовала (или хендлер
   когда-нибудь вызовут внутри внешней — сегодня ни `Worker.php`, ни `BaseTaskHandler` её не
   открывают, ревьюер проверил), откат вырождается в no-op, частичный перехват коммитится, и
   возвращается ровно дефект, который story 31 закрывала.
2. `testRouteLogTimestampUsesDatabaseClockNotPhpDate` утверждает лишь, что `created_at` попал
   между двумя `NOW()`. На машине с совпадающими часами и таймзоной это проходит и с PHP `date()` —
   тест не может покраснеть на дефекте, именем которого назван. Рядом есть образец, который умеет:
   тесты story 27 форсируют `Etc/GMT+12`.
3. `:180-183` — `silent_topic` стал терминальным (правильно), но вопрос из НАМЕРЕННО заглушённого
   топика уходит в `escalated`, а `escalated` — это очередь владельца на `/admin/community`. То есть
   заглушили топик, чтобы бот там молчал, — и получили в нём ручную работу вместо тишины.

## Contract
- Гарантия всё-или-ничего отказывает ЗАКРЫТО: если транзакция не стартовала или уже открыта
  снаружи, перехват не выполняется вовсе.
- Тест часов различает два источника времени, а не совпадает с ними обоими.
- Вопрос в заглушённом топике не копится у владельца как требующий ручного ответа. Какой статус
  этому соответствует — решение исполнителя, но оно обязано быть отличимым от «гвард отказал»
  и не попадать в очередь ручной работы.

## Acceptance criteria
- [ ] При невозможности начать транзакцию ни одна строка не перехвачена.
- [ ] Тест часов краснеет на PHP `date()` при расхождении таймзон.
- [ ] Вопрос из заглушённого топика не появляется в очереди `/admin/community`.
- [ ] Регрессия story 31 цела: частичный перехват по-прежнему откатывается целиком.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/TaskHandlers/CommunityAutoReplyHandlerTest.php`

## Implementation notes
- `claimGroup()`: `transBegin()` возврат больше не игнорируется — `false` (транзакция не
  стартовала/уже открыта снаружи) → отказ закрыто, `UPDATE` не выполняется вовсе.
- `resolveFailure()`: `TERMINAL_GATE_REASONS` разделены по целевому статусу —
  `silent_topic` → `'ignored'` (тот же терминальный статус, которым уже закрывается
  `Decision::isSilent()`), остальные причины (`text_too_long`/`unbalanced_markdown`/
  `canon_name_violation`) по-прежнему → `'escalated'`. `/admin/community` читает очередь
  как `whereIn('status', ['new','escalated'])`, `'ignored'` в неё не попадает.
- Тест часов (`testRouteLogTimestampUsesDatabaseClockNotPhpDate`) форсирует `Etc/GMT+12`
  на время вызова (паттерн story 27), иначе окно `[before, after]` из MySQL `NOW()`
  накрывало бы и PHP `date()` на машине с совпадающими часами.
- Добавлен `testClaimGroupClaimsNothingWhenTransactionCannotStart` — симулирует отказ
  `transBegin()` через публичное свойство `BaseConnection::$transEnabled`, без мока
  соединения.
- Обновлён существующий `testSilentTopicGivesOneLogEntryNotOnePerTick`: ожидание статуса
  `'escalated'` → `'ignored'`.
