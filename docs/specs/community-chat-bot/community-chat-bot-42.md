---
story: community-chat-bot-42
spec: community-chat-bot
status: todo
tier: 1
worker: worker-test
tracer: false
wave: 10
blocked_by: []
---

# Домести ручные схемы: два последних теста и снимок чистки

## Goal
Ни одного самодельного `CREATE TABLE` в тестах подсистемы не остаётся, а докблоки перестают
ссылаться на образец, который сам наполовину ручной.

## Requirements
> чтобы он от своего имени, от бота, давал ответы на вопросы

## Files
- tests/unit/Services/CommunityAnswerMatcherTest.php
- tests/unit/Commands/CommunityCleanupTest.php
- app/Commands/CommunityCleanup.php

## Non-goals
- Не трогать продовые схемы и миграции.
- Не переписывать утверждения тестов: чинится фикстура.

## В чём дефект
1. `CommunityAnswerMatcherTest:43-59` — единственный тест подсистемы, оставшийся на самодельной
   схеме: его не было в `## Files` story 36. Ревьюер проверил патченой копией с
   `UNIQUE(chat_id, message_id)` — 17/17 зелёные, скрытого зелёного-на-сломанном нет, но класс
   расхождения остался, и следующая правка схемы разойдётся снова.
2. `CommunityCleanupTest:57-60` — story 32 в той же волне, что story 36 убирала ручные схемы,
   завела свежий ручной `CREATE TABLE admin_audit_log`. Докблоки четырёх переведённых файлов при
   этом ссылаются на «паттерн `CommunityCleanupTest`» как на migration-based — теперь это верно
   наполовину.
3. `CommunityCleanup.php:145-152` — снимок id снят вне транзакции `UPDATE`, поэтому строка, ушедшая
   из `new` между `SELECT` и `UPDATE`, получит незаслуженный `COMMUNITY_QUESTION_AUTO_CLOSED` и
   завысит новую KPI. Некритично, пока чистку запускает один процесс крона (у нас один узел и одно
   расписание) — но условие держится на инфраструктуре, а не на коде.

## Contract
- Схемы в обоих тестах строятся прогоном соответствующих миграций.
- Докблоки, ссылающиеся на образец, соответствуют действительности.
- Учтённой закрытой считается строка, которую этот же `UPDATE` действительно перевёл.

## Acceptance criteria
- [ ] Ручного `CREATE TABLE` в тестах подсистемы не осталось.
- [ ] Оба файла зелёные на схеме из миграций.
- [ ] Строка, ушедшая из `new` между снимком и записью, не попадает в KPI авто-закрытия.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Services/CommunityAnswerMatcherTest.php tests/unit/Commands/CommunityCleanupTest.php`
