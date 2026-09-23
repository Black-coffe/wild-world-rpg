---
story: bugs-info-0923-04
spec: bugs-info-0923
status: todo
returned:
tier: 3
worker: worker-code
model: opus
tracer: false
wave: 1
blocked_by: []
---

# Русские имена ресурсов в находке стратегического объекта

## Goal
Сообщение «🏛 Найден стратегический объект!» (`StrategicLootHandler`) называет ресурсы по `resources.name`. Для Старой фермы это «Зерновые культуры / Фрукты / Древесина / Глина / Вода» вместо `Crops / Fruit / Wood / Clay / Water`. Причина, по разведке `:181-186`: `ResourceModel` возвращает `ResourceEntity`, `is_array($resRow)` ложно, поэтому печатается сырой ключ `name_en`. Кроме того, каждый из четырёх кандидатов-двойников того же класса проверяется. Если он читает строку `ResourceModel` правильно, в Implementation notes записывается вердикт «чисто» с номером строки. Если нет — исправляется с тестом.

## Requirements
> 📦 Добытые ресурсы:
> — Crops: 3 шт.
> — Fruit: 2 шт.
> — Wood: 6 шт.
> — Clay: 29 шт.
> — Water: 6 шт.
> нет перевода на старой ферме
> Якщо ці баги в грі є, береш їх всі, плануєш через вулик-план і запускаєш починку, ремонт цих багів

## Files
- app/TaskHandlers/Objects/StrategicLootHandler.php
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/Greenhouse/PlantCropActionStart.php
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/Robots/RobotRepairConfirmAction.php
- app/Controllers/Telegram/Commands/Actions/Drone/CargoDroneSendAction.php
- app/Services/Player/BuildingUpgrade/BuildingUpgradeValidator.php
- tests/unit/TaskHandlers/StrategicLootResourceNamesTest.php
- tests/unit/Camp/ResourceEntityTwinsTest.php

## Non-goals
- Двойника не переписывать «заодно»: правка только там, где строка `ResourceModel` читается неверно (`is_array()` / `['name']` на сущности). Остальное — вердикт «чисто».
- `tests/unit/Camp/ResourceEntityTwinsTest.php` создаётся, только если исправлен хотя бы один двойник.
- Не менять `ResourceModel::$returnType`, `ResourceEntity` и ключи сида `S24AddIslandFarm`: чинится чтение, а не источник.
- Не искать двойников за пределами четырёх из брифа. Найденный по дороге пятый — в Findings, не в диф.
- В `BuildingUpgradeValidator` не менять сигнатуру `validate()` (на ней стоит `UpgradeBuildingAction`).

## Map slice
`memory/map/player.md` — Key types (Entity, а не массив; `toRawArray()` обходит касты). `memory/map/world.md` — Key types (тот же класс ловушки на `BiomeModel`). `memory/map/tasks-worker.md` — как TaskHandler шлёт итог. В тестах TaskHandler'а поднять/подменить Telegram-отправку (memory `feedback_taskhandler_telegram_init_in_tests`).

## Acceptance criteria
- [ ] Текст находки Старой фермы с настоящим `ResourceEntity` из `ResourceModel` (не массив-заглушка) содержит «Зерновые культуры», «Фрукты», «Древесина», «Глина», «Вода» и не содержит `Crops`/`Fruit`/`Wood`/`Clay`/`Water`. Проверяет `StrategicLootResourceNamesTest` на схеме `resources`, которую тест строит сам (строки id 45, 48, 2, 13, 17 с `name`/`name_en`).
- [ ] Ключ, которого нет в `resources`, печатается без фатала: остаётся сырым, как сейчас.
- [ ] Для каждого из четырёх двойников в Implementation notes есть строка «файл:строка — чисто | исправлено (тест `ResourceEntityTwinsTest::…`)».
- [ ] Каждый исправленный двойник покрыт тестом, который красный на старом коде.
- [ ] Одиночный прогон зелёный: `vendor/bin/phpunit --no-coverage --no-progress tests/unit/TaskHandlers/StrategicLootResourceNamesTest.php`.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes

## Findings
