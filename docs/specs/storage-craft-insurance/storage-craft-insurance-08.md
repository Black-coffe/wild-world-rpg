---
story: storage-craft-insurance-08
spec: storage-craft-insurance
status: done
tier: 3
worker: worker-code
tracer: false
wave: 4
blocked_by: [storage-craft-insurance-01]
---

# Пул отвечает за то, что списал, а не за то, что прочитал

## Goal
`consume()` перестаёт рапортовать об оплате, которой могло не произойти. Сейчас он читает
остаток, решает, что хватает, вызывает два списания и возвращает намерение, не глядя на
результат: `BaseStorageModel::withdraw()` по собственному докблоку может списать меньше
запрошенного, а `decreaseResources()` возвращает `false`, когда строки нет. Между чтением и
записью нет ни блокировки, ни повторной проверки — два одновременных запроса на одного
персонажа (двойной тап по кнопке, вебхук рядом с крон-Worker'ом) печатают ресурс из воздуха.

## Requirements
> #bug [19.08.2026 05:13] Анжела: 1. Крафт ингредиенты доступны только из рюкзака, даже когда я стою на складе, где их тысячи😳.

## Files
- app/Services/Player/ResourcePoolService.php
- app/Models/BaseStorageModel.php
- tests/unit/Player/ResourcePoolServiceTest.php
- tests/unit/Storage/BaseStorageWithdrawTest.php
- tests/unit/Craft/CraftPoolConsumptionTest.php

## Non-goals
- НЕ переводить вызывающих на новый режим отказа — их сигнатуры не меняются, они уже ловят
  `RuntimeException`. Правка живёт внутри пула и модели.
- НЕ вводить блокировку строк ради самой блокировки, если проверки фактически списанного
  достаточно: цель — чтобы недоплата была НЕВОЗМОЖНА незаметно, а не чтобы код выглядел строже.
- НЕ менять порядок трат (рюкзак → склад) и не трогать killswitch.

## Map slice
`memory/map/bases.md` (склад), `memory/map/data-layer.md` (модели).

## Acceptance criteria
- [ ] `consume()` сверяет фактически списанное по КАЖДОМУ источнику с затребованным.
- [ ] Расхождение = отказ: транзакция откатывается, `RuntimeException`, ни рюкзак, ни склад
      не остаются частично списанными.
- [ ] `decreaseResources()`, вернувший `false`, считается неудачей, а не успехом.
- [ ] Возвращаемая разбивка описывает то, что РЕАЛЬНО ушло.
- [ ] `BaseStorageModel::withdraw()` покрыт собственным тестом: несколько строк на пару
      (персонаж, ресурс); qty больше суммы; `PHP_INT_MAX`; обнулившаяся строка удаляется.
- [ ] Тест воспроизводит расхождение (склад отдал меньше обещанного) и доказывает отказ.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Player/ResourcePoolServiceTest.php tests/unit/Storage/BaseStorageWithdrawTest.php`

## Implementation notes

- `ResourcePoolService::withdrawBackpack()` больше не зовёт `CharacterResourceModel::decreaseResources()`
  (он вычитает `amount` безусловно и при недостатке всё равно удаляет строку — врёт об успехе). Вместо
  этого — один атомарный `UPDATE character_resources SET quantity = quantity - ? WHERE ... AND quantity >= ?`
  через `\Config\Database::connect()`; `affectedRows()` — единственный источник правды об успехе.
  Возвращает `int` (было `void`): `$qty` при успехе, `0` при недостаче — ничего не тронуто.
- `withdrawStorage()` не изменился по контракту, но теперь его результат (уже был `int`) реально сверяется
  вызывающим кодом, а не игнорируется.
- Добавлены `restoreBackpack()`/`restoreStorage()` — компенсирующий откат уже списанного при отказе
  (частичная оплата недопустима). Собственную транзакцию `consume()` по-прежнему НЕ открывает — контракт
  не менялся, откат ручной (`increaseResources`/`deliver`), не через `transRollback()`.
- `consume()` сверяет фактически списанное с затребованным по каждому источнику; расхождение = `RuntimeException`
  + откат. Публичные сигнатуры `consume()`/`consumeByName()` не менялись — вызывающие ловят тот же `RuntimeException`.
- `BaseStorageModel::withdraw()` переведён на per-row атомарный `UPDATE ... WHERE quantity >= ?`
  (вынесен в новый protected `withdrawRow()` — это дало тестируемый seam для гонки). Больше не читает
  строку и не пишет её обратно из устаревшего снапшота.
- Тесты: `ResourcePoolServiceTest` расширен двумя тестами гонки (недостача в рюкзаке / недостача на складе
  + полный откат обоих источников) с чистым тестовым двойником. Новый `BaseStorageWithdrawTest` бьёт по
  реальной БД (`DatabaseTestTrait`, упрощённая схема `base_storage` без миграций) — несколько строк на пару,
  `qty` больше суммы, `PHP_INT_MAX`, удаление обнулившейся строки, и воспроизведение самой гонки через
  подмену `withdrawRow()` (строка реально уменьшается конкурентным запросом между `findAll()` и UPDATE).
- `CraftPoolConsumptionTest.php` (story-05, добавлен в `## Files` этой стори team-lead'ом после ревью
  первого прохода — см. `## Findings`) — двойник `poolDouble()` доведён до контракта живого сервиса:
  `withdrawBackpack()`/`withdrawStorage()` теперь `: int` и умеют симулировать недостачу
  (`backpackShortfallTo`/`storageShortfallTo`), добавлены `restoreBackpack()`/`restoreStorage()`. Новый тест
  `testSubtractResourcesThrowsAndRollsBackWhenStorageWithdrawsLessThanPromised` — НЕ дубль тестов
  `ResourcePoolServiceTest`: там проверяется сам пул в изоляции, здесь — что `GenericCraftActionStart::
  subtractResources()` реально доходит до настоящей (не переопределённой в этом двойнике) логики
  `consume()`, и расхождение склада не проглатывается на уровне крафта. Существовавший
  `testSubtractResourcesPropagatesRaceInsteadOfSwallowing` — другой класс гонки (искусственный throw в
  переопределённом `consumeByName()`, симулирующий списание за пределами пула), оставлен как есть.

## Findings

- **Коллатеральный разрыв, обнаруженный первым проходом, устранён вторым** (team-lead добавил
  `tests/unit/Craft/CraftPoolConsumptionTest.php` в `## Files`): анонимный сабкласс `ResourcePoolService`
  в `poolDouble()` переопределял `withdrawBackpack()`/`withdrawStorage()` с сигнатурой `: void`, несовместимой
  с новым `: int` — fatal `Declaration ... must be compatible`. Почищено выше. Единственный внешний файл с
  таким разрывом (grep по всему `tests/` на `ResourcePoolService|BaseStorageModel` дал 5 файлов;
  `PoolAdoptionRepairUpgradeTest.php` и `GreenhouseProductionWaterTest.php` не переопределяли эти
  protected-методы и были зелёными с первого прохода).
