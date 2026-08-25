---
story: community-chat-bot-33
spec: community-chat-bot
status: done
tier: 1
worker: worker-code
tracer: false
wave: 9
blocked_by: []
---

# Анонимный человек — тоже человек; молчаливый откат лимита получает голос

## Goal
Реплай администратора группы, скрывшего себя, снова отменяет выдержку, а падение группового
потолка на персональный перестаёт быть невидимым.

## Requirements
> когда игроки что-то спрашивают, уточняют по игре, он отвечал

> как добавить нашего же Telegram-бота, который уже есть, добавить в чат, где идет общение всех игроков

## Files
- app/Services/Community/CommunityAnswerMatcher.php
- app/Filters/TelegramRateLimitFilter.php
- tests/unit/Services/CommunityAnswerMatcherTest.php

## Non-goals
- Не трогать пороги полос, склейку, гвард, тик.
- Не менять сам групповой потолок и его ключ настройки.

## В чём дефект
1. `where('telegram_user_id !=', $authorId)` отсекает и строки с `telegram_user_id IS NULL` —
   анонимный админ группы, пост от имени канала. Такой человеческий реплай выдержку не отменит,
   и бот перебьёт живого помогающего человека. Это ровно то, ради чего тормоз существует.
2. Падение группового потолка на персональный при отсутствующей строке настройки происходит
   молча. Если ключ переименуют или удалят, чат тихо вернётся к 60/мин — к тому самому дефекту,
   который story 25 чинила, и наблюдаемого сигнала об этом не будет.

## Contract
- Отменяет выдержку реплай любого участника, кроме автора вопроса; неизвестный автор реплая
  (`NULL`) считается ДРУГИМ человеком, а не автором.
- Откат группового потолка на персональный оставляет наблюдаемый след — по существующей
  инфраструктуре логов, без новых таблиц.

## Acceptance criteria
- [ ] Реплай с `telegram_user_id IS NULL` отменяет выдержку.
- [ ] Реплай автора по-прежнему не отменяет (регрессия story 29 цела).
- [ ] Отсутствие строки настройки группового потолка оставляет след, по которому это видно.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Services/CommunityAnswerMatcherTest.php`

## Implementation notes
- `CommunityAnswerMatcher::isCancelledByHumanReply()`: голое `where('telegram_user_id !=', $authorId)`
  заменено на `groupStart()->where('telegram_user_id !=', $authorId)->orWhere('telegram_user_id IS NULL')->groupEnd()` —
  эта версия CI4 (`BaseBuilder`) не несёт `whereNull()`/`orWhereNull()`, поэтому NULL-ветка выражена
  сырым условием `orWhere('telegram_user_id IS NULL')`, а не именованным хелпером.
- `TelegramRateLimitFilter::groupMaxPerMinute()`: отсутствие строки `game_settings` для
  `experimental.community_chat.rate_limit_per_minute` теперь проверяется напрямую через
  `GameSettingsModel::findByKey()` (не по совпадению значения с fallback'ом — оно может совпасть
  случайно) и логируется через `log_message('error', ...)`, throttled одним логом на окно тем же
  cache-TTL приёмом, что `notified` в `before()` — иначе флуд группового чата печатал бы строку на
  каждый апдейт, пока настройка не заведена.
- `tests/unit/Services/CommunityAnswerMatcherTest.php`: изолированная тестовая схема
  `community_messages.telegram_user_id` переведена с `BIGINT NOT NULL` на `BIGINT NULL` (только в
  тестовой таблице — продовая миграция вне `## Files`, не тронута); добавлен
  `testDelayIsCancelledByAnonymousGroupAdminReply`.
- Третий acceptance-критерий (наблюдаемый след отката группового потолка) проверен только логически
  и phpstan — story не несёт файла теста для `TelegramRateLimitFilter` (существующие
  `tests/unit/TelegramRateLimitFilterTest.php` и соседи вне `## Files`, не трогал).
