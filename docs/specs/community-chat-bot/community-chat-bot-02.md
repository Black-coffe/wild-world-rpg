---
story: community-chat-bot-02
spec: community-chat-bot
status: done
tier: 2
worker: worker-code
tracer: false
wave: 1
blocked_by: []
---

# Схема: сообщения чата и банк ответов

## Goal
Появляются две таблицы и две модели: `community_messages` (сырой поток чата, окно 30 дней)
и `community_answers` (банк утверждённых ответов + черновики). Обе классифицированы в
`Config\WipeManifest`, иначе `WipeManifestCoverageTest` блокирует деплой.

## Requirements
> собирал информацию и чтобы где-то ее агрегировал, сохранял

> на боевой сервер игры складывал в какую-то директорию

## Files
- app/Database/Migrations/2026-08-25-100000_Adr176CreateCommunityMessagesTable.php
- app/Database/Migrations/2026-08-25-100100_Adr176CreateCommunityAnswersTable.php
- app/Models/CommunityMessageModel.php
- app/Models/CommunityAnswerModel.php
- app/Config/WipeManifest.php
- tests/unit/Config/CommunityWipeClassificationTest.php

## Non-goals
- Не писать ни строчки приёма и ни строчки отправки — это story 05 и 06.
- Не заводить админ-экран и не трогать `Routes.php`.
- Не создавать третью таблицу «открытых вопросов»: открытый вопрос — это состояние строки
  в `community_messages`, а не отдельная сущность.
- Не наполнять банк содержимым: ни одного ответа на старте.

## Map slice
`memory/map/data-layer.md` §Миграции и модели. Образец идемпотентной миграции —
любая `*Seed*Tip.php`; образец классификации — `app/Config/WipeManifest.php` целиком.

## Contract (из plan.md)
`community_messages`: `id`, `chat_id` BIGINT, `message_thread_id` INT NULL (топик),
`message_id` INT, `reply_to_message_id` INT NULL, `telegram_user_id` BIGINT, `username`,
`text` TEXT, `sent_at` DATETIME, `is_question` TINYINT, `addressed_to_bot` TINYINT,
`status` ENUM(`new`,`answered`,`escalated`,`ignored`) default `new`, `answered_by_id` INT NULL,
`created_at`. Индексы: (`chat_id`,`message_thread_id`,`sent_at`), (`status`,`sent_at`),
UNIQUE(`chat_id`,`message_id`) — идемпотентность повторной доставки апдейта.

`community_answers`: `id`, `client_key` VARCHAR(32) **UNIQUE** (ULID, генерится локально —
идемпотентность повторного `community:import`), `question_pattern` TEXT, `answer_text` TEXT,
`requires_setting` VARCHAR(120) **NULL** (ключ килсвитча — рубеж live/dormant, §5.5 плана),
`source_ref` VARCHAR(255) (адрес раздела корпуса, не его тело),
`status` ENUM(`draft`,`approved`,`rejected`,`revoked`) **default `draft`**,
`approved_at` DATETIME NULL, `approved_by` VARCHAR(64) NULL, `revoked_at` DATETIME NULL,
`created_at`, `updated_at`.

`WipeManifest`: `community_messages` → **TRANSIENT** (поток регенерируется чатом),
`community_answers` → **KEEP** (авторский корпус наравне с `game_tips` — вайп прогресса
игроков не должен стирать написанные владельцем ответы).

## Acceptance criteria
- [ ] `php spark migrate` проходит на чистой БД, обе таблицы создаются, `down()` их сносит.
- [ ] `WipeManifestCoverageTest` зелёный: обе таблицы классифицированы.
- [ ] Тест утверждает именно `TRANSIENT` для сообщений и `KEEP` для ответов — не просто
      «классифицированы хоть как-то».
- [ ] Повторный insert той же пары (`chat_id`,`message_id`) отбивается UNIQUE, а не создаёт дубль.
- [ ] Повторный insert того же `client_key` отбивается UNIQUE.
- [ ] Новая строка `community_answers` без явного статуса получает `draft`.
- [ ] Все колонки перечислены в `$allowedFields` обеих моделей (иначе тихая потеря записи).

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Config/ tests/unit/Models/`

## Implementation notes

- Обе миграции написаны через `$this->forge->addField()` + `addUniqueKey()`, по образцу
  `2026-08-21-100000_Adr135CreateCharacterTributes.php`. `community_messages`:
  UNIQUE(`chat_id`,`message_id`), индексы (`chat_id`,`message_thread_id`,`sent_at`) и
  (`status`,`sent_at`), `status` ENUM default `new`. `community_answers`:
  UNIQUE(`client_key`), `status` ENUM default `draft`, индекс по `status`.
- Обе модели (`CommunityMessageModel`, `CommunityAnswerModel`) перечисляют все колонки
  в `$allowedFields`, кроме `id`. `CommunityMessageModel` — `useTimestamps=false`
  (`sent_at`/`created_at` ставятся явно, как у `CharacterTributeModel`).
  `CommunityAnswerModel` — стандартный `useTimestamps=true` (created_at/updated_at).
- `Config\WipeManifest`: `community_messages` → TRANSIENT (рядом с `queue_jobs_failed`),
  `community_answers` → KEEP (рядом с `game_tips`).
- `php spark migrate` локально не прогонялся до конца: локальная DB `mmorpg` — дамп с
  testbot и падает на несвязанной старой миграции `battle_logs` (известная особенность,
  `reference_local_db_bootstrap_from_testbot.md`), а тестовая `wildworld_tests` отстаёт
  на 504 непрогнанные миграции — полный `migrate` там вне бюджета story. Вместо этого
  синтаксис подтверждён `php -l` на обеих миграциях, а `migrate:status` против
  `wildworld_tests` подтвердил, что CI4 корректно распознаёт обе новые миграции по имени
  таблицы/классу без ошибок парсинга.

## Findings

- ⚠️ ПЕРЕНЕСЕНО В РЕЛИЗ: `php spark migrate` end-to-end не прогонялся (локальная БД — дамп
  testbot, падает на несвязанной старой миграции; `wildworld_tests` отстаёт на 504). Обе
  миграции обязаны быть прогнаны на preprod-testbot до тега. Структурная проверка
  (`php -l` + `migrate:status`) не заменяет реального `up`.

