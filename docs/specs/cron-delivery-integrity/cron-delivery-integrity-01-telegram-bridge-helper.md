---
story: cron-delivery-integrity-01
spec: cron-delivery-integrity
status: todo
returned:
tier: 3
worker: worker-code
tracer: true
wave: 1
blocked_by: []
model: opus
---

# Общий честный подъём моста вместо пяти лгущих копий

## Goal
Существует `App\Services\Telegram\TelegramBridge::ensure(): bool` (контракт — plan.md
`## Contracts`): поднимает Telegram-мост идемпотентно, при неудаче пишет одну `error`-строку и
возвращает `false`, никогда не бросает. Пять копий аварийной ветки
`new Telegram('invalid','invalid')` переведены на него — в `app/` не осталось ни одной.

## Requirements
> Подъём Telegram-моста при невалидном/пустом ключе не бросает исключение наружу и честно сообщает о неудаче; пять копий аварийной ветки `new Telegram('invalid','invalid')` (`BaseTaskHandler`, `DeathService`, `GatherResultPersister`, `LevelUpNotifier`, `BaseObjectHandler`) заменены одним общим помощником — в `app/` не осталось ни одной.

## Files
- app/Services/Telegram/TelegramBridge.php
- app/TaskHandlers/BaseTaskHandler.php
- app/Services/Player/DeathService.php
- app/Services/Player/Gather/GatherResultPersister.php
- app/Services/Player/Progression/LevelUpNotifier.php
- app/TaskHandlers/Objects/BaseObjectHandler.php
- tests/unit/Services/Telegram/TelegramBridgeTest.php
- tests/unit/Services/Player/Progression/LevelUpNotifierTest.php

## Non-goals
- Не трогать `PveNotificationSender`, `PvEService`, `AutoPveHandler` — это `-02`/`-03`.
- Не менять уровни логирования в `safeSend*`/`degradeToText` — это `-03` (волна 2).
- Не переводить `EventNotificationSender`, `CommunityAutoReplyHandler`, `StandoffExpiryHandler` —
  они уже безопасны; их перевод — не заказан.
- Не заводить очередь/ретраи доставки.

## Map slice
`memory/map/tasks-worker.md`, `memory/map/telegram.md`. Образец — `EventNotificationSender::ensureTelegramInitialized()` (`app/Services/Events/EventNotificationSender.php:66`).

## Acceptance criteria
- [ ] `TelegramBridge::ensure()` с пустым/невалидным ключом возвращает `false`, не бросает, пишет `error`.
- [ ] С валидным ключом — `true`; второй вызов не пересоздаёт мост.
- [ ] `git grep "new Telegram('invalid'" -- app` — пусто (комментарий в `StandoffExpiryHandler.php:128` не в счёт).
- [ ] Пять мест вызывают помощник; их поведение при успехе не изменилось; `telegram()` в `BaseTaskHandler` при неудаче больше не падает.
- [ ] phpstan L9 чистый по тронутым файлам.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Services/`

## Tracer
Тонкий срез через слои: помощник → один потребитель (`BaseTaskHandler::telegram()`) → тест без ключа, до перевода остальных четырёх.

## Implementation notes

## Findings
