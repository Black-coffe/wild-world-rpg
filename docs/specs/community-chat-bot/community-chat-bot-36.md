---
story: community-chat-bot-36
spec: community-chat-bot
status: done
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

## Implementation notes

Все четыре теста переведены на схему из реальных миграций (`Adr176CreateCommunityMessagesTable`,
`Adr176CreateCommunityAnswersTable` — где нужен `community_answers`, `CreateAdminAuditLogTable` —
где нужен `admin_audit_log`), тем же паттерном Forge, что `CommunityCleanupTest`/`CommunityExportTest`.
Ручной `CREATE TABLE` убран из всех четырёх файлов целиком (остались только упоминания в docblock).

Находка при переходе (`CommunityChatSenderTest`): реальная миграция несёт
`UNIQUE(chat_id, message_id)` — продовый инвариант идемпотентности повторной доставки
Telegram-апдейта. Ручная изолированная схема этого теста такого ограничения не имела, и
`insertMessage()` использовала фиксированный дефолт `message_id => 999` для всех вставок
одного чата — семь тестов (`testHourlyCeilingSilencesCompletelyNotEveryOther`,
`testSecondAnswerToSameAuthorWithinCooldownBlocked`,
`testHourlyCeilingIsAccurateWhenAppAndDbClocksDiverge`,
`testAuthorCooldownIsAccurateWhenAppAndDbClocksDiverge`,
`testReactStillWorksAfterHourlyAnswerCeilingExhausted`, `testManualAnswerIgnoresHourlyCeiling`,
`testManualAnswerIgnoresAuthorCooldown`) красными упали на `Duplicate entry` при первой же
повторной вставке в тот же чат. Починено в пределах тестовой фикстуры: дефолт `message_id`
сделан уникальным на каждый вызов (`static $seq`, паттерн `CommunityAutoReplyHandlerTest`) —
набор утверждений не менялся, только фикстура. Реальный Telegram никогда не шлёт два разных
апдейта с одним `message_id` в одном чате, так что уникальность — корректное поведение
фикстуры, не подгонка под баг.

Остальные три файла (`CommunityControllerTest`, `CommunityModerationServiceTest`,
`CommunityAutoReplyHandlerTest`) уже генерировали `message_id` уникально (через `static $seq`
или `random_int`) — на схеме из миграции все тесты зелёные без правок фикстур сверх смены
источника схемы.
