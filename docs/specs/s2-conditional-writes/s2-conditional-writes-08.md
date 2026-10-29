---
story: s2-conditional-writes-08
spec: s2-conditional-writes
status: done
returned: DONE
tier: 3
worker: worker-code
wave: 2
blocked_by: []
red: required
red_reason: death roulette processed a candidate while the run lock was busy
red_verified_at: 2026-10-28T20:58:56Z 5bb5a9c30f3bb16252bbc1cfe4fd4d791d952e695b72c5d404f1c26c2ed60943
---

# F23: `DeathRouletteHandler` под `NamedLock` на весь прогон, без миграции

## Goal

`DeathRouletteHandler.php:64-95`: `findAll()` кандидатов → `rand` → `handlePlayerDeathAndReward` без клейма; два
наложившихся прогона крона могут обработать одного кандидата дважды. Аудит предлагал колонку
`death_roulette_claimed_at` (миграция) — владелец выбрал лок (Answers; ask 9: «без миграции»). После story весь
прогон — от выборки кандидатов до последнего `handlePlayerDeathAndReward` — внутри
`NamedLock::withLock('death_roulette', fn)`; `null` (лок занят) → обработчик выходит, не тронув никого, с одной
строкой лога. Только крон.

## Requirements

> 9. F23 — рулетка смерти (`DeathRouletteHandler`) идёт под `NamedLock` на весь прогон, без миграции; второй одновременный
> F23 рулетка смерти

## Files

- app/TaskHandlers/DeathRouletteHandler.php
- tests/database/DeathRouletteNamedLockTest.php

## Non-goals

- Никакой миграции, колонки, ключа `GameSettings`; вероятности и правила отбора кандидатов не меняются.
- Не трогать `DeathService`/`Death/*`: если цепочка смерти сама берёт именованный лок (вопрос 2 плана) —
  `NEEDS_CONTEXT`, не второй лок и не снятие чужого.
- Не ставить ожидание на лок: `GET_LOCK(?, 0)` non-blocking, как у `NamedLock`; занятый лок = пропуск прогона.
- Не поднимать Telegram в конструкторе теста (`feedback_taskhandler_telegram_init_in_tests`).

## Map slice

- Recon до волны 1 (Q2, 28.10): в `DeathService.php`, `DeathRouletteHandler.php` и `Services/Player/Death/{LootProcessor,PlayerRespawner,DeathPenaltyCalculator,InsuranceCalculator}.php` нет `GET_LOCK`/`NamedLock`/`withLock` — лок на прогон ни во что не вкладывается.

`memory/map/tasks-worker.md` → Entry points (`Config\Tasks` recurring vs `Worker`), Gotchas (три слоя защиты от
гонок, `telegram()` в тестах); `memory/map/player.md` → Entry points (`DeathService` + `Death/`). Контракт
`NamedLock` и тест с вторым соединением — `plan.md` → `## Contracts`.

## Acceptance criteria

- [ ] Выборка кандидатов, жребий и каждая смерть идут внутри одного `withLock('death_roulette', …)`; лок берётся до
      выборки (выборка вне лока — та же гонка).
- [ ] Лок занят → `handle()` возвращает штатно, ни одного `handlePlayerDeathAndReward`, одна строка лога уровня,
      который на проде пишется (`error` с фиксированным маркером — `INFO`/`warning` на проде не сохраняются).
- [ ] Освобождение лока — в `finally` обёртки; исключение внутри прогона лок не оставляет.
- [ ] Тест `DeathRouletteNamedLockTest`: второе соединение `Config\Database::connect('tests', false)` держит
      `GET_LOCK` с именем, которое `NamedLock` строит для `death_roulette`; `handle()` → ни один кандидат не умер
      (состояние строк кандидатов и счётчик вызовов двойника смерти равны исходным). Сообщение ассерта при провале:
      `death roulette processed a candidate while the run lock was busy`. `RELEASE_LOCK` в `tearDown`.

## Verification

`vendor/bin/phpunit --no-coverage --no-progress tests/database/DeathRouletteNamedLockTest.php && vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes

- `app/TaskHandlers/DeathRouletteHandler.php`: `handle()` оборачивает весь прогон (новый private `runRoulette()`, тело прежнего `handle()`) в `NamedLock::withLock('death_roulette', …)`; лок берётся до выборки кандидатов; `null` → одна строка `log_message('error', '[DEATH_ROULETTE_LOCK_BUSY] …')`; освобождение — `finally` внутри `NamedLock`.
- Конструктор получил опциональные `?DeathService $deathService`, `?NamedLock $lock` (после `$cfg`), константу `RUN_LOCK`; без аргументов поведение прежнее.
- `tests/database/DeathRouletteNamedLockTest.php`: второе соединение держит `GET_LOCK('death_roulette', 0)` (NamedLock имя не префиксует); кандидат с health 0 умирает при любом броске; двойник `CountingDeathService`; исключение из прогона тоже считается «кандидат обработан».
- Surprise: на общем стенде `characters` бывает урезанной таблицей чужого теста — тест добавляет недостающие колонки и убирает их в `tearDown`, свою таблицу дропает.
- Surprise: `red_reason` в кавычках не находился `stamp-red` (fm_raw берёт кавычки буквально) — кавычки сняты, текст тот же.

## Findings

## Red receipt
- command: vendor/bin/phpunit --no-coverage --no-progress tests/database/DeathRouletteNamedLockTest.php && vendor/bin/phpstan analyse --memory-limit=512M --no-progress
- exit: 1
- tests: 5bb5a9c30f3bb16252bbc1cfe4fd4d791d952e695b72c5d404f1c26c2ed60943 (tests/database/DeathRouletteNamedLockTest.php)
- impl: 0623f55a2f2f702baed80b4b6fce6328298d6b661a771b94ae4bf9a627213d22
- stamped: 2026-10-28T20:58:56Z

```
There was 1 failure:

1) Tests\Database\DeathRouletteNamedLockTest::testRunSkipsEveryCandidateWhileRunLockIsHeldElsewhere
death roulette processed a candidate while the run lock was busy
Failed asserting that 'CodeIgniter\Database\Exceptions\DatabaseException: Table 'wildworld_tests.character_resources' doesn't exist' is null.

C:\laragon\www\mmorpg\tests\database\DeathRouletteNamedLockTest.php:150

[37;41mFAILURES![0m
[37;41mTests: 1[0m[37;41m, Assertions: 2[0m[37;41m, Failures: 1[0m[37;41m, PHPUnit Deprecations: 1[0m[37;41m.[0m
```
