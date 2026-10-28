---
story: s2-conditional-writes-11
spec: s2-conditional-writes
status: todo
returned:
tier: 3
worker: worker-code
wave: 3
blocked_by: []
red: required
red_reason: "teleport charge consumed although the move failed"
---

# Телепорт: CAS заряда и перемещение в одной транзакции

## Goal

После exploit-fix-08 `TeleportItemConsumer::consumePortable()`/`consumeBackpack()` списывают заряд CAS по снимку и
`TeleportUseAction` (`:172-178` рюкзак, `:227-234` портативный) перемещает только на подтверждённый исход — но CAS
коммитится autocommit'ом, а `TeleportExecutor::teleport()` идёт вне транзакции: сбой перемещения = заряд потерян
(вред игроку, не дюп; descoped в exploit-fix круге 2 как «риск шире minor-пакета»). После story в обоих путях
`TeleportUseAction` открывает транзакцию до списания, зовёт перемещение и завершает её по возврату; исключение или
`false` из перемещения → откат, заряд на месте, игрок получает существующий текст отказа и остаётся на месте.

## Requirements

> 12. Телепорт — списание заряда (`TeleportItemConsumer`) и перемещение (`TeleportExecutor::teleport`) в одной транзакции в
> телепорт: CAS заряда и перемещение в одной транзакции

## Files

- app/Controllers/Telegram/Commands/Actions/Camp/TeleportUseAction.php
- app/Services/Player/TeleportUse/TeleportItemConsumer.php
- app/Services/Player/TeleportUse/TeleportExecutor.php
- tests/database/TeleportUseTransactionTest.php

## Non-goals

- `TeleportItemConsumer.php` и `TeleportExecutor.php` правятся **только** если мешают одной транзакции (своё
  соединение вместо общего, собственный `transCommit()`/`transComplete()` внутри — вопрос 5 плана); форма CAS
  exploit-fix-08 не меняется.
- Не менять выбор базы назначения (`backpack-teleport-base-choice`), `revealAround`, правило зажима зарядов, крафт
  телепортов.
- Не трогать `tests/exploit-poc/TeleportChargeTest.php` — он обязан остаться зелёным без правок.
- Не править `phpstan-baseline.neon` (устаревшие записи — списком, `WALL`).

## Map slice

`memory/map/player.md` → Entry points (`TeleportUse/`: назначение — выбранная активная база), Gotchas («ЗАКРЫТО …
exploit-fix-08, F7»: CAS по снимку, `bool`/`?int`, инлайн). Правило «транзакция завершается ровно один раз, исход по
возврату» и форма теста — `plan.md` → `## Contracts`.

## Acceptance criteria

- [ ] Рюкзачный и портативный пути: `transBegin` → CAS заряда → перемещение → `transCommit`, всё на одном
      соединении; откат при отказе CAS (как сегодня, без перемещения) и при исключении/`false` перемещения.
- [ ] Транзакция завершается ровно один раз на каждом пути выхода (успех, отказ CAS, исключение перемещения);
      исход — по возврату методов, не по `transStatus()`; исключение перемещения после отката не глотается молча —
      игрок видит существующий отказ, в лог идёт `error`.
- [ ] Честный телепорт работает как до story (заряд −1, персонаж на базе, `revealAround` вызван).
- [ ] Тест `TeleportUseTransactionTest`: двойник `TeleportExecutor`, бросающий исключение в `teleport()` (через
      существующий шов инъекции `TeleportUseAction`; без шва — опциональная зависимость конструктора с дефолтом);
      после вызова заряд предмета равен исходному, клетка персонажа не изменилась. Второй метод: честный путь
      списывает ровно один заряд. Сообщение ассерта при провале: `teleport charge consumed although the move failed`.
      Без живого Telegram.

## Verification

`vendor/bin/phpunit --no-coverage --no-progress tests/database/TeleportUseTransactionTest.php && vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes

## Findings
