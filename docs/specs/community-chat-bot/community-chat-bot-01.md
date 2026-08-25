---
story: community-chat-bot-01
spec: community-chat-bot
status: done
tier: 2
worker: worker-code
tracer: true
wave: 1
blocked_by: []
---

# Гейт по типу чата: групповой апдейт не идёт в игровой диспетчер

## Goal
Вебхук перестаёт пропускать апдейты из групп и супергрупп в `$this->telegram->handle()`.
Апдейт из группы распознаётся до игровой обработки, помечается и завершается `200 OK` без
единого исходящего сообщения. Групповой трафик перестаёт расходовать персональный
rate-limit игрока. После этой story бота можно физически добавить в чат, и он будет молчать.

## Requirements
> как добавить нашего же Telegram-бота, который уже есть, добавить в чат, где идет общение всех игроков

> Он должен работать только тогда, когда я запускаю cloud code.

## Files
- app/Controllers/Telegram/BotController.php
- app/Filters/TelegramRateLimitFilter.php
- tests/unit/Controllers/Telegram/BotControllerChatTypeGateTest.php
- tests/unit/TelegramRateLimitGroupScopeTest.php

## Non-goals
- Не создавать таблиц и моделей — схема это story 02.
- Не писать сохранение сообщений — приём это story 04.
- Не отправлять НИЧЕГО в группу. После этой story бот в чате нем.
- Не трогать `GenericmessageCommand` и ни один action-handler.
- Не менять поведение приватных апдейтов ни на йоту: `chat.type === 'private'` идёт
  прежним путём, включая существующие side-эффекты E6/E8 (`BotController.php:104-129`).
- Не вызывать `setWebhook` и не менять `allowed_updates` — это отдельная задача.

## Map slice
`memory/map/telegram.md` §Entry points. Ключевые строки: `BotController.php:45-153`
(`webhook()`, порядок: секрет → парсинг → `ActionOrigin::stripUpdate` →
`PlayerActionLogger::begin` → side-эффекты → `handle()` → `finally`);
`TelegramRateLimitFilter.php:64` (`DEFAULT_MAX_PER_MINUTE=60`), `:240-258`
(извлечение `from.id` из `callback_query`/`message`/`edited_message`/…).

## Contract (из plan.md)
- Тип чата берётся из `message.chat.type` / `edited_message.chat.type` /
  `callback_query.message.chat.type`. Значения `group` и `supergroup` — групповые;
  `private` — игровой путь; `channel` — тоже НЕ игровой путь (трактуем как групповой).
- Отсутствующий или неизвестный `chat.type` трактуется как `private` (иначе одна
  неожиданная форма апдейта обрушит игру для всех).
- Гейт стоит ДО `$this->telegram->handle()` и ДО side-эффектов E6/E8: групповое
  сообщение не должно двигать login-streak, ежедневки и return-digest.
- `PlayerActionLogger` на групповом апдейте не открывается (иначе firehose ADR-148
  заполнится чужим трафиком и сломает срезы воронки).
- Гейт возвращает `200 OK` с пустым телом — Telegram не должен ретраить.
- Точка расширения: гейт вызывает `handleCommunityUpdate(array $update): void`, который
  в этой story — пустой метод с комментарием «story 04 наполнит». Именно за этот метод
  зацепятся 04 и 05.
- Rate-limit: групповой апдейт считается в ОТДЕЛЬНОМ ключе кэша (не по `from.id` игрока)
  с собственным лимитом. Персональный игровой лимит 60/мин групповым трафиком не тратится.

## Acceptance criteria
- [ ] Апдейт с `message.chat.type='supergroup'` не доходит до `$this->telegram->handle()`
      и не порождает исходящих вызовов Telegram API.
- [ ] Тот же апдейт не открывает `PlayerActionLogger` и не трогает E6/E8-хуки.
- [ ] Апдейт с `chat.type='private'` обрабатывается ровно как сейчас (регрессионный тест
      на прежний путь).
- [ ] Апдейт без `chat` вообще (например `inline_query`) идёт прежним, приватным путём.
- [ ] 100 групповых сообщений подряд от одного `from.id` не расходуют его персональное
      окно rate-limit: следующий его личный игровой апдейт проходит.
- [ ] Ответ на групповой апдейт — HTTP 200 с пустым телом.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Controllers/Telegram/ tests/unit/TelegramRateLimitGroupScopeTest.php`

## Tracer
Тончайший срез через слой приёма: вебхук → распознавание типа чата → отбой. Если
окажется, что `BotController::webhook()` невозможно протестировать без живого
Longman-клиента, сообщить в `## Findings` — тогда story 04 и 05 пойдут через выделенный
сервис-приёмник, а не через метод контроллера, и контракт волны 2 поменяется.

## Implementation notes

- `BotController.php`: гейт вставлен сразу после парсинга `$update`, до извлечения
  `$telegramUserId`/`ActionOrigin::stripUpdate`/`PlayerActionLogger::begin()`. Тип чата
  берётся из `message.chat.type` / `edited_message.chat.type` /
  `callback_query.message.chat.type` через приватный `dig()`-хелпер (mixed-safe, phpstan
  L9 чист). `group`/`supergroup`/`channel` → `handleCommunityUpdate($update)` (пустой,
  комментарий «story 04 наполнит») + немедленный `200` с пустым телом. Остальное — private.
- **Тестируемость (см. Tracer в шапке story):** `webhook()` протестирован БЕЗ живого
  Longman — реальный `$this->telegram->handle()` вынесен за protected seam
  `dispatchToTelegram()` (тест переопределяет спаем, паттерн
  `feedback_taskhandler_telegram_init_in_tests`). Это НЕ архитектурный WALL: контроллер
  остался контроллером, story 04/05 могут цепляться либо за `handleCommunityUpdate()`,
  либо позже вынести в сервис — решение волны 2 не заблокировано.
  Constructor's `new Telegram(...)` уже был defensive (try/catch → `$telegram=null` при
  невалидном ключе, как в CI) — семы понадобились только вокруг `handle()`.
- `TelegramRateLimitFilter.php`: групповой/supergroup/channel трафик — отдельный ключ
  `tg_rate_group_{chat_id}` (не `tg_rate_{from_id}`), тот же лимит/окно (`maxPerMinute()`),
  без отдельного env-параметра (не запрошено — не overengineering). При блокировке
  группового ключа `notifyBlocked()` НЕ вызывается (бот молчит в общих чатах, Non-goals).
- Guide/Tips-вердикт: не выносился в этой story — гейт невидим игроку (анти-фича приёма,
  а не UX-поверхность); вердикт по всей ветке `community-chat-bot`, когда появится
  видимая механика (story 04/05), остаётся за Queen/Редколлегией.

## Findings

Не потребовалось — `webhook()` протестирован без живого Longman-клиента через
protected-seam `dispatchToTelegram()` (см. Implementation notes). Контракт волны 2 не
меняется: точка расширения `handleCommunityUpdate(array $update): void` в контроллере.

- Хвост в волну 2: групповой rate-limit получил ОТДЕЛЬНЫЙ счётчик (`tg_rate_group_{chat_id}`),
  но тот же числовой потолок, что персональный. Главное достигнуто — болтун в чате больше
  не выедает свой игровой лимит. Отдельная планка для группы понадобится, если чат окажется
  активнее ожидаемого; ключ под неё есть смысл заводить по факту, а не вслепую.

