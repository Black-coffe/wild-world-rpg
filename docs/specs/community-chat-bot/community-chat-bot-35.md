---
story: community-chat-bot-35
spec: community-chat-bot
status: done
tier: 2
worker: worker-code
tracer: false
wave: 9
blocked_by: [community-chat-bot-33]
---

# Анонимный автор: сделать ветку достижимой или признать её мёртвой

## Goal
Ветка «автор реплая неизвестен» перестаёт быть кодом, который прод исполнить не может, а
сообщения от имени канала перестают молча пропадать на входе.

## Requirements
> когда игроки что-то спрашивают, уточняют по игре, он отвечал

## Files
- app/Services/Community/CommunityIngestService.php
- app/Services/Community/CommunityAnswerMatcher.php
- app/Database/Migrations/2026-08-25-100000_Adr176CreateCommunityMessagesTable.php
- tests/unit/Services/CommunityIngestServiceTest.php
- tests/unit/Services/CommunityAnswerMatcherTest.php

## Non-goals
- Не трогать гвард, тик, отправителя, админку.
- Не менять пороги полос и склейку.
- Не вводить новых таблиц.

## В чём дефект
Story 33 научила матчер считать реплай с `telegram_user_id IS NULL` человеческим. Проверка по
коду показывает, что такая строка в проде появиться не может:

- `2026-08-25-100000_Adr176CreateCommunityMessagesTable.php:48-51` — колонка `BIGINT(20)`
  **без** `'null' => true`, то есть `NOT NULL`.
- `CommunityIngestService.php:103-106` — если `message.from.id` не число, ingest выходит РАНЬШЕ
  записи. Сообщение от имени канала (`sender_chat` без `from`) не сохраняется вовсе.

Тест story 33 проходит только потому, что изолированная тестовая схема была переведена на
`BIGINT NULL` — то есть проверяет поведение, которое продовая схема запрещает. Это ровно тот
класс «зелёного теста на игрушечной схеме», который волна 8 пришла чинить в гварде.

Отдельно: анонимный админ группы приходит НЕ как `NULL`, а как `from.id = 1087968824`
(`GroupAnonymousBot`) — общий на всех анонимных админов. Значит если анонимным был сам автор
вопроса, реплай ЛЮБОГО другого анонимного админа выглядит как реплай автора самому себе, и
выдержка не отменится. Это тот же дефект, что чинила story 29, только с другой стороны.

## Contract
- Решение принимается явно и записывается: либо (а) сообщения без `from.id` сохраняются с
  неизвестным автором и колонка становится nullable, либо (б) ветка `IS NULL` признаётся мёртвой
  и удаляется вместе со своим тестом. Половинчатое состояние — код есть, достижимости нет —
  недопустимо.
- При варианте (а) миграция меняет колонку на nullable, и правило проекта соблюдается: новая
  или изменённая player-колонка сверяется с `Config\WipeManifest` (таблица уже классифицирована —
  проверить, что классификация остаётся верной).
- Анонимный админ (`GroupAnonymousBot`) не считается тем же человеком, что автор вопроса, если
  автор вопроса — не он сам.
- Ни один тест не опирается на схему, отличающуюся от продовой миграции.

## Acceptance criteria
- [ ] Тестовая схема `community_messages` совпадает с продовой миграцией.
- [ ] Выбранный вариант (а или б) реализован целиком, без остатков другого.
- [ ] Реплай анонимного админа на вопрос обычного игрока отменяет выдержку.
- [ ] Регрессия story 29 цела: реплай автора самому себе выдержку не отменяет.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Services/CommunityIngestServiceTest.php tests/unit/Services/CommunityAnswerMatcherTest.php`

## Implementation notes

Выбран вариант **(б)** — ветка `IS NULL` признана мёртвой и удалена.

- `CommunityIngestService.php` не тронут: `telegram_user_id` уже строго `NOT NULL` в
  проде (`2026-08-25-100000_Adr176CreateCommunityMessagesTable.php:48-51` без
  `'null' => true`), а анонимный админ группы приходит с `from.id = 1087968824`
  (`GroupAnonymousBot`) — это число, `is_numeric()` проходит, ingest уже сохраняет
  такую строку как обычное сообщение. Миграция и WipeManifest не тронуты — новой
  или изменённой колонки нет.
- `CommunityAnswerMatcher::isCancelledByHumanReply()` — убран `orWhere('telegram_user_id
  IS NULL')` (эта ветка недостижима на проде). Добавлена константа
  `GROUP_ANONYMOUS_BOT_ID = 1087968824`. Логика: если автор вопроса — НЕ анонимный
  админ, фильтр `telegram_user_id != $authorId` работает как раньше (реплай самого
  автора — не отмена, story 29). Если автор вопроса САМ анонимный админ
  (`authorId === GROUP_ANONYMOUS_BOT_ID`), фильтр по автору не применяется вовсе —
  ЛЮБОЙ реплай в тред отменяет выдержку, включая реплай с тем же общим id. Отличить
  «автор дописал сам себе» от «ответил другой анонимный админ» невозможно (оба несут
  один и тот же `from.id`) — выбрано консервативное поведение «отменять», чтобы бот
  не перебивал живого человека, который уже мог помочь (тот же принцип, что и в
  исходном тормозе: лучше промолчать лишний раз, чем встрять поверх ответившего).
- `tests/unit/Services/CommunityAnswerMatcherTest.php` — изолированная схема
  `community_messages` приведена в точное соответствие продовой миграции:
  `telegram_user_id BIGINT NOT NULL` (было `NULL`), `sent_at DATETIME NOT NULL` (было
  `NULL` — тоже расходилась с продом, хоть story её и не называла впрямую), `status`
  как `ENUM('new','answered','escalated','ignored')` вместо `VARCHAR(16)` (тоже
  расхождение со схемой прод-миграции, обнаруженное при сверке acceptance
  criteria «тестовая схема совпадает с продовой»). Тест
  `testDelayIsCancelledByAnonymousGroupAdminReply` (реплай с `telegram_user_id =>
  null`) заменён на два теста: реплай анонимного админа на вопрос обычного игрока
  (отменяет) и реплай анонимного админа, когда автор вопроса тоже был анонимным
  админом (отменяет — консервативная ветка).
- `tests/unit/Services/CommunityIngestServiceTest.php` не тронут: он и раньше
  создавал схему через саму миграцию (`up()`), а не через ручной SQL — уже совпадал
  с продом, отдельного дефекта там не было.
- Verification: `vendor/bin/phpunit --no-coverage --no-progress
  tests/unit/Services/CommunityIngestServiceTest.php
  tests/unit/Services/CommunityAnswerMatcherTest.php` — 33 теста, 71 assertion,
  зелёный. `vendor/bin/phpstan analyse --memory-limit=512M --no-progress` на обоих
  тронутых сервисах — без ошибок.
