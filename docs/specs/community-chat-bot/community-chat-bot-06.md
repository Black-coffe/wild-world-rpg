---
story: community-chat-bot-06
spec: community-chat-bot
status: done
tier: 2
worker: worker-code
tracer: false
wave: 2
blocked_by: [community-chat-bot-02, community-chat-bot-03]
---

# CommunityChatSender: голос в топик, реплай и реакции

## Goal
Появляется единственная разрешённая точка, через которую бот говорит в групповой чат:
отправка в `chat_id` + `message_thread_id`, обязательный реплай на исходное сообщение,
реакции-эмодзи вместо молчания, лимиты и аудит. Вся существующая инфраструктура
(`BroadcastService`) шлёт только в личные чаты игроков и для этого не годится.

## Requirements
> чтобы он от своего имени, от бота, давал ответы на вопросы

> чтобы была живая группа, потому что я не всегда успеваю, не всегда могу

## Files
- app/Services/Community/CommunityChatSender.php
- tests/unit/Services/CommunityChatSenderTest.php

## Non-goals
- Не решать, ЧТО отправлять: решение принимают матчер (story 08) и гвард (story 07).
- Не трогать `BroadcastService`, `MediaSender` и `MessageController` — они про личные чаты.
- Не слать фото: в чате только текст. Если картинка когда-нибудь понадобится — отдельная
  задача с caption-лимитом 1024 и `MediaSender`.
- Не реализовывать модерацию (удаление чужих сообщений) — story 10.

## Map slice
`memory/map/telegram.md` §Исходящая отправка. `BroadcastService.php:41-66` — образец
`dispatch()`/`sendOne()` с проверкой `isOk()`; `App\Services\Telegram\Request` — обёртка
над Longman. Аудит — `BaseAdminController::audit()` пишет в `admin_audit_log`.

## Contract (из plan.md §4, §6)
- `sendAnswer(int $messageRowId, string $text): bool` — всегда `reply_to_message_id` на
  исходное сообщение и в тот же `message_thread_id`. Ответ без реплая запрещён: в топике,
  где за сутки прошло 200 сообщений, ответ без привязки нечитаем.
- `react(int $messageRowId, string $emoji): bool` — через `setMessageReaction` (в Bot API метод называется так; `sendMessageReaction` не существует, и в vendor 0.81.0 его нет в whitelist `Request` — путь идёт мимо обёртки, это принятый долг). 👀 «взял в
  очередь», 🤔 «стоп-тема». Реакция не занимает строку и никого не перебивает.
- Перед отправкой проверяются, в этом порядке: `community.enabled` →
  `community.autoreply.enabled` → топик не в `silent_topics` → потолок
  `max_per_hour_per_topic` → кулдаун `author_cooldown_seconds` → длина не выше
  `max_answer_chars`.
- **При срабатывании потолка бот молчит полностью, а не через раз** — выборочное молчание
  читается игроками как «мне не отвечают», ровное как «бота нет».
- Каждая отправка и каждый отказ пишутся в `admin_audit_log` с причиной отказа.
- Текст экранируется под тот parse_mode, который выбран; непарные `*` — отказ, а не 400
  от Telegram с тихим не-отправлением.

## Acceptance criteria
- [ ] Ответ уходит в тот же топик и реплаем на исходное сообщение.
- [ ] При `community.autoreply.enabled=false` не уходит ничего, причина в аудите.
- [ ] Топик из `silent_topics` — ни одного ответа, независимо от прочих условий.
- [ ] Шестой ответ в топик за час при потолке 5 не уходит; седьмой и восьмой — тоже
      (молчит полностью, а не через раз).
- [ ] Второй ответ одному автору внутри `author_cooldown_seconds` не уходит.
- [ ] Текст длиннее `max_answer_chars` не отправляется усечённым — отправка отклоняется.
- [ ] Текст с непарным `*` отклоняется до вызова API.
- [ ] Текст, содержащий «Робби», отклоняется — канон одна «б» (гвард имени, дублирует
      story 04 намеренно: это последняя точка перед Telegram).
- [ ] `react()` идёт реакционным путём, а не текстовым (`sendMessage`).
- [ ] Реакции НЕ расходуют потолок ответов в топике и не блокируются кулдауном автора.
- [ ] Отказ Telegram (`ok=false`) не роняет вызывающего и попадает в аудит.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Services/`

## Implementation notes

- `CommunityChatSender::sendAnswer()`/`::react()` оба идут через общий `dispatch()` →
  инжектируемый `$transport` (`(string $method, array $data): ServerResponse`, по
  умолчанию `defaultTransport()`) и общий `checkGates()` (порядок гейтов дословно
  из контракта: `community.enabled` → `autoreply.enabled` → `silent_topics` →
  `max_per_hour_per_topic` → `author_cooldown_seconds` → `max_answer_chars` +
  парность `*` + канон-гвард «Робби»/«Роби»).
- Потолок/кулдаун считаются не по отдельной таблице, а по собственным прошлым записям
  в `admin_audit_log` (`COMMUNITY_ANSWER_SENT`/`COMMUNITY_REACTION_SENT`), присоединённым
  к `community_messages` через `target_id` — план не просил третью сущность под то же
  самое. Каждая попытка (успех/отказ) пишется в `admin_audit_log` с `admin_user_id=0`
  (действие бота, не человека за админкой) и причиной в `payload.reason`.
- `GameSettingsService` объявлен `final` — тесты не могут подменить его подклассом.
  Чтение настроек идёт через отдельно инжектируемый `$settingsGetter`-callable
  (`(string $key, mixed $default): mixed`), по умолчанию `[$settings, 'get']`; тесты
  подают чистый closure без БД/кеша.
- Cast `mixed → int/bool/string` от `$settingsGetter` сделан через отдельные `readBool`/
  `readInt`/`readString`, каждый сначала присваивает результат вызова в переменную и
  только потом кастит её — прямой `(int) $callResult` phpstan L9 ловит как `cast.int`
  (memory `feedback_phpstan_no_mixed_to_int_cast`).
- **Находка вне докладной части контракта, но блокирующая react() как есть:** vendor
  `longman/telegram-bot` 0.81.0 (текущий `composer.lock`) не содержит `setMessageReaction`
  в приватном whitelist `Request::$actions` вовсе — `ensureValidAction()` бросает
  `TelegramException` РАНЬШЕ, чем срабатывает `PHPUNIT_TESTSUITE`-заглушка, то есть
  `App\Services\Telegram\Request::send('sendMessageReaction'|'setMessageReaction', …)`
  падает и в проде, и под PHPUnit без инжекции. Апгрейд composer — вне `## Files` этой
  story. `react()` поэтому в проде идёт напрямую на Bot API через `curl`
  (`rawBotApiCall()`), в обход `App\Services\Telegram\Request`.
- Контракт называет метод `sendMessageReaction` — реального метода с таким именем в
  Bot API нет (правильное имя — `setMessageReaction`, `POST /bot<token>/setMessageReaction`,
  задокументировано в Telegram Bot API). Использовано корректное имя `setMessageReaction`;
  acceptance-критерий «react() вызывает sendMessageReaction, а не sendMessage» прочитан
  по существу (реакционный путь, а не текстовый), тест `testReactCallsReactionMethodNotSendMessage`
  утверждает именно это (`assertNotSame('sendMessage', …)` + `setMessageReaction` де-факто).
- Тест: изолированная схема `community_messages`/`admin_audit_log` в `wildworld_tests`
  (паттерн `VehicleRepairTest`/`DemolishBuildingTest` — общая схема отстаёт на сотни
  непрогнанных миграций), `$transport` инжектируется в каждом сценарии — реальный
  `Request::send()`/сеть не вызывается ни разу, `PHPUNIT_TESTSUITE`-режим Longman не
  задействован.
- `vendor/bin/phpstan analyse --memory-limit=512M --no-progress` — чисто, включая
  полный прогон по всему `app/` (не только `app/Services/Community`).
  `vendor/bin/phpunit --no-coverage --no-progress tests/unit/Services/` — 1562 теста,
  0 failures (20 deprecations — предсуществующий baseline-шум всего набора, не от
  этой story: тот же счётчик воспроизводится на `CraftShortageScreenHelperTest.php`
  в одиночном прогоне).

## Findings

Nothing outstanding — vendor-gap задокументирован выше как принятое архитектурное
решение (curl-обход `Request` для одного конкретного метода), не как стена.

### Ремонтный круг 1 — ревью Queen: реакции расходуют бюджет ответов

Реализация аккуратная, находка про `setMessageReaction` засчитана и внесена в контракт.
Отклонено одно поведение.

`sentInTopicLastHour()` считает `COMMUNITY_REACTION_SENT` вместе с `COMMUNITY_ANSWER_SENT`,
а `react()` прогоняет полный `checkGates()` — то есть потолок `max_per_hour_per_topic`
и `author_cooldown_seconds` применяются к реакциям наравне с текстом.

**Почему это неверно.** Реакция — дешёвая квитанция ВМЕСТО молчания: 👀 «вопрос принят»,
🤔 «стоп-тема». Она не занимает строку в чате, никого не перебивает и ничего не утверждает
об игре. Потолок 5/час существует, чтобы бот не забивал топик СООБЩЕНИЯМИ. Сейчас шестой
вопрос в топике за час не получит даже 👀 — игрок прочитает это как «меня игнорят», ровно
тот исход, ради предотвращения которого реакции и появились в плане. И кулдаун автора
означает: два вопроса за десять минут — на второй ни ответа, ни признака, что услышали.

Условия:
1. Реакции не расходуют `max_per_hour_per_topic` и не считаются в нём.
2. Кулдаун автора на реакции не распространяется.
3. Для реакций остаются гейты: `community.enabled`, `community.autoreply.enabled`,
   `silent_topics`, отсутствие строки сообщения. Длина текста, парность `*` и канон
   имени к реакции неприменимы по смыслу.
4. Свой предохранитель у реакций допустим, но отдельный и заведомо щедрый — НЕ общий
   с ответами.
5. Тесты обязаны краснеть на этом: после исчерпания потолка ответов в топике `react()`
   продолжает работать; внутри кулдауна автора `react()` продолжает работать.

**Принятый долг, менять не нужно:** счёт лимитов по `admin_audit_log` остаётся —
в `community_messages` нет отметки времени ответа, иначе не посчитать без новой колонки
в чужой story. Односторонний риск (сбой записи в аудит занижает счётчик) уходит в ревью.

### Ремонтный круг 1 — исправление

Каркас `checkGates(array $row, ?string $text, bool $isReaction = false)` уже был на
месте (флаг + пропуск потолка/кулдауна при `$isReaction === true`), но `react()`
вызывал `checkGates($row, null)` без третьего аргумента — флаг молча оставался
`false`, и реакция фактически проходила полный путь ответа. Исправлено:
`react()` теперь вызывает `checkGates($row, null, true)`.

Отдельно оказалось, что оба счётчика (`sentInTopicLastHour()`,
`authorSentWithinCooldown()`) считали `COMMUNITY_ANSWER_SENT` и
`COMMUNITY_REACTION_SENT` вместе. Даже с флагом `$isReaction`, пропускающим сам гейт
для реакций, прошлые реакции продолжали бы копить счётчик, которым меряется потолок/
кулдаун ДЛЯ ОТВЕТОВ — реакция, отправленная в ответ на пятый вопрос, засчиталась бы
как шестая единица и придвинула бы срабатывание потолка раньше времени. Оба SQL
сужены до `a.action = 'COMMUNITY_ANSWER_SENT'` (было `IN (...)`).

Добавлены два теста (условие 5 контракта): `testReactStillWorksAfterHourlyAnswerCeilingExhausted`
(5 прошлых `COMMUNITY_ANSWER_SENT` в топике, шестая реакция всё равно уходит) и
`testReactStillWorksInsideAuthorCooldown` (прошлый ответ автору < cooldown назад,
реакция тому же автору всё равно уходит). Оба падали до фикса `checkGates`-вызова
(реакция ловила `topic_rate_limit`/`author_cooldown` наравне с ответом), зелёные после.

`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Services/CommunityChatSenderTest.php`
— 12 тестов, 46 assertions, 0 failures. `vendor/bin/phpstan analyse --memory-limit=512M
--no-progress` по файлу — чисто.

