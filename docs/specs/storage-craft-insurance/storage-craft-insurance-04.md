---
story: storage-craft-insurance-04
spec: storage-craft-insurance
status: todo
tier: 3
worker: worker-code
tracer: false
wave: 2
blocked_by: [storage-craft-insurance-01]
---

# Теплица видит воду на складе

## Goal
`GreenhouseProductionHandler` перестаёт считать воду только в рюкзаке. Теплица стоит на базе — значит
вода на складе той же базы для неё доступна. Заодно исчезает сообщение «у вас осталось всего 3 единицы
воды» у игрока, у которого на складе почти восемь тысяч.

## Requirements
> #bug [Image #1] [Image #2] Теплица не видит воду, выложенную из рюкзака на склад

## Files
- app/TaskHandlers/GreenhouseProductionHandler.php
- tests/unit/TaskHandlers/GreenhouseProductionWaterTest.php

## Non-goals
- НЕ трогать `FarmingService` и активное земледелие — это отдельный слой, воды он не касается.
- НЕ менять пороги и cooldown предупреждения (`building.greenhouse.water_shortage_threshold`,
  `..._cooldown_sec`) — числа правильные, врал источник остатка, а не порог.
- НЕ вызывать `telegram()` жадно при инициализации — гейт из `feedback_taskhandler_telegram_init_in_tests`.
- НЕ расширять обработчик на другие ресурсы теплицы: жалоба про воду, механика про воду.

## Map slice
`memory/map/tasks-worker.md` (cron → TaskHandler), `memory/map/bases.md` (склад).

## Acceptance criteria
- [ ] Остаток воды для теплицы считается пулом: рюкзак + склад базы.
- [ ] Списание идёт сначала из рюкзака, остаток — со склада; склад не уходит в минус.
- [ ] Предупреждение о нехватке не отправляется, пока пула хватает; в тексте предупреждения названа
      суммарная вода, а не только карманная — иначе игрок снова не поймёт, о чём речь.
- [ ] При `storage.pool_enabled = false` поведение ровно сегодняшнее (только рюкзак).
- [ ] Обработчик остаётся вызываемым в тестах без живого Telegram.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/TaskHandlers/GreenhouseProductionWaterTest.php`

## Implementation notes

## Findings
