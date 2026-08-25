---
story: community-chat-bot-05
spec: community-chat-bot
status: done
tier: 2
worker: worker-code
tracer: false
wave: 2
blocked_by: [community-chat-bot-01, community-chat-bot-02]
---

# CommunityIngestService: приём, топики, детектор вопроса

## Goal
Пустой метод `handleCommunityUpdate()` из story 01 наполняется: сообщение из группы
попадает в `community_messages` с топиком, автором и тредом; определяется, вопрос ли это,
адресован ли он боту, и не флудит ли автор. После этой story бот всё слышит и по-прежнему
молчит.

## Requirements
> проходился по всем веткам, собирал информацию

> собирал информацию и чтобы где-то ее агрегировал, сохранял

> Супергруппа с темами (топиками)

## Files
- app/Services/Community/CommunityIngestService.php
- tests/unit/Services/CommunityIngestServiceTest.php

## Non-goals
- Не отправлять ничего: отправитель — story 06.
- Не матчить с банком: матчер — story 08.
- Не модерировать ссылки и вербовку: story 10.
- Не менять `BotController` — точка входа `handleCommunityUpdate()` уже есть из story 01;
  если её сигнатуры не хватает, записать в `## Findings`, а не править контроллер (Закон 3).
- Не связывать автора сообщения с игровым персонажем — вынесено из объёма явным
  допущением плана §15.

## Map slice
`memory/map/telegram.md` §Entry points; форма апдейта Telegram: `message.message_thread_id`
(топик супергруппы), `message.reply_to_message`, `message.entities` (для `@упоминания`),
`message.from.{id,username}`.

## Contract (из plan.md §4)
- Запись идемпотентна по (`chat_id`,`message_id`) — повторная доставка апдейта не плодит строк.
- `addressed_to_bot = 1`, если: упоминание `@<bot_username>` в `entities`, ИЛИ реплай на
  сообщение бота, ИЛИ текст начинается с «Роби» (регистронезависимо).
- **Детектор вопроса — не по знаку «?»**: вопросительное слово (как / где / что / когда /
  почему / зачем / сколько / какой / можно ли / кто) ИЛИ «?» **И** длина выше минимума
  **И** текст не в чёрном списке междометий («серьёзно?», «да ну?», «правда?», «а?»).
- `is_question=0`, если сообщение начинается с обращения к конкретному человеку («Вась, …»)
  — это не разговор с ботом.
- Анти-флуд: свыше `community.ingest.max_questions_per_author_per_hour` вопросов от одного
  `telegram_user_id` за час — строка пишется, но `is_question=0`. Очередь владельца не
  должна набиваться мусором за пять минут троллинга.
- Ничего не пишется, если `community.enabled=false` или `chat_id` не совпал с настроенным.

## Acceptance criteria
- [ ] Сообщение из настроенной супергруппы пишется с верными `message_thread_id`,
      `reply_to_message_id`, `telegram_user_id`, `username`, `sent_at`.
- [ ] Повторный тот же апдейт не создаёт второй строки.
- [ ] «а как ваще крафтить» (без «?») → `is_question=1`.
- [ ] «серьёзно?» и «да ну?» → `is_question=0`.
- [ ] «Вась, а как ты качался?» → `is_question=0` (чужой разговор).
- [ ] `@bot где вода` → `addressed_to_bot=1`; реплай на сообщение бота → то же.
- [ ] Шестой вопрос от одного автора за час пишется с `is_question=0`.
- [ ] При `community.enabled=false` не пишется ничего.
- [ ] Сообщение из чужого чата (не настроенный `chat_id`) игнорируется полностью.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Services/`

## Implementation notes

- `CommunityIngestService::handle(array $update)` — новый публичный вход (имя метода не
  зафиксировано контрактом; выбран для будущей связки `handleCommunityUpdate()` следующей
  story). Fail-closed на `community.enabled` и `community.chat_id` (строгое сравнение строк).
- Детектор вопроса: приоритет проверок — 1) обращение к человеку («Вась, ») → сразу 0,
  2) точное совпадение с чёрным списком междометий, 3) минимальная длина, 4) «?» ИЛИ
  вопросительное слово/фраза целым словом (regex-границы вручную по классам `а-яёa-z0-9`,
  т.к. PCRE `\b` не понимает кириллицу без `(*UCP)`).
- Анти-флуд считает по `sent_at` окну `[sent_at-1h, sent_at)` (PHP-время сообщения, не
  `NOW()` БД) — детерминированно для тестов и не зависит от расхождения часов.
- `addressed_to_bot`: упоминание — **прямой regex-поиск `@<bot_username>` в тексте**
  (граница справа `(?![a-z0-9_])`, чтобы `@botname_fanclub` не засчитался за
  `@botname`), НЕ через `entities[].offset/length` — см. ремонт ниже. Реплай — по
  `reply_to_message.from.is_bot===true` (не по username, т.к. в чате один бот); префикс
  «Роби» — `mb_stripos` регистронезависимо.
- **Ремонт (2026-08-25, тот же день):** исходная версия резала упоминание через
  `mb_substr($text, $offset, $length, 'UTF-8')` по `entities[].offset/length`. Telegram
  считает эти смещения в UTF-16 code units, а не в символах — эмодзи вне BMP (🔥, 🤖, 🏆)
  занимает 2 такие единицы против 1 символа PHP-строки, поэтому срез уезжал при любом
  таком символе перед упоминанием, и `addressed_to_bot` тихо становился 0 на «полосе A»
  (адресное обращение, где бот обязан отвечать ВСЕГДА, даже «не знаю» — молчание здесь
  худший исход из всех, хуже неверного ответа). Тест это пропустил, т.к. позитивный кейс
  использовал bot username в самом начале текста (offset=0), где UTF-8/UTF-16
  расхождение не проявляется. Фикс убрал зависимость от offset/length целиком —
  теперь только прямой поиск подстроки в уже декодированном UTF-8 тексте, которым мы и
  так владеем целиком. Добавлены `testMentionAfterEmojiOutsideBmpStillMarksAddressedToBot`
  (красный на старом коде) и `testMentionOfDifferentUsernameWithBotNameAsPrefixIsNotAddressed`
  (граница подстроки).
- Bot username конфигурируем через необязательный конструкторный параметр (default —
  `getenv('telegram.BOT_USERNAME')`) — тестируем без завязки на `.env`.
- Тест: `game_settings` подменён анонимным двойником `GameSettingsModel::findByKey()`
  (паттерн `BuildDurationServiceTest`), `community_messages` — реальная таблица через
  прогон миграции `Adr176CreateCommunityMessagesTable` на Forge (паттерн
  `CommunitySettingsSeedTest`), `service('cache')->clean()` в `setUp()` обязателен
  (`GameSettingsService` кэширует 60с глобально).
- `BotController` не тронут (Non-goals) — связка `handleCommunityUpdate()` →
  `CommunityIngestService::handle()` остаётся для следующей story.

## Findings
