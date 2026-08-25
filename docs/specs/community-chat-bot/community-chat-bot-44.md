---
story: community-chat-bot-44
spec: community-chat-bot
status: todo
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
