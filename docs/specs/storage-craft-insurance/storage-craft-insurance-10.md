---
story: storage-craft-insurance-10
spec: storage-craft-insurance
status: done
tier: 2
worker: worker-code
tracer: false
wave: 4
blocked_by: [storage-craft-insurance-04]
---

# Теплица сама воспроизводит нулевую строку, от которой её лечили

## Goal
Story 04 перенесла отметку о предупреждении в кэш именно затем, чтобы в рюкзаке игрока не
появлялось «📦 Вода | 0 шт». Но соседний путь того же метода — списание воды из рюкзака —
пишет `update(['quantity' => backpackQty - fromBackpack])` и при полном расходе оставляет
строку с нулём. Экран «Добытые ресурсы» фильтра по количеству не имеет, значит симптом
возвращается. Заодно списание воды и начисление урожая идут тремя независимыми записями без
транзакции.

## Requirements


## Files
- app/TaskHandlers/GreenhouseProductionHandler.php
- tests/unit/TaskHandlers/GreenhouseProductionWaterTest.php

## Non-goals
- НЕ менять пороги, cooldown и формулы урожая.
- НЕ переводить обработчик на `ResourcePoolService` — гейт «персонаж на базе» ему не подходит,
  решение принято и записано в Plan deltas.
- НЕ трогать `ResourcesGatheredAction` (отсутствие фильтра там — отдельная задача).

## Map slice
`memory/map/tasks-worker.md`, `memory/map/bases.md`.

## Acceptance criteria
- [x] Полный расход воды из рюкзака НЕ оставляет строку с нулём (путь списания ведёт себя как
      `decreaseResources()`, который строку удаляет).
- [x] Списание воды и начисление урожая происходят в одной транзакционной границе.
- [x] Тест доказывает отсутствие нулевой строки именно на пути СПИСАНИЯ, а не только на пути
      уведомления.
- [x] Тест перестаёт дропать общие таблицы тестовой БД и оставлять их удалёнными: следующий
      DB-тест не должен падать по причине, не связанной с его предметом.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/TaskHandlers/GreenhouseProductionWaterTest.php`

## Implementation notes
- `GreenhouseProductionHandler::handle()`: путь списания рюкзака переведён с прямого
  `update(['quantity' => ...])` на `CharacterResourceModel::decreaseResources()` — тот же
  метод, что удаляет строку при уходе в 0 (как у `ResourcePoolService`). Списание
  (рюкзак+склад) и начисление harvest обёрнуты в `$db->transStart()/transComplete()`
  (паттерн F0.6, как в `GenericCraftActionStart`).
- `GreenhouseProductionWaterTest`: `setUp()`/`tearDown()` больше не дропают шесть общих
  таблиц безусловно. Теперь проверяют `tableExists()` — если под этим именем уже лежит
  настоящая таблица, она переименовывается в сторону (`RENAME TABLE ... __ghwt_backup`) и
  возвращается в `tearDown()`; если оригинала не было (текущее состояние `wildworld_tests`
  на этой машине — там только `factories`/`migrations`/`onboarding_cohort_daily`/
  `player_action_log`), просто создают и дропают свою упрощённую схему, восстанавливать
  нечего. Добавлены `backpackRow()` helper и тест
  `testFullBackpackDrainByConsumptionLeavesNoZeroRow` (пул целиком из рюкзака, склад не
  трогается) — доказывает отсутствие нулевой строки именно на пути СПИСАНИЯ, а не только
  уведомления; тем же `assertNull` довооружён `testStorageCoversShortfallAfterBackpackDrained`.
- Проверено эмпирически: прогон `GreenhouseProductionWaterTest` перед
  `AchievementServiceTest` (обе трогают `game_settings`) и перед
  `BaseStorageWithdrawTest` + `ResourceOverviewServiceTest` (обе трогают
  `character_resources`/`base_storage`) — все зелёные в одном процессе PHPUnit.

## Findings
