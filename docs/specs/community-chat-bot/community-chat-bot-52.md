---
story: community-chat-bot-52
spec: community-chat-bot
status: done
tier: 2
worker: worker-code
tracer: false
wave: 12
blocked_by: []
---

# Тик инициализирует Telegram перед отправкой — иначе авто-ответ мёртв в кроне

## Goal
Авто-ответ перестаёт падать фаталом на каждой попытке отправки в реальном cron-запуске.

## Requirements
> чтобы он от своего имени, от бота, давал ответы на вопросы

## Files
- app/TaskHandlers/Community/CommunityAutoReplyHandler.php
- tests/unit/TaskHandlers/CommunityAutoReplyHandlerTest.php

## Non-goals
- Не трогать `CommunityChatSender`, гвард, матчер, приём, админку.
- Не менять транспорт-сеам, на котором стоят существующие тесты.

## В чём дефект
Найдено автономным Tier-3 на preprod (2026-08-25). Настоящий cron-запуск
`php spark tasks:run` → `community.auto-reply` даёт в журнале:

```
COMMUNITY_ANSWER_FAILED | target_id=2 |
payload={"reason":"exception: Call to a member function getBotUsername() on null"}
```

Причина: `Request::send()` (`vendor/longman/telegram-bot/src/Request.php:696`) обращается к
`self::$telegram->getBotUsername()`. Статическое поле заполняет только `Request::initialize()`,
которую в этом процессе никто не звал. `BaseTaskHandler::telegram()` умеет ленивую инициализацию
(`:39-55`, там же `Request::initialize()`), и наш хендлер его наследует — но вызывает отправку
через сервис, минуя этот метод, поэтому инициализация не происходит НИКОГДА.

Почему не поймали раньше: все 3718 юнит-тестов подставляют транспорт-двойник и до `Request::`
не доходят; вдобавок `Request::send()` под PHPUnit возвращает фейковый ответ. То есть путь,
который в бою исполняется всегда, в тестах не исполняется никогда.

Масштаб: в кроне падала бы КАЖДАЯ отправка авто-ответа — фича BUILT-BUT-DEAD. Реакция 👀/🤔 при
этом работает: у неё свой curl-путь (`setMessageReaction` нет в whitelist `Request::$actions`),
и в том же прогоне она честно дошла до Telegram и вернула «chat not found» на синтетическом чате.

## Contract
- К моменту первой отправки в тике `Request` инициализирован — так же, как это делают соседние
  task-handler'ы через `BaseTaskHandler::telegram()`.
- Инициализация ленивая: тик, которому нечего отправлять, не платит за неё (смысл `telegram()`
  как ленивого метода сохраняется).
- Существующие тесты с транспорт-двойником продолжают работать без правок семантики.
- Есть проверка, которая краснеет, если инициализацию убрать.

## Acceptance criteria
- [x] В cron-контексте отправка не бросает `getBotUsername() on null`.
- [x] Тик без кандидатов на отправку Telegram не инициализирует.
- [x] Существующие тесты тика зелёные без изменения их смысла.
- [x] Тест краснеет при удалении инициализации.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/TaskHandlers/CommunityAutoReplyHandlerTest.php`

## Implementation notes

- Добавлен сеам `?callable $telegramInitializer` (по умолчанию `fn() => $this->telegram()`,
  унаследованный `BaseTaskHandler`-метод) — новый private-параметр конструктора
  `CommunityAutoReplyHandler`, добавлен последним, старые вызовы конструктора не ломает.
- `ensureTelegramInitialized()` вызывается ровно в двух точках, где `CommunityChatSender`
  реально идёт в сеть через `Request::send()`: перед `sender->sendAnswer()` в
  `resolveAndSend()` и перед `sender->react()` в `reactOnce()` (покрывает и полосу A/B,
  и `receipt_only`/🤔-реакцию при отказе гварда). Килсвитч-`return` в начале `handle()`
  и «нечего слать» ветки (`isSilent`) инициализацию не трогают вовсе — лень сохранена.
- Тесты: `handler()`-фабрика получила 6-й опциональный параметр `$telegramInitializer`
  (default — no-op, чтобы существующие ~25 тестов не трогали реальный `BaseTaskHandler::telegram()`
  на CI, где нет `.env`/`telegram.API_KEY` — см. `feedback_taskhandler_telegram_init_in_tests`).
  Добавлены 3 новых теста со счётчиком-спаем: `testActualSendInitializesTelegramBeforeSending`
  (must=1 перед реальным `sendMessage`), `testReceiptOnlyReactionAlsoInitializesTelegramBeforeSending`
  (симметрично для ветки реакции), `testNoCandidatesToSendDoesNotInitializeTelegram` (килсвитч
  выключен → must=0).
- Редность проверена вручную: временный `sed` убрал оба вызова `$this->ensureTelegramInitialized();`
  из рабочего кода → `testActualSendInitializesTelegramBeforeSending` покраснел
  (`Failed asserting that 0 is identical to 1`), остальные 27 тестов остались зелёными;
  файл восстановлен из бэкапа, полный прогон снова зелёный (28/28).
- `CommunityChatSender`/`CommunityGuard`/`CommunityAnswerMatcher`/админка не тронуты (Non-goals).
