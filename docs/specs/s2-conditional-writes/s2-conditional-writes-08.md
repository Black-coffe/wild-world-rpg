---
story: s2-conditional-writes-08
spec: s2-conditional-writes
status: todo
returned:
tier: 3
worker: worker-code
wave: 2
blocked_by: []
red: required
red_reason: "death roulette processed a candidate while the run lock was busy"
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

## Findings
