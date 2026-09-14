---
story: cron-delivery-integrity-02
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

# `PveNotificationSender` поднимает мост сам — авто-PvE чинится

## Goal
`PveNotificationSender::send()` перед `Request::sendMessage` вызывает `TelegramBridge::ensure()`;
при `false` — `error`-строка и выход без исключения. Авто-PvE из крона доставляет сообщение о бое
без того, чтобы `AutoPveHandler`/`PvEService` что-то помнили. Предупреждение о недостижимом
получателе (`:86`) остаётся `warning` — штатная ситуация.

## Requirements
> Авто-PvE из крона: после боя игрок получает сообщение о бое — живой прогон крона на preprod-testbot (тест-чар на клетке с живым агрессивным NPC, тик `npc.auto-pve`), сообщение пришло в чат, в логе нет `PvE notify failed`.

## Files
- app/Services/PVE/PveNotificationSender.php
- tests/unit/Services/PVE/PveNotificationSenderTest.php

## Non-goals
- Не трогать `AutoPveHandler` и `PvEService` — смысл story в том, что вызывающему помнить не нужно.
- Не менять текст сообщения о бое и `PveMessageFormatter` (media-off, ask 5).
- Не переписывать отправку на `MediaSender`/новую архитектуру уведомлений.

## Map slice
`memory/map/pve-pvp.md`; контракт помощника — plan.md `## Contracts`.

## Acceptance criteria
- [ ] Тест: `send()` в процессе без предварительной инициализации моста зовёт `TelegramBridge::ensure()` до `Request::sendMessage` (двойник транспорта не должен прятать отсутствие init — проверяется порядок/факт вызова помощника).
- [ ] Тест: `ensure()` = `false` → `send()` не бросает, пишет `error`, `sendMessage` не вызывается.
- [ ] Поведение `blocked_at`-гигиены (`markBlocked`/`clearBlocked`) не изменилось.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Services/`

## Implementation notes

## Findings
