---
story: community-chat-bot-44
spec: community-chat-bot
status: done
tier: 1
worker: worker-code
tracer: false
wave: 10
blocked_by: [community-chat-bot-42]
---

# Транзакция чистки сообщает о неудаче, а не рапортует успех

## Goal
Провал транзакции авто-закрытия перестаёт выглядеть как успешная чистка.

## Requirements
> собирал информацию и чтобы где-то ее агрегировал, сохранял

## Files
- app/Commands/CommunityCleanup.php
- tests/unit/Commands/CommunityCleanupTest.php

## Non-goals
- Не менять пороги, ключи настроек и расписание.
- Не трогать тесты и код вне чистки.

## В чём дефект
`cleanup()` (`:150-163`) обрамляет снимок и `UPDATE` парой `transStart()` / `transComplete()`,
но возврат `transComplete()` не проверяется. У нас уже записан урок на эту тему
(`feedback_transcomplete_false_success_when_strict_off`): при `strictOn=false` вызов сбрасывает
статус и может отрапортовать успех там, где транзакция не прошла.

Последствие конкретное: команда вернёт `staleClosed` больше нуля и запишет
`COMMUNITY_QUESTION_AUTO_CLOSED` в журнал, хотя строки остались `new`. KPI «Закрыто чисткой без
ответа» на экране владельца покажет закрытия, которых не было, а сами вопросы продолжат висеть.

Соседний код волны 10 (`CommunityAutoReplyHandler::claimGroup()` после story 40) обрабатывает
это правильно: там проверяется и старт транзакции, и её исход.

## Contract
- Исход транзакции проверяется по возврату вызова (либо по явному статусу), а не предполагается.
- При неуспехе команда не сообщает о закрытых строках и не пишет аудит авто-закрытия.
- Поведение при успехе не меняется.

## Acceptance criteria
- [ ] Неуспешная транзакция не даёт ни `staleClosed > 0`, ни записей `COMMUNITY_QUESTION_AUTO_CLOSED`.
- [ ] Успешный путь и его тесты не изменились.
- [ ] Тест воспроизводит именно неуспех транзакции, а не общий отказ БД.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Commands/CommunityCleanupTest.php`

## Implementation notes
`cleanup()` now captures `$ok = $db->transComplete();` and branches on the return value
instead of proceeding unconditionally — on failure `staleClosed` is forced to `0` and
`staleIds` cleared, so `auditAutoClosed()` never runs and no `COMMUNITY_QUESTION_AUTO_CLOSED`
row is written. Success path unchanged (audit still runs inside the `else` branch).

Test `testFailedTransactionDoesNotReportStaleClosedOrAudit` reproduces a genuine
transaction failure (not a generic DB outage) via a MySQL `BEFORE UPDATE` trigger that
`SIGNAL`s only for the sentinel row's id, forcing the `UPDATE` inside the cleanup
transaction to fail while leaving everything else untouched.

Surprise: CI4's `BaseConnection::transStatus` is *not* reset by `transBegin()` — only
`resetTransStatus()` does, and this project never sets `transStrict(false)` anywhere
(the `strictOn` config key only toggles MySQL's SQL strict mode, not CI4's internal
transaction-strict flag — the two are unrelated despite sharing a name). So a failed
transaction on a shared connection object leaves `transStatus=false` for good; PHPUnit
reuses one connection across test methods, so the test must call `$db->resetTransStatus()`
in its `finally` block or it silently breaks the next test that starts a transaction on
the same connection (confirmed: `testRunIgnoresCommunityEnabledKillswitch` failed only
when run after this test, not in isolation, until the reset was added). This is a test-
isolation artifact of the shared connection, not a production concern (each CLI/cron
invocation gets its own fresh, non-persistent connection — `pConnect=false`).
