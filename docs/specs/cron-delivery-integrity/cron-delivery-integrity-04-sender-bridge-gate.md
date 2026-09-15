---
story: cron-delivery-integrity-04
spec: cron-delivery-integrity
status: done
returned: DONE
tier: 3
worker: worker-test
tracer: false
wave: 3
blocked_by: [cron-delivery-integrity-01, cron-delivery-integrity-02]
model: opus
---

# Гейт против рецидива — детерминированный тест

## Goal
Тест по образцу `WipeManifestCoverageTest`: сканирует `app/Services/**`, находит классы, которые
зовут `Request::send*`/`Request::edit*`, и требует, чтобы каждый звал `TelegramBridge::ensure()`
(или уже принятый аналог — `EventNotificationSender::ensureTelegramInitialized`) либо стоял в явном
списке исключений внутри теста с причиной на каждую строку. Плюс поведенческий тест: реальный путь
`PveNotificationSender::send()` в окружении без ключа не бросает наружу и не возвращает ложный успех.

## Requirements
> Гейт: тест роняет набор, если класс в `app/Services/**`, зовущий `Request::send*`/`Request::edit*`, не поднимает мост сам и не стоит в списке исключений с причиной; к скану приложен поведенческий тест — реальный путь отправки без ключа не бросает наружу и не возвращает ложный успех.

## Files
- tests/unit/Config/TelegramSenderBridgeCoverageTest.php
- tests/unit/Services/PVE/PveNotificationSenderNoKeyTest.php

## Non-goals
- Не чинить найденные сканом сервисы в коде `app/` — story тестовая; каждый несоответствующий класс
  идёт в список исключений с честной причиной («вызывается только из веб-запроса, мост поднят
  `BotController`» и т.п.), а спорные — в Findings для Queen.
- Не сканировать `app/TaskHandlers/**` и контроллеры — вне брифа.
- Скан исходника не выдавать за покрытие (урок `feedback_source_scan_tests_are_not_coverage`) —
  поэтому поведенческий тест обязателен.

## Map slice
`memory/map/telegram.md`; образец — `tests/unit/Config/WipeManifestCoverageTest.php`; контракт — plan.md `## Contracts`.

## Acceptance criteria
- [ ] Новый класс в `app/Services/`, зовущий `Request::sendMessage` без помощника и без записи в исключениях, роняет тест (проверено временной фикстурой или объяснено в notes, как проверено).
- [ ] Каждая запись списка исключений несёт причину.
- [ ] Поведенческий тест без ключа: `send()` не бросает, `sendMessage` не исполняется, в логе `error`.
- [ ] Набор зелёный.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`

## Implementation notes
- Scan gate (`TelegramSenderBridgeCoverageTest`): comments are stripped with `token_get_all` before matching `Request::(send|edit)\w*(`. Accepted helpers are `TelegramBridge::ensure(` and `->ensureTelegramInitialized(`. 3 tests:
  - the gate itself;
  - every exception has a reason (>20 chars) and is still flagged (a stale entry fails);
  - the detector self-tested on a temp fixture dir (naked send, edit, bridged, Event-style, comment-only, ensure-only-in-comment).
- Proof the gate fails on a violator: a temporary `app/Services/ZzCdi04ProbeSender.php` with a bare `Request::sendMessage` turned the gate red, naming that file. The file was then deleted.
- 24 exceptions, each reason checked against callers by grep:
  - 4 inline self-init (`new Telegram` + `Request::initialize`);
  - 15 webhook-only;
  - 3 cron services whose task-handler calls `telegram()` → `ensure()` first;
  - CommunityChatSender and MediaSender, caller-initialised (`CommunityAutoReplyHandler::ensureTelegramInitialized()`; `safeSend*` → `telegram()`).
- Behavioural test (`PveNotificationSenderNoKeyTest`): runs the real `send()` with the real Longman Request, no transport double. The test nulls Longman `Request::$telegram` to reproduce a fresh cron process. A Guzzle MockHandler with history is the network layer (it records requests and blocks real HTTP). The recipient model is a DB-free subclass. Static state is restored in tearDown. A control test with a format-valid key proves the harness can see a send (`clearBlocked` fires).
- Proof the behavioural test fails, each mutation checked on its own:
  - guard replaced with `if (false)`: both tests error (`getBotUsername() on null` thrown out of send());
  - guard removed while Request was already initialised elsewhere: the no-key test fails on the HTTP-history assertion. The same setup with the guard intact is green.
  - App code was restored each time; `git status` shows only the 2 new test files.
- Fresh DB `wildworld_ci_cdi04`: 5 tests, 57 assertions, OK. The one "PHPUnit deprecation" is the XML schema of `phpunit.xml.dist`, pre-existing.

## Findings
- **Doubtful — CommunityChatSender, admin path:** `Admin\CommunityController::approveAnswer()/revokeAnswer()` → `sendManualAnswer()` → `Request::send`. Nothing initialises the bridge on that admin HTTP request (unlike `PollController`/`CharacterResetController`, which do). This is likely a real delivery gap for manual answers. It is in the exceptions list, marked ⚠.
- **Doubtful — MediaSender:** a stateless helper whose "caller initialises" contract is only as good as every caller. Callers in TaskHandlers go through `safeSend*` → `telegram()`; service callers are webhook.
- **Bypass, not a gap — BroadcastService, PlayerDetectionService, TowerAlertService, ObjectSignalService:** these raise the bridge inline, bypassing `TelegramBridge` (a second init copy). They are candidates to move to `ensure()`.
- **OnboardingHintService in craft handlers:** the hint runs after `safeSend*`, so if `telegram()` failed there, the hint's `Request::send` hits a null bridge. Whether the handler catches that is not checked here.
