---
story: storage-craft-insurance-04
spec: storage-craft-insurance
status: done
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

- `GreenhouseProductionHandler`: остаток воды теперь `$backpackQty + $storageQty` (пул), где
  `$storageQty` читается через `BaseStorageModel::quantityFor()` НАПРЯМУЮ, а не через
  `ResourcePoolService`. Отклонение от контракта story: `ResourcePoolService::isPooled()`
  гейтит доступность склада тем, стоит ли ПЕРСОНАЖ на базе прямо сейчас
  (`BaseCheckService::checkBaseStatus`) — верный критерий для крафта/ремонта, где игрок сам
  жмёт кнопку стоя на базе. Для крона теплицы это неверно: он обходит всех владельцев теплиц
  раз в минуту независимо от того, где они гуляют, а теплица физически стоит на базе и её
  склад доступен ей всегда. Гейтинг через «персонаж на базе» сделал бы починку нерабочей для
  любого игрока, ушедшего исследовать карту. Уважается только killswitch
  `storage.pool_enabled` (тот же ключ, тот же default `true`).
- Списание: сначала рюкзак (`min(backpackQty, needed)`), остаток — `BaseStorageModel::withdraw()`
  (не уходит в минус — модель уже это гарантирует).
- Порог/cooldown-предупреждение теперь считается от `poolQty`, текст указывает «(рюкзак + склад
  базы)» — без этого игрок со складом снова не понял бы, о чём число.
- Edge-case: если весь рюкзак выложен на склад, строки `character_resources` для воды может не
  быть вовсе (`decreaseResources` удаляет строку при quantity<=0) — тогда класть cooldown-метку
  было некуда. `checkAndNotifyWaterShortage` заводит нулевую строку под неё в этом случае —
  единственное отклонение от «трогать только два файла из списка».
- `notifyWaterShortage`: `private` → `protected` (seam для теста, по образцу
  `AntiCampDwellHandler::sendWarn`) — иначе тест не может подменить отправку без живого
  Telegram/БД character/telegram_users.
- `$baseStorageModel` получил явный тип `BaseStorageModel` (остальные модельные свойства файла
  остались нетипизированными по историческим причинам, зафиксированным в baseline) — иначе
  phpstan L9 требовал новую baseline-запись на новое свойство.
- `phpstan-baseline.neon`: удалена одна строка — запись под
  `GreenhouseProductionHandler::checkAndNotifyWaterShortage() has parameter $charResWater with no
  value type specified` устарела, потому что параметр получил точный PHPDoc
  `array<string,mixed>|null`. Больше в baseline этого файла ничего не трогал — соседние 6 ошибок
  в `BuildingUpgradeValidator.php` принадлежат параллельной сессии (не в `## Files` этой story).
- Тесты — `tests/unit/TaskHandlers/GreenhouseProductionWaterTest.php`, изолированная фикстура
  (`CREATE TABLE` на 6 таблиц в `wildworld_tests`, не полные миграции) по образцу
  `tests/database/AntiCampDwellHandlerTest.php`. 8 тестов: пул из склада без рюкзака, порядок
  списания рюкзак→склад, недостача рюкзака докрывается складом, подавление предупреждения при
  достаточном пуле, предупреждение с суммой пула (в т.ч. без строки рюкзака), cooldown,
  killswitch off = поведение только рюкзака.

## Findings
