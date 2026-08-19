---
story: storage-craft-insurance-09
spec: storage-craft-insurance
status: todo
tier: 3
worker: worker-code
tracer: false
wave: 4
blocked_by: [storage-craft-insurance-06]
---

# Откаченная транзакция перестаёт выглядеть успехом

## Goal
Три новых пути оплаты завершают транзакцию и не смотрят на её исход. `transComplete()` без
проверки `transStatus()` означает: откат произошёл, исключения нет, эффект применяется.
У ремонта это бесплатный ремонт, у апгрейда — экран «уровень повышен» при прежнем уровне и
начисленном счёте фракции. Плюс у ремонта оплата коммитится своей транзакцией, а задача
создаётся вне её — провал вставки оставляет игрока без ресурсов и без ремонта.

## Requirements
> #bug [19.08.2026 05:13] Анжела: 1. Крафт ингредиенты доступны только из рюкзака, даже когда я стою на складе, где их тысячи😳.

## Files
- app/Controllers/Telegram/Commands/Actions/Craft/Repair/RepairCraftedItemAction.php
- app/Services/Player/BuildingUpgrade/BuildingUpgradeApplier.php
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/UpgradeBuildingAction.php
- tests/unit/Player/PoolAdoptionRepairUpgradeTest.php

## Non-goals
- НЕ менять стоимость ремонта и требования апгрейда — только транзакционные границы и отказ.
- НЕ трогать крафт: там проверка исхода уже есть, она и служит образцом.
- НЕ расширять правку на ремонт оборонной постройки и ремонт робота — они вне этой спеки.

## Map slice
`memory/map/craft.md` (ремонт), `memory/map/bases.md` (апгрейд).

## Acceptance criteria
- [ ] Каждый путь оплаты отличает закоммиченную транзакцию от откаченной и во втором случае
      НЕ применяет эффект.
- [ ] Оплата ремонта и создание задачи ремонта — в одной транзакционной границе.
- [ ] Перехват в `UpgradeBuildingAction` покрывает и `DatabaseException`, а не только
      `RuntimeException` — иначе исключение пролетает мимо ровно того catch'а, ради которого он и заведён.
- [ ] Игрок в каждом случае получает текст, а не белый экран и не ложный успех.
- [ ] Тесты: откат при неудачной оплате → задача ремонта не создана, уровень постройки не изменён,
      золото не списано.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Player/PoolAdoptionRepairUpgradeTest.php`

## Implementation notes

- `RepairCraftedItemAction`: `deductResources()` больше не открывает свою транзакцию — она
  переехала в новый приватный `payAndScheduleRepair()`, который оборачивает списание ресурсов
  И `character_tasks->insert()` одной `transStart()`/`transComplete()` и бросает `RuntimeException`,
  если `transStatus()===false` после коммита (откат без исключения). `confirmRepair()` зовёт этот
  метод и одинаково реагирует на гонку пула и на неотслеженный откат — текст отказа игроку, задача
  не создаётся.
- `BuildingUpgradeApplier::apply()`: после `transComplete()` добавлена проверка `transStatus()`,
  бросает `RuntimeException` при откате без исключения (тот же приём, что и на гонке ресурсов
  выше по методу).
- `UpgradeBuildingAction::confirmUpgrade()`: `catch (\RuntimeException)` расширен до
  `catch (\RuntimeException|\CodeIgniter\Database\Exceptions\DatabaseException)` — хотя
  `DatabaseException` фактически уже наследует `\RuntimeException` в CI4 8.3, явное перечисление
  снимает зависимость от этой цепочки наследования и прямо покрывает требование review.
- Тесты: добавлен `testPayAndScheduleRepairDoesNotInsertTaskWhenResourceRaceFails` (спай
  `CharacterTaskModel::insert()`, гонка на пуле → `insert()` не вызван). Существующий
  `testApplierApplyDoesNotTouchGoldOrLevelWhenResourceRaceFails` уже покрывал «золото не списано /
  уровень не изменён» для апгрейда — не дублировался.
- Не тронуто: стоимость ремонта, формулы требований апгрейда, крафт, ремонт оборонных построек/робота.

## Findings
