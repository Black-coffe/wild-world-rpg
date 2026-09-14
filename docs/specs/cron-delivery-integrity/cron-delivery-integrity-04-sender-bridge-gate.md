---
story: cron-delivery-integrity-04
spec: cron-delivery-integrity
status: todo
returned:
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

## Findings
