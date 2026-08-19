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
- **Тест-изоляция — вторая версия, после красной CI-сборки (см. Findings).** Первая
  версия (`RENAME TABLE ... __ghwt_backup` + `tableExists()`) СЛОМАЛА CI: на реально
  мигрированной `wildworld_tests` `RENAME TABLE game_settings TO ...` падал с «table
  doesn't exist», потому что другие DB-тесты того же прогона (например
  `AchievementServiceTest`) уже унесли `game_settings` своим собственным (существовавшим
  до этой story) `DROP TABLE`-в-`tearDown()`, не восстанавливая её — общие имена в этой
  тестовой БД в принципе никогда не гарантированно «настоящие» в середине прогона.
  Правильное решение — не трогать общие имена вообще, а не чинить бэкап поверх них.
  `GreenhouseProductionHandler` теперь принимает ВСЕ свои модели через конструктор (тот
  же опциональный DI, что уже был у `$cfg`/`$buildingEffects`) плюс `GameSettingsService`
  (переопределяет `GameSettingsReaderTrait::gs()` через trait-алиас `gs as private
  traitGs`, поскольку `gs()` жёстко создавал `new GameSettingsService()` с реальной
  `game_settings`). Тест подставляет модели с `Model::setTable('ghwt_...')` — уникальный
  префикс, который не может пересечься ни с одним другим тестом в репозитории. `setUp()`/
  `tearDown()` вернулись к простому безусловному `DROP TABLE IF EXISTS` + `CREATE TABLE`
  на этих приватных именах — безопасно, потому что имена никому больше не принадлежат.
- Добавлены `backpackRow()` helper и тест `testFullBackpackDrainByConsumptionLeavesNoZeroRow`
  (пул целиком из рюкзака, склад не трогается) — доказывает отсутствие нулевой строки
  именно на пути СПИСАНИЯ, а не только уведомления; тем же `assertNull` довооружён
  `testStorageCoversShortfallAfterBackpackDrained`.
- Репродукция CI-сценария локально (обязательное условие приёмки): вручную создал реальные
  таблицы `game_settings`/`buildings`/`resources`/... в локальной `wildworld_tests` с
  sentinel-строками (временным PHPUnit-тестом, не закоммичен), прогнал
  `GreenhouseProductionWaterTest` — 10/10 зелёных, sentinel-строки не тронуты. Прогнал ту
  же связку ВМЕСТЕ с `AchievementServiceTest` — sentinel `game_settings` пропадает, но
  из-за `AchievementServiceTest::tearDown()` (её собственный `DROP TABLE IF EXISTS
  game_settings` без восстановления — существовал до этой story, вне `## Files`, не
  трогал). Изолированный прогон (только seed → наш тест → verify, без Achievement)
  подтвердил: реальные таблицы переживают наш тест невредимыми. Полный `composer test`
  после фикса — 2928/2928 зелёных.

## Findings
Первая версия фикса (backup через `RENAME TABLE` + `tableExists()`) прошла локально
(пустая `wildworld_tests`, ветка бэкапа ни разу не исполнилась), но упала на CI (10 ошибок,
`Table 'wildworld_tests.game_settings' doesn't exist`, GitHub Actions прогон 32232665896) —
там же, где команда-lead её и поймал. Гипотеза, подтверждённая репродукцией: причина не в
логике rename/restore самой по себе, а в допущении, что общее имя таблицы в тестовой БД
имеет стабильное «настоящее» состояние, которое можно временно одолжить и вернуть. В
реальности десятки DB-тестов в этом репо (`AchievementServiceTest` и другие с `$migrate =
false`) уже годами безусловно дропают/создают/дропают эти же имена без восстановления —
любой другой тест того же прогона мог унести таблицу до нашего `tableExists()` check.
Урок: для теста, вынужденного трогать общее имя таблицы в такой тестовой БД, единственный
надёжный путь — не трогать общее имя вообще (свои приватные имена + DI моделей), а не
пытаться временно «одолжить» чужое.
