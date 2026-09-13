---
story: angela-second-base-bugs-03
spec: angela-second-base-bugs
status: done
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
`vendor/bin/phpunit --no-coverage --no-progress`

## Замечания по тестам (обязательно прочесть)
- Обязателен тест на то, что чужая строка НЕ изменилась, — иначе порча данных останется незамеченной.
- Тест должен проверять поведение, а не наличие строки в исходнике.
- DB-тест строит свою схему сам (CI на пустой базе), схема — из миграции, не по памяти.
- Смена сигнатуры публичного метода валидатора ломает тестовые подклассы и валит ВЕСЬ набор фаталом загрузки: если меняешь сигнатуру, прогреби по репо всех наследников и вызывающих.
- НИКОГДА `git stash` / `git checkout`; старый вариант файла — `git show HEAD:<path>`.
- Не запускай весь набор параллельно с другими агентами.

## Implementation notes

- `BuildingUpgradeValidator.php` — новый шаг 1b: резолв целевой базы через
  `ClaimedCellModel::resolveTargetBaseCell($characterId, $character['cell_number'])`
  сразу после старого шага 1 («на базе»), до поиска строки `character_buildings`.
  Null → отказ текстом из `## Contracts`. Шаг 2 получил `->where('map_cell_id', $targetMapCellId)`.
  Конструктор получил 6-й опциональный параметр `?ClaimedCellModel $claimedCellModel = null`
  (append в конец, позиционные вызовы с 5 аргументами не ломаются).
- `BuildingUpgradeApplier.php` — код НЕ менялся. `update($charBuilding['id'], …)` и раньше
  бил по правильному id, только id приходил из бажного шага 2 валидатора; с починкой шага 2
  applier автоматически перестал портить чужую строку — отдельного фикса не требовалось.
- `BaseBuildingUpgradeAction.php` — тот же паттерн (резолв базы + `where('map_cell_id', …)`
  + отказ при `null`) продублирован в generic-путь для `RoboticsWorkshopUpgradeAction`
  (единственный наследник). Проверил перед правкой: `RoboticsWorkshopUpgradeAction` нигде не
  инстанцируется/не роутится (`grep -rn` по `app/` — ноль вызовов кроме своего же файла) —
  мёртвый пример-код, но раз файл в Files списка и несёт тот же баг класса — почил тем же
  патчем ради согласованности с валидатором, реального игрового пути это не меняет.
  `RepairBuildingAction.php` НЕ трогал — он `extends BaseAction`, к `BaseBuildingUpgradeAction`
  отношения не имеет, вне Files и вне пути этого бага.
- `UpgradeBuildingAction.php` — не менялся. Обе двери (ask/confirm) уже шли через
  `$this->validator->validate(...)`, читая свежий `$character['cell_number']` из БД на каждый
  callback — фикс полностью внутри валидатора, «Ask и confirm видят одну базу» выполняется
  автоматически (одна и та же детерминированная функция от текущей позиции персонажа).
- `PoolAdoptionRepairUpgradeTest.php` — НЕ в Files этой story, но новый обязательный вызов
  `ClaimedCellModel::resolveTargetBaseCell()` внутри `validate()` ломал все его тесты (дефолтный
  `new ClaimedCellModel()` бил по реальной `claimed_cells`, которой в изолированном наборе этого
  файла нет — `Table 'wildworld_tests.claimed_cells' doesn't exist`). Добавил `claimedCellModelDouble()`
  (всегда возвращает `1`, т.к. `characterBuildingModelDouble` в этом файле игнорирует `where()`-
  фильтры и так) и передал 6-м аргументом в `validator()`. Коллизия неизбежна при добавлении
  параметра с боевым дефолтом в конструктор, используемый другим тестом с частичными доублами —
  задокументировано здесь, а не молча пропущено.
- Тестовая схема `bubs_claimed_cells`/`bubs_character_buildings` (приватный префикс, без FK) —
  1:1 колонки с `2024-05-23-061031_CreateClaimedCellsTable.php` и
  `2024-05-27-105534_CreateCharacterBuildingsTable.php` (+ `..._AddLastTaxCollectedToCharacterBuildings.php`),
  изолирована от общих таблиц, за которые в этот момент дерутся параллельные агенты волны.

## Findings
