# Recon — multibase-picker (drone-scout ×2, 2026-09-15)

Сводка двух разведок; строки — file:line на момент 2026-09-15. Подсказка, не истина — сверять.

## «🏠 База» и Вышка связи

- Живой обработчик «🏠 База» — `app/Controllers/Telegram/Commands/Actions/Camp/DetailedBaseInfoAction.php::handle()` (:19-86). `BaseService.php` в `app/Services/` не найден (map `bases.md` ссылается на него — устарело; `BaseService.php:57-92 showBaseInfo()` упоминает вторая разведка — сверить, какой из них живой).
  - :50-56 — `ClaimedCellModel::findActiveCell($charId,$cell)` → игрок на своей базе → `showBuildings()` этой клетки (корректно и при мульти-базе).
  - :61-66 — не на базе → `findAllActiveCells()[0]` (первая по id); нет баз → `handleNoBase()`.
  - :69-85 — `CommunicationTowerCoverageService::checkCoverage($charId)`; `isCovered` → удалённый вид ПЕРВОЙ активной базы; иначе `handleNotOnBasePhysically()` (текст :117-162, координаты/биом первой базы, кнопки Телепорт/Двигаться).
  - :239-243 — кнопки построек `callback_data = 'building_' . $building_id . '_' . $bNameEng` — идентификатора базы НЕТ.
- `BaseServiceMessageFormatter` (`app/Services/Bases/BaseServiceMessageFormatter.php`) — `baseBuildings()` :141, `notOnBasePhysically()` :107, строка «🤖 Ангар» :212-214 (`callback_data 'hangar'`, всегда). Разведка НЕ подтвердила, живой ли этот форматтер для `DetailedBaseInfoAction` — открытый вопрос, проверить grep'ом вызывающих.
- `app/Services/Coverage/CommunicationTowerCoverageService.php::checkCoverage(int $characterId): array{hasTower,towerLevel,distanceToBase,maxCoverage,isCovered,message}` (:65-199). :68-71 — база через `first()` без `orderBy` (одна, произвольная); :105-107 — второй `first()`; :182 — радиус `towerLevel * 100` зашит (нарушение ADR-024). Вызывающие: `BaseScopeResolver.php:60`, `DetailedBaseInfoAction.php:70`, `StartRobotGatheringAction` (outbound).
- `app/Services/Bases/BaseScopeResolver.php::resolve(int $characterId, int $currentCell): array{cell,reason,text}` (:48-69); под вышкой — первая активная по id (:60-66); иначе `ambiguous` (:68), текст :32. Используют 14 карточек через `Camp/BuildingHandlerAction` и `BuildingUpgradeValidator`.
- Хранилища «выбранной базы» нет (ни колонки, ни кэша). Единственный прецедент — идентификатор в `callback_data`.
- Роутинг: `app/Config/CallbackRoutes.php` (группа Camp/Base), `app/Services/Telegram/CallbackPrefixDispatcher.php` (префикс+суффикс, напр. `upgrade_building_{id}`). Лимита 64 байта на `callback_data` в коде/доках не найдено — нужен тест.
- `BaseBuildingUpgradeAction` — мёртвый код; живой апгрейд `CallbackPrefixDispatcher → UpgradeBuildingAction → BuildingUpgradeValidator::validate()`.

## Прецедент выбора базы — телепорт (backpack-teleport-base-choice)

- `app/Services/Player/TeleportUse/TeleportUseValidator.php` — `listActiveBases(int $characterId)` :289-320 → `id, map_cell_id, camp_name, coordinate_x, coordinate_y`, по id ASC; `findBaseLocation()` :350-384 — 0 баз → ошибка, 1 → сразу, ≥2 без id → `reason:'choose_base', bases`; с id — проверка владения+active (:352-362).
- `TeleportUseAction.php` — `parseCallbackData()` :83-90 регэксп `^TeleportUse_(…)(?:_(\d+))?$`; `handleReason()` :99-119.
- `TeleportUseMessageFormatter::chooseBase()` :124-159 — кнопка `"🏠 {$name} ({$x},{$y})"`, `callback_data "TeleportUse_{$kind}_{$id}"`, ряды через `App\Services\Telegram\ButtonPacker::pack()`, хвост `[↩️ Назад | 🏠 База]`, текст перечисляет базы до кнопок (media-off), имена через `MarkdownSafe::name()`.

## «🤖 Ангар» и роботы

- `app/Controllers/Telegram/Commands/Actions/Camp/HangarAction.php` — всё по `character_id`, без базы:
  - `workshopLevel()` :371-397 — `max(level)` Мастерской робототехники по ВСЕМ базам (комментарий :368). Корень бага: база-2 без Мастерской видит уровень базы-1.
  - `robotsBlock()` :160-228, `dronesBlock()` :280-334 — `CraftedItemsLogModel` по `character_id` (:175-179, :305-310); `hasAutomationGear()` :341-365; `handle()` :55-73 — lock/unlock только от `workshopLevel`.
- Роботы/дроны — `crafted_items_log` (`app/Models/CraftedItemsLogModel.php`), только `character_id`, связи с базой в схеме нет — это инвентарь персонажа.
- `Camp/Buildings/Robots/StartRobotGatheringAction.php` — `BaseCheckService::checkBaseStatus()` :75-76 («есть ли база / стоит ли на ЛЮБОЙ своей»), Мастерская :137-140 по `character_id+building_id` + `first()`; экран запуска :262-279 базу не называет; задание `character_tasks` :224-234, `task_settings` JSON только `crafted_item_id`.
- `Camp/Buildings/Robots/RobotGathererActivator.php` существует (разведка его не прочла; study называл :74,179-181,220).
- Прочие файлы `Camp/Buildings/Robots/`: `ActivateRobotHandler`, `AllRobotsHandler`, `RobotActivatorInterface`, `RobotExplorerActivator`, `RobotRepair*`, `SetCoordinatesRobotExplorerAction`, `StartRobotExplorationAction` — не разведаны.
- `app/TaskHandlers/CompleteRobotGatheringHandler.php` — база заново :120-123 `claimed_cells … status=active … first()` без `orderBy`; Мастерская :144-147 без `map_cell_id` — **сознательно** убрано в v0.51.30 (комментарий :138-141: игрок перенёс/добавил базу, завершение брало старую клетку). Новый фикс не должен вернуть ту регрессию. BFS сбора :167-169 от `$baseCellNumber`.
- `character_buildings` несёт `map_cell_id` (`CharacterBuildingModel.php:20`).

## Твины

- `app/Services/Housing/BaseCampDecorService.php:124-134 resolveCell($charId,$cellNumber)` — если передана клетка активной базы — она, иначе `findFirstActiveCell()`; вышку не знает.
- `app/Controllers/Telegram/Commands/Actions/Camp/BaseDevelopmentAction.php:70-75` — `GROUP BY b.id, MAX(cb.level)` по `character_id`.
- `TeleportBeacon*.php` — `Camp/Buildings/TeleportBeacon.php`, `TeleportBeacon{Move,MoveConfirm,Remove,Set}Action.php`; решение владельца: правило «Центр на любой базе» — намеренное, не чинить.

## Тесты (есть в git)

- `tests/unit/TaskHandlers/CompleteRobotGatheringNameTest.php`
- `tests/unit/TeleportBeacon/*` (4 файла)
- `tests/unit/Camp/BuildingCardBaseScopeTest.php` (прошлая спека; фиксирует текст отказа `ambiguous`)
- Со слов vault: `tests/database/BuildingBaseBindingTest.php`, `GuideCatalogTest` (раздел `base`, number-gate).
- Тестов на `BaseScopeResolver`, `CommunicationTowerCoverageService`, `HangarAction`, `TeleportUse*` по имени не найдено — воркеру проверить `grep -rln` в `tests/`.

## Прочее

- `/guide`: `app/Services/Onboarding/GuideCatalog.php`, раздел ключ `base` (группа `start`), уже расширялся под мульти-базу 04.09 и 10.09.
- Категории `game_tips`: `биомы, ресурсы, крафт, персонаж, события, NPC, общие, земледелие, еда, квесты, фракции, бой, эндгейм, настройки`; прецедент мульти-база-совета (`DuplicateBuildingsAbsorbed`) — `общие`.
- ADR-187 в vault НЕТ — существует только как черновик `docs/specs/angela-second-base-bugs/adr-proposals.md`.
- Тестовая инфраструктура: у субагентов Glob и Grep по каталогу возвращают пусто — пользоваться `Bash grep -rn`/`ls`.
