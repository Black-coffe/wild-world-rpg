<!-- Срез-указатель, а не копия территории. Подробность — в mmorpg-vault; здесь только то,
     что нужно, чтобы понять, куда идти, и не вляпаться. Посеян обследованием дерева репозитория
     и конституцией проекта 2026-08-19; углубляется /vulyk-map <path> через drone-scout. -->
last-verified: 2026-09-15

# Scout report: Базы, лагерь, постройки

## Purpose
Строительство и жизненный цикл баз: лагерь, здания, апгрейды, лимиты, налог, мульти-база,
защита от рейда, выбор базы кнопками (spec `multibase-picker`, shipped v0.51.669).

## Entry points
- **«🏠 База» (живой путь):** `Base`[`_b<id>`] → `Camp/Buildings/ShowBaseInfoAction` →
  `App\Services\BaseService::showBaseInfo()` → `App\Services\Bases\BaseServiceMessageFormatter`
  (`basePicker()` при 2+ базах). `DetailedBaseInfoAction` — НЕ этот экран: висит на `construction`
  (кнопки построек `building_<id>_<name>[_b<id>]`). С суффиксом — база из `resolveForBase()`;
  голый `construction` под вышкой (с multibase-picker-11) — база из `BaseScopeResolver::resolve()`,
  шапка из строки `coverageByBase()` той же базы, суффикс кнопок — её id; без покрытия —
  легаси `handleNotOnBasePhysically()` с `findAllActiveCells()[0]`.
- `app/Services/Bases/` — `BaseLifecycleService`, `BaseLimitService`, `BaseCheckService`,
  `CampCheckService`, `BaseLocationResolver`, `BaseBuildingsList`, `BaseServiceMessageFormatter`,
  `BaseCallbackSuffix`, `BaseScopeResolver` (angela-second-base-bugs-07, расширен
  multibase-picker-01/09) — единая точка «с какой базой работает этот экран», зовётся всеми
  14 карточками построек (`Camp/Buildings/{...}Handler.php`) и `Camp/Buildings/
  UpgradeBuildingAction` (`CallbackPrefixDispatcher`, префикс `upgrade_building_`).
- **`BaseCallbackSuffix`** — кодек `<callback>_b<claimed_cells.id>`: `append()` (`\LengthException`
  >64 байт), `split(): [данные, baseId|null]` по `/_b(\d+)$/`. Роутер суффикс не снимает —
  каждый обработчик сам зовёт `split()`.
- **`BaseScopeResolver::resolve(characterId, currentCell)`** — cell / `no_bases` / `ambiguous`;
  ветка «под сигналом» с multibase-picker-09 берёт первую по `id` базу, ПОКРЫТУЮ её собственной
  Вышкой (раньше — первую активную вообще). `resolveForBase(characterId, currentCell, baseId)`
  (multibase-picker-01) — явный id из суффикса: `on_base`/`tower`/`unavailable`.
- **`App\Services\Coverage\CommunicationTowerCoverageService::coverageByBase(characterId,
  playerCell)`** — покрытие по КАЖДОЙ активной базе (ASC `id`); Вышка засчитывается только своей
  базе (`character_buildings.map_cell_id` = клетке этой базы). `maxCoverage = towerLevel ×
  GameSettings('communication_tower.coverage_per_level')`; `checkCoverage()` — дешёвый гейт.
- **`HangarAction`** (`Camp\HangarAction`, `hangar`[`_b<id>`]) — с суффиксом показывает
  Мастерскую именно той базы; без суффикса и `resolve()==null` (multibase-picker-10) —
  `renderNoBaseHub()`: хаб ADR-120 с честным lock, ни одна база не называется местной.
- **Робот:** `Camp\Buildings\Robots\StartRobotGatheringAction::launchBase()`/`workshopAtBase()`/
  `baseLabel()` — база запуска: игрок на ней, иначе первая база, покрытая её собственной Вышкой
  и со своей Мастерской. Клетка пишется в `character_tasks.task_settings.base_cell` (JSON).
  `CompleteRobotGatheringHandler` (`app/TaskHandlers/CompleteRobotGatheringHandler.php`, task
  `GatheringResourcesRobot`) читает `base_cell`; легаси/неактивная база → активная с наименьшим
  `id` (`orderBy('id','ASC')->first()`).
- `app/Services/Buildings/`, `app/Services/BuildingEffects/`, `app/Services/Housing/`.
- `app/Services/Player/BuildingUpgrade/`.
- TaskHandlers — `app/TaskHandlers/Built/`, `BaseLifecycleHandler.php`, `TaxCollectionHandler.php`.
- Таблица `character_buildings`.

## Key types / contracts
Дубли зданий разрешены намеренно: бонусы не суммируются, но налог растёт ×N и база крепче.
Налог — per-base (ADR-122).

## Dependencies
inbound: action-handler'ы лагеря, `Worker` (стройка, налог), PvP-рейды.
outbound: ресурсы, `GameSettings`, `Services/Coverage`.

## Gotchas
- Защита базы не должна собираться из И-НЕ флагов: комбинация делается недостижимой, и база
  перестаёт укрывать. База обязана укрывать всегда, когда игрок на ней.
- Смерть: −3% с базой, −50% без базы.
- Открытый хвост: штраф при сносе одной базы из нескольких.
- Ловушка (exploit-audit, `docs/specs/exploit-audit/REPORT.md` #3, `EA-economy-04`), **ОТКРЫТА,
  сознательно descoped из exploit-fix (решение владельца 2026-09-02)**:
  `ResourcesBankModel::updatePurchasedQuantity()` / `ResourceTradeService::buyResource()` не
  ограничивают объём покупки сырья — арбитраж «купи → дождись тика крона → продай дороже»,
  цена меняется только между тиками `ResourceBankUpdateHandler`. Это вопрос цены/лимита (свой ADR,
  ключи `economy.resource.max_purchase_per_trade`/`.repricing_mode`), не гонки — отдельная спека.
  PoC `EconomyLimitsTest::testSingleUnguardedPurchasePumpsSellPriceAboveOriginalBuyPrice` остаётся
  красным намеренно.
- **ЗАКРЫТО (exploit-fix-07, F5, ADR-181).** `BeaconInstaller::install()` ставил маяк до
  подтверждённого списания предмета — при неудаче выдавался бесплатно. Порядок перевёрнут:
  условное списание первым, вставка маяка только на `Applied`. См.
  `mmorpg-vault/tech-writing/services/BeaconInstaller.md`.
- **(ADR-181) Заряд карго-дрона и списание ремонта — условная запись, не снимок.**
  `CargoDroneSendAction`/`CargoDroneAutoSendAction` списывали абсолютным значением заряда
  до транзакции (гонка); `RepairBuildingAction` при отказе коммитил уже списанные строки плана.
  Оба — через `decrementIfAtLeast()`/условный откат внутри транзакции.
- **(angela-second-base-bugs-07) Building-карточки мульти-база-aware.** Раньше 14 карточек,
  `BaseBuildingUpgradeAction` и `BuildingUpgradeValidator` звали `resolveTargetBaseCell()`
  напрямую и на `null` отвечали ОДНИМ текстом на «баз нет» и «баз несколько». `BaseBuildingUpgradeAction`
  (abstract) на практике недостижим — живой путь апгрейда `CallbackPrefixDispatcher` →
  `UpgradeBuildingAction` → `BuildingUpgradeValidator`.
- **(multibase-picker, shipped v0.51.669) Выбор базы живёт в `callback_data`, не в хранилище.**
  До этого «🏠 База» вне базы всегда открывала первую активную базу (`first()` без `orderBy`),
  даже в шаге от второй под её собственной Вышкой. Открытые хвосты (не чинятся этой спекой, см.
  `docs/specs/multibase-picker/plan.md` `## Открытые хвосты`): голые кнопки «🏠 База»/«назад» без
  суффикса ведут в пикер, а не на просматриваемую базу; кнопки экрана базы без суффикса
  («🏗 Строить», «📦 Склад базы», «🔨 Снести», `DeleteBase`, «📡 Маяки») не проверяют выбранную
  базу повторно; при двух покрытых базах со своими Мастерскими робот уходит с меньшей по `id`.
  Правило маяков «Центр телепортации на любой базе» записано намеренным в ADR-187.
- **Экран «📡 Маяки» — две двери, обе в `BaseServiceMessageFormatter`**: `baseBuildings()`
  (happy-path) и `notOnBasePhysically()` (заглушка). Маяк ставится в клетке игрока, вне базы.
  Отказ «нет Центра телепортации» в `TeleportBeacon::handle()` — под текстом «🏗 Строить»/«🏠 База».
  См. `mmorpg-vault/tech-writing/handlers/buildings/TeleportBeaconScreen.md`.

## Vault
`mmorpg-vault/apps/bases/index.md`
