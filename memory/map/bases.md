<!-- Срез-указатель, а не копия территории. Подробность — в mmorpg-vault; здесь только то,
     что нужно, чтобы понять, куда идти, и не вляпаться. Посеян обследованием дерева репозитория
     и конституцией проекта 2026-08-19; углубляется /vulyk-map <path> через drone-scout. -->
last-verified: 2026-10-08

# Scout report: Базы, лагерь, постройки

## Purpose
Строительство и жизненный цикл баз: лагерь, здания, апгрейды, лимиты, налог, мульти-база,
защита от рейда, выбор базы кнопками (ADR-187). С w2-n4-base (v0.51.681, ADR-190) — ядро без
`chat_id`, два рендерера: бот и веб `/play?view=base` (см. `website.md`).

## Entry points
- **Ядро экрана базы — `App\Services\Bases\BaseScreenService`**: `resolve()` («🏠 База», с пикером),
  `resolveConstruction()` («🏘 Постройки», без пикера), `overview()` (модель, `null` для чужой/неактивной),
  `open()` (визит + онбординг). Рендереры: `BaseService::showBaseInfo()` (`Base`[`_b<id>`] через
  `ShowBaseInfoAction`), `Camp\DetailedBaseInfoAction` (`construction`[`_b<id>`]), `WebNativeScreenService::baseModel()`.
  Строки построек — `BaseBuildingsList::rows()`; тексты/кнопки бота — `BaseServiceMessageFormatter`.
- **Ядро стройки — `App\Services\Buildings\BuildOrderService`**: `catalog()`/`preview()`/`start()`.
  Рендереры: `Camp\BuildListAction` (`Build`[`_b<id>`]), `Camp\GenericBuildingInfoAction`
  (`genericBuildInfo_<Key>`[`_b<id>`]), `Camp\GenericBuildingAction` (`genericStartBuild_<Key>`[`_b<id>`]), веб.
- **Ядро апгрейда — `App\Services\Buildings\BuildingUpgradeService`**: `preview()`/`apply()` + хуки
  (endgame, `FarmersHarvest`). Рендереры: `Camp\Buildings\UpgradeBuildingAction` (`CallbackPrefixDispatcher`,
  `upgrade_building_<id>` / `confirm_upgrade_building_<id>`, опц. `_b<id>`), веб. Запись —
  `Player/BuildingUpgrade/BuildingUpgradeApplier`, проверка — `BuildingUpgradeValidator`.
- `app/Services/Bases/` — также `BaseLifecycleService`, `BaseLimitService`, `BaseCheckService`,
  `CampCheckService`, `BaseLocationResolver`, `BaseCallbackSuffix`, `BaseScopeResolver`.
- **`BaseCallbackSuffix`** — кодек `<callback>_b<claimed_cells.id>`: `append()` (`\LengthException`
  >64 байт), `split()` по `/_b(\d+)$/`. Роутер суффикс не снимает — каждый обработчик сам.
- **`BaseScopeResolver`** — `resolve(charId, cell)`: cell / `no_bases` / `ambiguous`, под сигналом — первая
  по `id` база, покрытая своей Вышкой; `resolveForBase(charId, cell, baseId)`: `on_base`/`tower`/`unavailable`.
- **`CommunicationTowerCoverageService::coverageByBase()`** — покрытие по каждой активной базе, Вышка
  засчитывается только своей базе; `checkCoverage()` — дешёвый гейт.
- **Ядро склада базы — `App\Services\Bases\BaseStorageService`** (w2-n6): `storageModel()`, `withdrawAll()`/`withdrawOne()`,
  `depositOne()`/`depositAll()`, `carriedResources()`; коды `ok|off_base|bad_resource|not_carried|missing|short|empty|failed`.
  Рендереры: `Storage\BaseStorageListAction`/`BaseStorageDepositAction`, веб `view=storage`. Склад — на персонажа, не на базу.
- `HangarAction` (`hangar`[`_b<id>`]), робот `StartRobotGatheringAction` / `CompleteRobotGatheringHandler`
  (`task_settings.base_cell`).
- `app/Services/BuildingEffects/` (`BuildingEffectLines` — строка эффекта), `app/Services/Housing/`; TaskHandlers `app/TaskHandlers/Built/`,
  `BaseLifecycleHandler.php`, `TaxCollectionHandler.php`; таблица `character_buildings`.

## Key types / contracts
Дубли зданий разрешены намеренно: бонусы не суммируются, но налог растёт ×N и база крепче.
Налог — per-base (ADR-122). Явный `baseId` (`_b<id>` бота, `b` веба) — только подсказка: ядро
перепроверяет `resolveForBase()`. Строить можно только стоя на базе (покрытие Вышки не разрешает).

## Dependencies
inbound: action-handler'ы лагеря, веб `/play`, `Worker` (стройка, налог), PvP-рейды.
outbound: ресурсы, `GameSettings`, `Services/Coverage`, `Services/Onboarding`.

## Gotchas
- Защита базы не собирается из И-НЕ флагов: база обязана укрывать всегда, когда игрок на ней.
- Смерть: −3% с базой, −50% без базы. Открытый хвост: штраф при сносе одной базы из нескольких.
- **Переезд блокирует ядро (w2-n4-tails-01).** `BuildOrderService` (`catalog`/`preview`/`start`) и
  `BuildingUpgradeService` (`preview`/`apply`) отдают код `relocating` с `ActiveTasksService::TEXT_RELOCATION`
  через `hasActiveRelocation()`; бот и веб получают один текст. Бот-экраны дополнительно зовут
  `checkRelocationAndBlock()` (`UpgradeBuildingAction::askForUpgrade()`), но опираться на него не нужно.
- **Подтверждение апгрейда несёт уровень**: бот `confirm_upgrade_building_<id>_l<N>[_b<id>]`, веб — поле `from`.
  `apply(..., $fromLevel)`: `!= current_level` или `null` → код `stale`, ничего не списано. Кнопка без `_l`
  (старые сообщения) заново показывает запрос через `prompt()`. `WHERE level = nextLevel - 1` в
  `BuildingUpgradeApplier` остаётся защитой от одновременных подтверждений.
  `stale` проверяется **до** условий следующего уровня (золото, уровень персонажа, ресурсы), как только постройка
  найдена: валидатор кладёт `currentLevel` и в поздние отказы. Каждый отказ в боте снимает «часики» (один
  `answerCallbackQuery`). Имена ресурсов для игрока — `preview()['resource_names']` (веб не печатает `Water`).
- **Старт стройки (ADR-181)**: лимит базы и `already_building` (та же задача `in_work|queued` с
  `task_settings.base_cell` этой клетки) перепроверяются под `SELECT … FOR UPDATE` строки персонажа;
  списание условное; лог отказа `BUILD_<Key>` пишет рендерер (ядро отдаёт `log`). `cellLoad()` считает
  стройки в работе по всем базам персонажа, возведённые — по клетке.
- «❌ Отмена» подтверждения апгрейда ведёт на `Base_b<id>` (та же база), без базы — голый `Base`.
- **Склад: гейт «на базе» — в ядре** (`isOnBase()` → `BaseCheckService::checkBaseStatus()['isOnBase']`, код `off_base`,
  ничего не списано); выдача/сдача — условные записи (`BaseStorageModel::withdraw`, `decrementIfAtLeast`), повтор → `missing`/`empty`.
- Открытые хвосты multibase-picker (`docs/specs/multibase-picker/plan.md`): голые «🏠 База»/«назад» ведут в
  пикер; «📦 Склад базы», «🔨 Снести», `DeleteBase`, «📡 Маяки» без суффикса базу не перепроверяют; робот
  при двух покрытых базах с Мастерскими уходит с меньшей по `id`. «🏗 Строить» суффикс несёт с w2-n4-base-01.
- **ОТКРЫТО, descoped (EA-economy-04, `docs/specs/exploit-audit/REPORT.md` #3)**: объём покупки сырья
  (`ResourceTradeService::buyResource()`) не ограничен — арбитраж между тиками `ResourceBankUpdateHandler`;
  PoC `EconomyLimitsTest::testSingleUnguardedPurchasePumpsSellPriceAboveOriginalBuyPrice` красный намеренно.
- Опт-продажа ресурсов (`bulkSellResources`) требует токен превью — см. `player.md`.

## Vault
`mmorpg-vault/apps/bases/index.md` · `tech-writing/services/{BaseScreenService,BuildOrderService,
BuildingUpgradeService,BuildingUpgradeApplier,BaseService,BaseBuildingsList,BeaconInstaller}.md` ·
`tech-writing/handlers/camp/*.md`, `handlers/buildings/TeleportBeaconScreen.md`
