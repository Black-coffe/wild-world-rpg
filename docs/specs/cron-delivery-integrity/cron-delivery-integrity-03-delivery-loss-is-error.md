---
story: cron-delivery-integrity-03
spec: cron-delivery-integrity
status: todo
returned:
tier: 3
worker: worker-code
tracer: false
wave: 2
blocked_by: [cron-delivery-integrity-01]
model: sonnet
---

# Потеря доставки видна на проде

## Goal
Провал отправки игроцкого сообщения пишется уровнем `error`: `PvEService.php:188`
(`PvE notify failed`) и `BaseTaskHandler` — `safeSendMessage` not-ok (`:120`), `safeSendPhoto`
not-ok (`:175`), `degradeToText` not-ok (`:258`). Порог логгера не трогаем. В Implementation notes
воркер записывает решение с причиной: нужен ли маркер в `action_log` (на проде мониторинг идёт по
нему) — и, если да, делает его в этих же файлах.

## Requirements
> Провал отправки игроцкого сообщения в PvE-пути и в `BaseTaskHandler::safeSend*` пишется уровнем `error` — строка видна при прод-пороге логгера 4.

## Files
- app/Services/Player/PvEService.php
- app/TaskHandlers/BaseTaskHandler.php
- tests/unit/Services/Player/PvEServiceNotifyFailureTest.php

## Non-goals
- Не менять `app/Config/Logger.php` — подъём порога потянет поток `[DEPRECATED]`-шума.
- Не трогать `PveNotificationSender.php` — это файл `-02` в той же волне.
- Не менять `warning` в ветке фото «файла нет → деградация в текст» (`:160`) — сообщение доходит текстом, это не потеря.
- Не трогать `TelegramBridge` и подъём моста в `telegram()` — сделано в `-01`.
- Не расширять на прочие сервисы «сквозняком» — только названные точки.

## Map slice
`memory/map/pve-pvp.md`, `memory/map/tasks-worker.md`.

## Acceptance criteria
- [ ] Исключение из `notificationSender->send()` в `PvEService::attack()` логируется `error`; бой и награды по-прежнему засчитаны (исключение не всплывает).
- [ ] Три not-ok ветки `BaseTaskHandler` пишут `error`.
- [ ] Решение по `action_log`-маркеру записано с причиной (и `ActionLogModel` enum не нарушен, если маркер есть — `feedback_action_log_enum_strict_values`).

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Services/Player/`

## Implementation notes

## Findings
