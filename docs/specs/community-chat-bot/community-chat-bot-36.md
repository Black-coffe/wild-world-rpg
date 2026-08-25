---
story: community-chat-bot-36
spec: community-chat-bot
status: todo
tier: 2
worker: worker-test
tracer: false
wave: 9
blocked_by: [community-chat-bot-31, community-chat-bot-32, community-chat-bot-35]
---

# Тестовые схемы берутся из миграции, а не сочиняются заново

## Goal
Ни один тест подсистемы не проверяет поведение против схемы, которой на проде нет.

## Requirements
> чтобы он от своего имени, от бота, давал ответы на вопросы

## Files
- tests/unit/Controllers/Admin/CommunityControllerTest.php
- tests/unit/Services/CommunityChatSenderTest.php
- tests/unit/Services/CommunityModerationServiceTest.php
- tests/unit/TaskHandlers/CommunityAutoReplyHandlerTest.php

## Non-goals
- Не трогать продовый код и миграции — только тестовые фикстуры.
- Не переписывать сами проверки: набор утверждений остаётся тем же.
- Не трогать `CommunityCleanupTest` и `CommunityExportTest` — они уже строят схему из миграции.

## В чём дефект
Story 35 нашла, что изолированная схема `community_messages` в `CommunityAnswerMatcherTest`
разошлась с продовой миграцией сразу по трём колонкам: `telegram_user_id` был `NULL` вместо
`NOT NULL`, `sent_at` — `NULL` вместо `NOT NULL`, `status` — `VARCHAR(16)` вместо
`ENUM('new','answered','escalated','ignored')`. На такой схеме зелёным становится тест
поведения, которое прод запрещает: именно так story 33 «доказала» работу ветки, недостижимой
в бою.

Это не единичный случай. Ручной `CREATE TABLE` вместо миграции остался ещё в четырёх тестах
подсистемы (перечислены в `## Files`), тогда как `CommunityCleanupTest` и `CommunityExportTest`
уже строят схему прогоном самой миграции. Пока расхождение существует, любой следующий тест
может оказаться зелёным по той же причине — и мы этого не увидим.

`ENUM` здесь не косметика: на `VARCHAR` тест примет статус, который прод отвергнет вставкой
(урок проекта про strict-режим и enum-колонки).

## Contract
- Схема `community_messages` в этих тестах создаётся прогоном продовой миграции
  (`Adr176CreateCommunityMessagesTable`), как это уже сделано в `CommunityCleanupTest`.
- Если тесту нужны соседние таблицы (`community_answers`, `admin_audit_log`) — они строятся
  тем же способом, из своих миграций.
- Набор утверждений каждого теста сохраняется: story чинит фикстуру, а не проверки.
- Если после перехода на настоящую схему какой-то тест краснеет — это находка, а не помеха:
  зафиксировать её в отчёте, а не подгонять схему обратно под тест.

## Acceptance criteria
- [ ] Ни один из четырёх тестов не содержит ручного `CREATE TABLE` для таблиц подсистемы.
- [ ] Все четыре зелёные на схеме из миграции.
- [ ] Любое покраснение при переходе описано в отчёте с указанием, какое расхождение оно вскрыло.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Controllers/Admin/CommunityControllerTest.php tests/unit/Services/CommunityChatSenderTest.php tests/unit/Services/CommunityModerationServiceTest.php tests/unit/TaskHandlers/CommunityAutoReplyHandlerTest.php`
