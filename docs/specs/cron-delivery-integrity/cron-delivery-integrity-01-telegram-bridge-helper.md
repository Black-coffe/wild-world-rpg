---
story: cron-delivery-integrity-01
spec: cron-delivery-integrity
status: done
returned: DONE
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
`vendor/bin/phpunit --no-coverage --no-progress`

## Tracer
Тонкий срез через слои: помощник → один потребитель (`BaseTaskHandler::telegram()`) → тест без ключа, до перевода остальных четырёх.

## Implementation notes
- `app/Services/Telegram/TelegramBridge.php` (new): static `ensure(): bool` per contract; catches `Throwable`, logs `[TelegramBridge] Telegram init failed: <reason>`. Added two helpers beyond the contract: `instance(): ?Telegram` (the base classes' `telegram()` must still return an object) and `reset(): void` (test-only, the state is static).
- `BaseTaskHandler` / `BaseObjectHandler`: `telegram()` now returns `?Telegram` (`ensure() ? instance() : null`), and the private field is gone. I chose `?Telegram` over `bool` because `tests/database/StandoffExpiryHandlerTest.php` overrides `telegram(): Telegram`. That override stays covariant, where `bool` would break it outside scope. `safeSend*` is unchanged (that is -03's job): if init fails, the send inside its `catch (\Throwable)` still logs a second line.
- `DeathService`, `GatherResultPersister`, `LevelUpNotifier`: the private `telegram()` and its field are removed. `send` now starts with `if (! TelegramBridge::ensure()) return;`. On success the behaviour is the same.
- Surprise: a failed `ensure()` is not cached, so every call retries and writes one `error` line. A cron with many recipients and no key will log one line per send attempt.
- Tests: `TelegramBridgeTest` (empty key, malformed key, idempotent with a key that is valid in format only, `BaseTaskHandler::telegram()` without a key gives null and no throw) + `LevelUpNotifierTest::testRealSendWithoutKeyDoesNotThrow` (real `send()`, no double). The key is set via `putenv` and restored, so there is no dependence on `.env`.
- Verification `tests/unit/Services/`: 1803 tests, 10 errors, all in `ResourceBankInsertRaceTest` (`Cannot drop table 'resources'` FK on the local test DB). That test is unrelated to Telegram. I did not check it against a baseline without my changes.

## Findings
