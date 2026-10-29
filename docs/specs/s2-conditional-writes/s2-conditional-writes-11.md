---
story: s2-conditional-writes-11
spec: s2-conditional-writes
status: done
returned: DONE
tier: 3
worker: worker-code
wave: 3
blocked_by: []
red: required
red_reason: teleport charge consumed although the move failed
red_verified_at: 2026-10-28T21:18:38Z f0ed404b944bc89e3feab20a224fd4e405faef46dc798134d46974c72519409e
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

- Recon до волны 1 (Q5, 28.10): `TeleportExecutor::teleport()` (`:41-51`) транзакций не открывает; при `$statDeltas !== []` зовёт `CharacterStatsService::adjust()`, который сам делает `transBegin`/`transCommit`/`transRollback` (`CharacterStatsService.php:86,101,126,130`). Внутри внешней транзакции CI4 это только счётчик глубины: внутренний `transRollback` НЕ откатывает — внешний код обязан откатиться сам, если `adjust()` вернул `null` или бросил. `revealAround` (`ExploredCellsModel.php:28`) ни `TeleportExecutor`, ни `TeleportUseAction` не зовут — пункт бэклога про него неактуален.

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

- `TeleportUseAction.php`: новый `chargeAndMove()` — transBegin → CAS → перемещение → один transCommit/transRollback на каждом пути; исключение перемещения — откат + `log_message('error')`; оба пути (рюкзак, портативный) идут через него.
- Шов: конструктор `TeleportUseAction($callbackQuery, ?TeleportExecutor $executor = null)`; списание и перемещение на одном `$this->db` (`TeleportItemConsumer($this->db)`, `TeleportExecutor(new CharacterModel($this->db))`).
- `TeleportItemConsumer.php`, `TeleportExecutor.php` не правились: своих транзакций и своего соединения у них нет (`adjust()` в executor только на путях золото/опыт, вне этой story).
- Текст при сбое перемещения — существующий `formatter->baseNotFound()` («База не найдена… Попробуй ещё раз.»): текст CAS-отказа («заряд списан другим запросом») был бы ложью.
- `TeleportExecutor::teleport()` возвращает `void`, поэтому «`false` из перемещения» на практике = исключение; `commit === false` тоже трактуется как отказ.
- `red_reason` был в кавычках: `fm_raw` сохраняет кавычки, и stamp-red не находил строку — кавычки сняты.
- Тест зовёт приватный `usePortableTeleport()` через reflection (гейты `handle()` вне пункта), схема — по образцу `TeleportChargeTest`.

## Findings

## Red receipt
- command: vendor/bin/phpunit --no-coverage --no-progress tests/database/TeleportUseTransactionTest.php && vendor/bin/phpstan analyse --memory-limit=512M --no-progress
- exit: 1
- tests: f0ed404b944bc89e3feab20a224fd4e405faef46dc798134d46974c72519409e (tests/database/TeleportUseTransactionTest.php)
- impl: 40036806ff2962ce8b6448020ef3063aee7681670a8e741c32ddf5952f77009e
- stamped: 2026-10-28T21:18:38Z

```
There was 1 failure:

1) Tests\Database\TeleportUseTransactionTest::testMoveFailureKeepsTheCharge
teleport charge consumed although the move failed
Failed asserting that 1 is identical to 2.

C:\laragon\www\mmorpg\tests\database\TeleportUseTransactionTest.php:99

[37;41mFAILURES![0m
[37;41mTests: 2[0m[37;41m, Assertions: 6[0m[37;41m, Failures: 1[0m[37;41m, PHPUnit Deprecations: 1[0m[37;41m.[0m
```
