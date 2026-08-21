---
story: pharmacy-split-06
spec: pharmacy-split
status: done
tier: 1
worker: worker-code
tracer: false
wave: 4
blocked_by: [pharmacy-split-02]
---

# Слой полок избавляется от мёртвых частей

## Goal
В новом коде не остаётся зависимостей и состояния, которые ничего не делают, а ключ полки
имеет один источник.

## Requirements
> Не тащить в сервис `Request`/`MediaSender`/модели: он обязан оставаться чистым

## Files
- app/Services/Player/ConsumableShelfService.php
- app/Controllers/Telegram/Commands/Actions/ProvisionAction.php

## Что убрать

1. `ConsumableShelfService`: параметр конструктора `?DebuffService $debuffs`, одноимённое поле
   и геттер `debuffs()`. Сервис ими не пользуется — поле держится живым только геттером,
   а геттер существует, чтобы phpstan не ругался на неиспользуемое поле: они оправдывают друг
   друга и больше ничего. Это дефект моей спеки, а не автора. Убедись, что ни один вызывающий
   не передаёт второй аргумент (грепни `new ConsumableShelfService(`).
2. `ConsumableShelfService::split()` возвращает захардкоженные ключи `'medicine'` / `'provision'`,
   а оба контроллера индексируют результат через `Consumables::SHELF_*`. Смена константы дала бы
   undefined index в рантайме вместо ошибки анализа — верни ключи через константы.
3. `ProvisionAction`: свойство `$craftedItemsModel` создаётся и нигде не используется (скопировано
   из `PharmacyAction`). Убери его вместе с инициализацией в конструкторе.

## Non-goals
- Не трогать `PharmacyAction` — там та же мёртвая модель, но это легаси и отдельная уборка,
  а не хвост этой задачи.
- Не менять формат текста полки и не трогать `itemLine()`.
- Не заводить новых записей в `phpstan-baseline.neon` — если что-то всплывёт, чини кодом.

## Map slice
`app/Services/Player/ConsumableShelfService.php` строки 20–70.

## Acceptance criteria
- [ ] `new ConsumableShelfService()` и `new ConsumableShelfService($expiry)` работают; второго
      параметра нет.
- [ ] `split()` строит массив по константам `Consumables::SHELF_*`.
- [ ] В `ProvisionAction` нет неиспользуемых свойств.
- [ ] `vendor/bin/phpunit --no-coverage --no-progress tests/unit` остаётся зелёным.

## Verification
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress app/Services/Player/ConsumableShelfService.php app/Controllers/Telegram/Commands/Actions/ProvisionAction.php`

## Implementation notes

- `ConsumableShelfService`: убран `?DebuffService $debuffs` из конструктора, поле `$debuffs`
  и геттер `debuffs()`. Ни `use App\Services\Player\DebuffService` не требовался (тот же
  namespace), никаких вызывающих со вторым аргументом не нашлось (`grep new ConsumableShelfService(`
  — только `PharmacyAction`, `ProvisionAction`, тест, все без второго аргумента).
- `split()` теперь возвращает `[self::SHELF_MEDICINE => ..., self::SHELF_PROVISION => ...]`
  вместо литералов `'medicine'`/`'provision'` — значения констант совпадают, phpstan-форма
  `array{medicine:..., provision:...}` не изменилась.
- `ProvisionAction`: убрано неиспользуемое свойство `$craftedItemsModel` и его инициализация
  вместе с `use CraftedItemsModel`.
- phpstan по обоим файлам — чисто. `phpunit tests/unit` — 2444/2444 зелёно (первый прогон
  словил 2 ошибки `Table 'crafted_items' already exists` в `MarchingTransportTest` — это
  коллизия параллельных прогонов других агентов на общей тест-БД, не связано с этой правкой:
  тот же файл изолированно и повторный полный прогон — зелёные).

## Findings
