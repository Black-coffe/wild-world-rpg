---
story: community-chat-bot-48
spec: community-chat-bot
status: done
tier: 1
worker: worker-code
tracer: false
wave: 11
blocked_by: []
---

# Чистка проверяет старт транзакции, а объяснение перестаёт быть перевёрнутым

## Goal
Строки не закрываются молча вне транзакции, которая обязана их накрыть, а комментарий совпадает
с проверенным поведением CI4.

## Requirements
> собирал информацию и чтобы где-то ее агрегировал, сохранял

## Files
- app/Commands/CommunityCleanup.php
- tests/unit/Commands/CommunityCleanupTest.php

## Non-goals
- Не менять пороги, ключи настроек и расписание.
- Не трогать поведение успешного пути.

## В чём дефект
1. `:150` — возврат `transStart()` не проверяется, хотя story 44 сама назвала образцом
   `claimGroup()`, где проверяется и старт, и исход. Если транзакция не стартовала, `SELECT … FOR
   UPDATE` идёт без блокировки, `UPDATE` автокоммитится, `transComplete()` вернёт `false` →
   `staleClosed = 0` и аудита нет, хотя строки реально закрыты. Вопрос без ответа исчезает
   молча — ровно тот дефект, который закрывала story 32.
2. `:163-166` и тест `:327-331` объясняют фикс через «`strictOn=false` … `transStatus()` тихо
   возвращается в `true`». Всё наоборот: `transStrict` по умолчанию `true`, сброс происходит
   ТОЛЬКО при `transStrict === false`, а `strictOn` из `Config\Database` — это SQL-режим MySQL.
   `transStatus` залипает в `false` до явного `resetTransStatus()`. Это же написано в notes самой
   story 44 и в `finally` того же теста — файл противоречит сам себе в двух местах. В ноте vault'а
   неверное объяснение уже убрано, в коде и тесте осталось.

## Contract
- Строки не переводятся в `ignored`, если транзакция, которая обязана их накрыть, не стартовала.
- Обоснование проверки возврата `transComplete()` совпадает с проверенным поведением CI4 во всех
  местах, где записано.

## Acceptance criteria
- [x] Неудачный старт транзакции не оставляет закрытых строк и сообщает об этом.
- [x] Комментарии в коде и тесте не упоминают `strictOn` как причину.
- [x] Поведение успешного пути и тесты story 44 не изменились.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Commands/CommunityCleanupTest.php`
→ 12 tests, 30 assertions, OK (0 failures).

## Implementation notes
- `cleanup()` теперь проверяет возврат `transStart()` по образцу `claimGroup()`
  (`CommunityAutoReplyHandler`): при `false` `SELECT … FOR UPDATE`/`UPDATE` вообще не
  выполняются, пишется `log_message('error', …)`, `staleClosed` остаётся 0, аудит не пишется.
- Комментарии в коде (`:163` было) и в тесте (docblock `testFailedTransactionDoesNotReportStaleClosedOrAudit`)
  переписаны: причина липкости `transStatus` — `transStrict` по умолчанию `true` (не переключается
  в репо), `strictOn` из `Config\Database` — SQL-режим MySQL, к исходу транзакции отношения не имеет.
- Новый тест `testTransStartFailureLeavesRowsUntouchedAndUnreported` воспроизводит провал старта
  через `$db->transOff()` (единственный способ гарантированно получить `transStart()===false` без
  подмены соединения); `finally` возвращает `transEnabled = true` для изоляции от соседних тестов.
