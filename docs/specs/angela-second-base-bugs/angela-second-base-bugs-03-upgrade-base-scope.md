---
story: angela-second-base-bugs-03
spec: angela-second-base-bugs
status: todo
tier: 3
worker: worker-code
tracer: false
wave: 1
blocked_by: []
---

# Апгрейд проверяет и прокачивает постройку той базы, где стоит игрок

## Goal
«Поднять уровень» перестаёт находить постройку по типу здания и работает со строкой
`character_buildings` текущей базы. Апгрейд здания 1 уровня на второй базе запускается вместо
отказа «Здание уже достигло максимального уровня (10)», и — что важнее — списанные ресурсы
и поднятый уровень уходят в постройку ТОЙ базы, а не в чужую строку.

## Requirements
> На второй базе постройки первого уровня при попытке поднять уровень пишут,
> что уровень уже максимальный, десятый
> Если те баги остались еще, устрани их, протестируй на тест-проде и потом залей на боевой. И с смок-тестами.

## Files
- app/Services/Player/BuildingUpgrade/BuildingUpgradeValidator.php
- app/Services/Player/BuildingUpgrade/BuildingUpgradeApplier.php
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/UpgradeBuildingAction.php
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/Upgrades/BaseBuildingUpgradeAction.php
- tests/unit/Player/BuildingUpgradeBaseScopeTest.php

## Non-goals
- Не менять формат `callback_data` (`upgrade_building_{buildingId}`): базу резолвим на сервере в момент обработки — так же, как это делает `GenericBuildingAction`. Смена формата ломает кнопки в уже отправленных сообщениях.
- Не трогать четырнадцать `*Handler.php` карточек — это история 02 в той же волне.
- Не менять стоимость апгрейда, `MAX_LEVEL`, множитель `economy.upgrade.cost_multiplier.*` и тексты отказов, кроме случая неоднозначной базы.
- Не добавлять методы в `ClaimedCellModel` — им владеет история 01.
- Не «чинить заодно» `RoboticsWorkshopUpgradeAction.php` и `RepairBuildingAction.php`, если они не на пути этого бага: сначала проверь, потом решай, и если тронул — скажи об этом в `## Implementation notes`.

## Map slice
`memory/map/bases.md` (апгрейды, налог, мульти-база), `memory/map/player.md` (`Services/Player`).

## Ключевые места (разведка уже сделана, искать не надо)
- `app/Services/Player/BuildingUpgrade/BuildingUpgradeValidator.php:81-88` — шаг 2 валидации: `->where('character_id',…)->where('building_id',$buildingId)->first()` без `map_cell_id` и без `orderBy`. Шаг 4 читает `level` этой строки и на 10 отдаёт «Здание уже достигло максимального уровня (10)».
- `app/Services/Player/BuildingUpgrade/BuildingUpgradeApplier.php:117` — `update($charBuilding['id'], …)` по строке, которую нашёл валидатор. Это и есть порча данных: правильная валидация чинит и запись.
- `app/Controllers/Telegram/Commands/Actions/Camp/Buildings/UpgradeBuildingAction.php:90-98` — парсит `buildingId` из callback и зовёт валидатор дважды (ask и confirm). Обе двери должны получить одну и ту же базу.
- `app/Controllers/Telegram/Commands/Actions/Camp/Buildings/Upgrades/BaseBuildingUpgradeAction.php:78` — третья дверь с тем же запросом.
- Резолв базы — `ClaimedCellModel::resolveTargetBaseCell($characterId, (int) $character['cell_number'])` (ADR-102), см. `## Contracts` плана.

## Acceptance criteria
- [ ] Апгрейд здания 1 уровня на второй базе проходит валидацию, хотя такое же здание 10 уровня есть на первой.
- [ ] После апгрейда `level` растёт у строки `character_buildings` с `map_cell_id` текущей базы, а строка другой базы не изменена.
- [ ] Отказ «уже достигло максимального уровня» по-прежнему приходит, когда 10 уровня достигла постройка ИМЕННО текущей базы.
- [ ] Отказ «У вас нет здания с ID=…» приходит, когда на текущей базе такого здания нет, даже если оно есть на другой.
- [ ] Неоднозначная база (`resolveTargetBaseCell` вернул `null`) — согласованный текст из `## Contracts` плана, ресурсы не списаны.
- [ ] Ask и confirm видят одну и ту же базу: между двумя нажатиями подмены строки не происходит.
- [ ] Персонаж с одной базой: поведение апгрейда не изменилось.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Player/`

## Замечания по тестам (обязательно прочесть)
- Обязателен тест на то, что чужая строка НЕ изменилась, — иначе порча данных останется незамеченной.
- Тест должен проверять поведение, а не наличие строки в исходнике.
- DB-тест строит свою схему сам (CI на пустой базе), схема — из миграции, не по памяти.
- Смена сигнатуры публичного метода валидатора ломает тестовые подклассы и валит ВЕСЬ набор фаталом загрузки: если меняешь сигнатуру, прогреби по репо всех наследников и вызывающих.
- НИКОГДА `git stash` / `git checkout`; старый вариант файла — `git show HEAD:<path>`.
- Не запускай весь набор параллельно с другими агентами.

## Implementation notes

## Findings
