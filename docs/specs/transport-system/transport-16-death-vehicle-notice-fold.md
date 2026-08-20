---
story: transport-16
spec: transport-system
status: done
tier: 2
worker: worker-code
model: sonnet
tracer: false
wave: 3
blocked_by: [transport-14]
---

# «Машина разбита» перестаёт быть вторым сообщением в рулетке смерти

## Goal
Story 14 подключила разбитие машины к реальному потоку смерти, но текст ушёл
ОТДЕЛЬНЫМ `sendMessage` — воркер честно не полез в `DeathMessageBuilder` и
вызывающие обработчики, потому что они были вне его `## Files`. В её собственной
приёмке при этом записано: текст должен быть «≤ 1024 знаков **вместе с остальным
сообщением о смерти**». Этот разрыв и закрывается здесь.

## Requirements
> «груз некуда везти» перестал быть вторым сообщением (коммит ee43d7d7, то же
> требование владельца: одно событие — одно сообщение игроку)

## Context
Смерть приходит из трёх мест:

- `DeathRouletteHandler::sendDeathMessage()` — доминирующий путь, обычный текст
  (`parse_mode: Markdown`), получатель ровно один: умерший. **Сюда текст вклеивается.**
- `AttackPlayerAction` (PvP) — `$summaryText` общий для победителя и проигравшего
  и идёт в `parse_mode: HTML`. Вклейка рассказала бы победителю про чужой транспорт.
  **Остаётся отдельным личным сообщением.**
- `BossEncounterService::renderLost()` — экран боя, вне этой story.
  **Остаётся отдельным личным сообщением.**

Поэтому нужен явный опт-ин, а не смена поведения по умолчанию: тот, кто берёт текст
на себя, говорит об этом вызовом; все остальные пути работают ровно как сегодня.

## Files
- app/Services/Player/DeathService.php
- app/TaskHandlers/DeathRouletteHandler.php
- tests/database/Transport/DeathWiringTest.php
- tests/database/BossEncounterServiceTest.php (спай-подкласс `DeathService` — синхронизация сигнатуры)

## Acceptance
- [ ] `DeathService::handlePlayerDeathAndReward()` принимает третий параметр
      `bool $deferVehicleNotice = false` и всегда кладёт в результат ключ
      `'vehicleBroken' => ?string` (текст `LootProcessor::breakActiveVehicleOnDeath()`
      либо `null`, если активной машины не было).
- [ ] При `$deferVehicleNotice === false` (значение по умолчанию) поведение
      байт-идентично сегодняшнему: отдельное сообщение уходит через
      `notifyVehicleBroken()`. PvP и босс НЕ трогаются вовсе.
- [ ] При `$deferVehicleNotice === true` отдельное сообщение НЕ отправляется —
      только возвращается текстом.
- [ ] `DeathRouletteHandler` зовёт смерть с `true` и приклеивает `vehicleBroken`
      к тексту `DeathMessageBuilder::rouletteDeath()` через пустую строку — умерший
      получает ОДНО сообщение вместо двух.
- [ ] Игрок без активной машины получает ровно тот же текст рулетки, что и до правки
      (никаких пустых хвостов, лишних переводов строки).
- [ ] Тест на длину: собранный текст рулетки вместе с приклеенным «машина разбита»
      ≤ 1024 знаков (мера — `mb_strlen`, не `strlen`). Это не заметка в комментарии,
      а падающая проверка.
- [ ] Текст остаётся markdown-safe (сообщение уходит с `parse_mode: Markdown`):
      непарных `*` / `_` в склейке не появляется.

## Non-goals
- Не трогать `LootProcessor` и `VehicleActivationService` (владельцы — story 09/03).
- Не трогать `AttackPlayerAction`, `BossEncounterService`, `DeathMessageBuilder`.
- Не менять доли потерь, страховку и дробный бросок ADR-172.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/database/Transport/DeathWiringTest.php`

## Map slice
`memory/map/pve-pvp.md` (смерть, `DeathService`), `memory/map/player.md`

## Implementation notes
- `DeathService::handlePlayerDeathAndReward()` получил третий параметр
  `bool $deferVehicleNotice = false` и всегда кладёт `'vehicleBroken' => ?string`
  в результат (включая early-return для несуществующего персонажа — `null`).
  При `false` (default) поведение не изменилось: `notifyVehicleBroken()` вызывается
  как раньше. При `true` отдельная отправка пропускается, текст только возвращается.
- `DeathRouletteHandler` зовёт смерть с `true` и склеивает текст через новую чистую
  функцию `DeathRouletteHandler::glueVehicleNotice(string $rouletteText, ?string $vehicleBroken): string`
  (public static) — без машины текст рулетки не трогается (нет хвостов), с машиной
  приклеивается через `"\n\n"`. Функция вынесена отдельно, чтобы тест на длину/
  markdown-safety проверял чистую склейку, а не факт отправки (`safeSendMessage`
  не подменялся).
- PvP (`AttackPlayerAction`) и босс (`BossEncounterService`) не тронуты — они по-прежнему
  зовут `handlePlayerDeathAndReward()` без третьего аргумента (default `false`), их файлы
  вне `## Files` этой story и не редактировались.
- Тесты: расширил `tests/database/Transport/DeathWiringTest.php` (не переписывал) —
  добавил 6 тестов: defer-режим не шлёт отдельное сообщение но возвращает текст,
  defer без активной машины возвращает `null`, default-поведение byte-identical
  story 14 (отправленный текст == `result['vehicleBroken']`), 2 теста на чистую
  `glueVehicleNotice()` (склейка / без машины — текст не тронут), и падающая проверка
  длины+markdown-safety на реальной склейке `DeathMessageBuilder::rouletteDeath()` +
  `vehicleBroken` (mb_strlen ≤ 1024, парные `*`/`_`).
- `vendor/bin/phpunit --no-coverage --no-progress tests/database/Transport/DeathWiringTest.php`:
  11 тестов, 50 assertions, зелёно (MySQL на машине поднята, `wildworld_tests` доступна).
- `vendor/bin/phpstan analyse --memory-limit=512M --no-progress`: без ошибок.
- Ремонт по находке Queen'а на ПОЛНОМ прогоне: анонимный спай `DeathService` в
  `tests/database/BossEncounterServiceTest.php::deathSpy()` переопределял метод по старой
  сигнатуре → фатальная ошибка загрузки классов, весь набор не запускался вовсе, хотя
  одиночный прогон story-файла был зелёным, а exit code фатала — 0. Спай синхронизирован
  (третий параметр + ключ `vehicleBroken` в возвращаемой форме). Файл добавлен в `## Files`
  задним числом. Урок записан в `claude-memory/feedback_signature_change_breaks_test_subclasses.md`.
