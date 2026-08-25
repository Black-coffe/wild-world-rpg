---
story: community-chat-bot-62
spec: community-chat-bot
status: done
tier: 1
worker: worker-code
tracer: false
wave: 14
blocked_by: []
---

# Одни часы на таблицу: модерация и чистка переходят на время БД

## Goal
Строки, которые читаются одним оконным запросом, перестают писаться двумя разными часами.

## Requirements
> чтобы была живая группа, потому что я не всегда успеваю, не всегда могу

## Files
- app/Services/Community/CommunityModerationService.php
- app/Commands/CommunityCleanup.php
- tests/unit/Services/CommunityModerationServiceTest.php
- tests/unit/Commands/CommunityCleanupTest.php

## В чём дефект
`CommunityModerationService.php:492` и `CommunityCleanup.php:240` пишут `created_at` часами PHP
(`date()`), тогда как `CommunityChatSender::audit()` и `logRoute()` — часами БД (`NOW()`). Читаются
эти строки **одними и теми же** оконными запросами (`CommunityController::autoClosedCount()` и
соседние счётчики потолка). Расхождение часов PHP и БД сдвигает границу окна, и строка либо
попадает в счёт лишний раз, либо выпадает из него.

Это ровно тот класс, который чинила story 27 для отправителя, — здесь он остался в двух других
дверях. Урок проекта: `feedback_db_clock_seed_not_php_in_time_window_tests`.

## Non-goals
- Не переводить на `NOW()` всё подряд: только те записи, что читаются оконными запросами вместе с аудитом отправителя.
- Не трогать отправитель и тик — там уже `dbNow()`.
- Не менять TTL-логику чистки по существу, только источник времени.
- Не заводить миграций и не переписывать исторические строки.

## Acceptance criteria
- [ ] `created_at` в модерации и чистке пишется часами БД, тем же способом, что в отправителе.
- [ ] Тест сеет время часами БД (`NOW() - INTERVAL …`), а не `date()` в PHP, и краснеет при возврате PHP-часов.
- [ ] Сторона чистки (`CommunityCleanup`) покрыта тестом наравне с модерацией — обе двери, а не одна.
- [ ] Оконные счётчики админки дают одинаковый результат независимо от расхождения часов PHP и БД.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Services/CommunityModerationServiceTest.php`

## Implementation notes

- `CommunityModerationService::audit()` (строка ~492) переведён на новый приватный `dbNow()`
  (`SELECT NOW()` через `$this->db`, тот же приём, что `CommunityChatSender::dbNow()`),
  вместо `date('Y-m-d H:i:s')`.
- `CommunityCleanup::auditAutoClosed()` (строка ~240) — та же замена, свой `dbNow()` через
  `Database::connect()` (в команде нет DI-поля `$db`, только статический коннект — используется
  тот же паттерн, что у остальных запросов файла).
- TTL/зависшие-вопросы запросы (`WHERE sent_at < DATE_SUB(NOW(), ...)`) уже были на `NOW()` —
  не трогались (non-goal).
- Тест: `testAuditCreatedAtUsesDbClockWhenAppAndDbClocksDiverge` в `CommunityModerationServiceTest.php`
  — форсирует `date_default_timezone_set('Etc/GMT+12')`, вызывает `live`-удаление (пишет
  `COMMUNITY_MODERATION_DELETED`), затем сверяет `created_at` оконным запросом
  `NOW() - INTERVAL 1 MINUTE` (часами БД, не PHP `date()`) — на старом `date()` строка не
  попадает в окно и тест краснеет.
- Дополнено после уточнения story (лид добавил `CommunityCleanupTest.php` в `## Files`):
  `testAutoClosedAuditCreatedAtUsesDbClockWhenAppAndDbClocksDiverge` в `CommunityCleanupTest.php`
  — тот же приём (форс `Etc/GMT+12`, вызов `cleanup()`, сверка `created_at` окном
  `NOW() - INTERVAL 1 MINUTE`) для `COMMUNITY_QUESTION_AUTO_CLOSED`. Обе двери теперь
  покрыты симметрично.

## Findings
