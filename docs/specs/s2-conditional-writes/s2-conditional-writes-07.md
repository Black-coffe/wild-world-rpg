---
story: s2-conditional-writes-07
spec: s2-conditional-writes
status: done
returned: DONE
tier: 3
worker: worker-code
model: haiku
wave: 2
blocked_by: []
red: required
red_reason: non-transport item was activated as a vehicle
red_verified_at: 2026-10-28T20:57:50Z 5274513786274411b1662aa3d3b4a3f529031cffb42d422fd5086efc958b60b9
---

# F22: `VehicleActivationService::activate()` отвергает предмет без ключа транспорта

## Goal

`VehicleActivationService.php:100-110` проверяет только владение предметом: любой предмет владельца можно
«активировать» как транспорт. После story `activate()` возвращает `false`, если `name_eng` предмета не разрешается
через `VehicleEffectsService::keyForItemNameEng()` в непустой ключ транспорта; транспорт активируется как прежде.
`model: haiku` — один гвард в одном методе плюс тест на две ветки.

## Requirements

> 8. F22 — `VehicleActivationService::activate()` возвращает `false`, если предмет владельца не разрешается в непустой ключ
> F22 `VehicleActivationService::activate()` принимает не только транспорт

## Files

- app/Services/Player/VehicleActivationService.php
- tests/database/VehicleActivationNonTransportTest.php

## Non-goals

- Не менять вызывающих и их тексты при `false` (что они показывают — вопрос 4 плана; отметить в отчёте, не чинить).
- Не трогать `VehicleEffectsService` и таблицу ключей транспорта (ADR-174).
- Не добавлять новый текст отказа внутрь сервиса — он возвращает `bool`, как сегодня.
- Если существующий тест (symbol-map: `tests textual 5`) активирует не-транспорт как фикстуру и краснеет — это
  находка в отчёт, не правка чужого теста.

## Map slice

- Recon до волны 1 (Q4, 28.10): вызывающий `VehicleAction.php:75-77` на `false` показывает `activationFailed()` (`:110-122`, один текст на все причины, тест `Tests\Unit\Transport\VehicleScreenRenderTest`) — текст не меняем. Внимание: `tests/unit/Transport/VehicleActivationServiceTest.php` (`:197-297`) и `DeathBreaksVehicleTest.php:174` активируют фикстуры — если их предмет не транспорт, тест покраснеет от правки: это `WALL` с перечнем, а не правка чужого теста. `VehicleActivationService.php:34-36` упоминает фолбэк по каталогу для не-транспорта — сверить, что правка его не ломает.

`memory/map/player.md` → Entry points (`Services/Player`, инъекция `BaseConnection` — принятый паттерн), Key types
(`CharacterEntity` — не массив). Форма теста — `plan.md` → `## Contracts`.

## Acceptance criteria

- [ ] `activate()` до любой записи резолвит ключ транспорта по `name_eng` предмета; пустой ключ → `false`, ни одной
      записи в БД.
- [ ] Предмет с непустым ключом активируется так же, как до story (существующий путь не изменён).
- [ ] Тест `VehicleActivationNonTransportTest`: два метода — предмет владельца без ключа транспорта → `false`, строки
      активного транспорта нет; настоящий транспорт → `true`. Сообщение ассерта при провале:
      `non-transport item was activated as a vehicle`.

## Verification

`vendor/bin/phpunit --no-coverage --no-progress tests/database/VehicleActivationNonTransportTest.php && vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes

- `app/Services/Player/VehicleActivationService.php`: `activate()` берёт строку через `fetchOwnedRow()`, резолвит `name_eng` через `VehicleEffectsService::keyForItemNameEng()`, на `null`/пустом ключе возвращает `false` до `setPointer()`. Остальные пути сервиса не тронуты.
- `tests/database/VehicleActivationNonTransportTest.php`: два метода (`LightCart` → `true`; `Hammer` → `false` и указатель `NULL`).
- Единственный прод-вызыватель `activate()` — `VehicleAction.php:101`; его тексты не менялись (Non-goal).

## Findings

Существующие тесты, краснеющие от правки (фикстуры — не-транспорт, по Non-goal не правились):

- `tests/unit/Transport/VehicleActivationServiceTest.php`: 8 из 19 падают. Причина: фикстура `seedTemplate('cart', …)` (строчная, в `NAME_ENG_TO_KEY` есть только `LightCart`) и `seedTemplate('snowmobile', …)` (`:279`; ключ `Snowmobile`); `testResolveActiveNonVehicleItemStillClampsToCatalog` (`:480`, `MedKit`) вызывает `activate()` и ждёт указатель. Падают: `testReactivatingWithAnotherOwnedRowMovesPointer`, `testSpendChargesDeductsExactlyCellsTimesWearFromActiveRowOnly`, `testSpendChargesRespectsWearPerCellMultiplier`, `testSpendChargesClampsGarbageDurabilityToTemplateBase`, `testResolveActiveOnZeroChargesReturnsRowWithZeroChargesNoException`, `testBreakActiveZeroesDurabilityClearsPointerKeepsRow`, `testCharacterResetNullsActiveVehiclePointer`, `testResolveActiveNonVehicleItemStillClampsToCatalog`.
- `tests/unit/Transport/DeathBreaksVehicleTest.php`: 1 из 6 падает — `testDeathWithActiveVehicleZeroesDurabilityClearsPointerKeepsRow` (`:174`), фикстура `seedTemplate()` с `name_eng = 'cart'` (`:97`).

Правка их не чинит: решение о переименовании фикстур на `LightCart` / `Snowmobile` — за владельцем (map slice помечает это `WALL` с перечнем).

## Red receipt
- command: vendor/bin/phpunit --no-coverage --no-progress tests/database/VehicleActivationNonTransportTest.php && vendor/bin/phpstan analyse --memory-limit=512M --no-progress
- exit: 1
- tests: 5274513786274411b1662aa3d3b4a3f529031cffb42d422fd5086efc958b60b9 (tests/database/VehicleActivationNonTransportTest.php)
- impl: 40d3435a6ab3f2e2f7a7e2ed5c6f0577055493d5395a2b30c7f6301971e0f2af
- stamped: 2026-10-28T20:57:50Z

```
There was 1 failure:

1) Tests\Database\VehicleActivationNonTransportTest::testNonTransportItemIsRejectedWithoutWrite
non-transport item was activated as a vehicle
Failed asserting that true is false.

C:\laragon\www\mmorpg\tests\database\VehicleActivationNonTransportTest.php:74

[37;41mFAILURES![0m
[37;41mTests: 2[0m[37;41m, Assertions: 3[0m[37;41m, Failures: 1[0m[37;41m, PHPUnit Deprecations: 1[0m[37;41m.[0m
```
