# Дайджест разведки (4 drone-scout, 2026-08-19) — под систему транспорта

## A. Перемещение

- Два пути: **одиночный шаг** (`MoveCharacterToDirectionAction.php`, синхронно, без задачи)
  и **Поход** (`MarchAction.php` → цепочка 1-клеточных задач `Marching` →
  `MarchingTaskHandler.php`).
- Темп похода: `etaMinutes = ceil(cells / cells_per_tick) × minutes_per_cell`
  (`MarchingTaskHandler::etaMinutes():738`). `cellsPerTick()` — `:751`, читает глобальный
  `world.march.cells_per_tick=3`. **Персонального контекста нет.**
- Ключи `world.march.*` (все в GameSettings, category `world`): `minutes_per_cell=1`,
  `cells_per_tick=3`, `max_steps_per_order=60`, `tired_cost_per_cell=0.5`,
  `health_cost_per_cell=0.02`, `danger_health_surcharge=1.0`, `xp_per_cell=0.03`,
  `stat_per_cell=0.02`. Мини-события: `world.march_events.enabled=1`, `min_level=25`,
  `chance_per_cell=0.05`.
- Темп похода намеренно НЕ зависит от ❤️/💤 («топливо, не двигатель») — заявлено в UI.
- Одиночный шаг: `baseHealthCost=0.1`, `baseTiredCost=3.35`, danger-биом `+1.15` —
  **hardcoded в классе** (`:64-65`), мимо GameSettings. `EarlyProgressionService::moveCostFactor()`
  (×0.8 новичкам) — единственная персональная модификация в игре.
- **Разницы explored/unexplored в механике нет** (гейт снят ADR-019). Только UI-предпросмотр
  биома впереди (`MarchAction::biomeAhead():330`).
- Остановки похода: край мира, вода (биом «Реки», id 4), чужой `claimed_cells`, пол ❤️/💤.
  Паузы: PvP-детект, NPC, мини-событие.
- `stepDueInterval() = minutes_per_cell − 1` — намеренная компенсация дрожания
  (`:623-641`). Ломать нельзя.
- Вес: `WeightCapacityService`, killswitch `inventory.weight_cap.enabled` **выключен**;
  проверяется только в `GatherTaskHandler` и при запуске карго-дрона. На перемещение
  не влияет.
- Телепорт-контракт (образец «предмет для перемещения»):
  `TeleportUseValidator` → `TeleportExecutor::teleport()` → `TeleportItemConsumer::consume*()`.
- Модификаторов скорости нет нигде: `BuildingEffectsService` покрывает крафт/телепорт/теплицу,
  но не движение.

## B. Крафт-пайплайн

Два пути: **generic** (`Config\CraftRecipes` + `GenericCraftActionStart` +
`GenericCraftCompletionHandler`) и **custom-класс** (образец — `PortableTeleportRecipe`,
ADR-166: свой Recipe-класс + превью-Action + старт-Action + свой TaskHandler).

Обязательные точки касания нового рецепта:
1. Рецепт (config-запись или Recipe-класс), числа — в GameSettings с rationale.
2. Action превью + Action старта (или `genericCraft_<Key>_<qty>`).
3. TaskHandler завершения с `#[HandlerKey]`.
4. `Config\CallbackRoutes.php` — все новые callback.
5. **`Controllers/Worker.php` → `$taskHandlerKeyMap['craft<X>'] = '<handler_key>'`** —
   без строки задача зависает молча.
6. Миграция: строка в `tasks` (**обязательно `type='craft'`**, иначе слот не считается),
   шаблон в `crafted_items`, seed GameSettings.
7. **Кнопка в существующем меню** — иначе падает `CraftRecipeReachabilityTest`
   (source-scan: ключ обязан встречаться литералом `genericCraft_<Key>_` вне своего экрана
   либо иметь зарегистрированный `info_callback`).
8. `Config\ImageRegistry` + `php spark images:generate`.

Поля гейтов рецепта: `required_level`, `required_faction` (int 1..4), `required_quest`,
`required_buildings`, `required_building_levels`, `gold_required`, `required_crafted_items`.
Гейт проверяется дважды: на превью (🔒 с объяснением) и в `GenericCraftActionStart:271-278`.

Грабли: `deductCraftedItem()` вместо raw-set; `CharacterModel::find()` даёт Entity, не массив;
`new Model()` в цикле (builder-state quirk); `is_file()` до `encodeFile()`;
`gold_required > crafted_items.price` (анти-эксплойт ADR-157); `agility_bonus`/`intellect_bonus`
обязательны при `output_type=crafted_item`.

## C. Фракции

- 4 фракции + «Нейтралы» (id 5 = не выбрал). id: 1 Милитари, 2 Партизаны, 3 Инженеры,
  4 Фермеры. Выбор на 10 уровне, **необратим**.
- Прод: Инженеры 21, Нейтралы 21, Партизаны 7, Милитари 4, Фермеры 1. Средний уровень
  членов фракций 46–112.
- Читать фракцию — `CharacterFactionModel::getFactionId()` (raw builder, обходит quirk).
- Эталон гейта: `FactionWeaponsCraftSelect` / `WeaponRecipePreviewT3Action:173-203` —
  витрина видна всем, 🔒 внутри превью, enforcement при старте.
- **Индивидуальной репутации/уровня внутри фракции НЕТ.** Есть коллективный
  `faction_projects` (пул золота → −10% времени крафта на 24ч) и квесты захвата
  стратегических объектов (`StrategicCapture*`) — единственная индивидуальная ось.
- Ривалри: Милитари↔Партизаны, Инженеры↔Фермеры (hardcoded в `CaravanService`).
- Лор: Милитари — военная техника, захват, тяжёлая логистика. Партизаны — скрытность,
  засады, мобильность, слабое тяжёлое вооружение. Инженеры — робототехника, электроника,
  редкие ресурсы и энергия. Фермеры — еда, снабжение, экономика, слабы в бою.
- Эндгейм 1:1: Бункер=Милитари, Технопарк=Инженеры, Город-призрак=Партизаны,
  Старая ферма=Фермеры.

## D. Владение предметом и «активный предмет»

- Крафт-владение — `crafted_items_log` (`character_id` + `crafted_item_id` + `quantity`,
  `durability_count` = заряды одной единицы, `durability_time` = срок годности только для
  `drug`, `insured` = полис).
- **Два разных паттерна «активного» в коде:**
  1. **Экипировка** (`characters_weapons.equipped`, `ToggleEquipWeaponAction`): постоянный
     флаг, инвариант «максимум один» держится КОДОМ (сначала всех в 0), «надеть» требует
     Арсенал + нахождение на базе, «снять» — всегда и откуда угодно.
  2. **Дрон** (`crafted_items_log.id` в callback + окно `combat_drone_active_until`):
     разовое действие конкретным инстансом, постоянного «выбранного» нет.
- 🔴 **Смерть забирает имущество ТОЛЬКО из `crafted_items_log`.** `characters_weapons`
  и броня `LootProcessor`'ом не трогаются вообще. Значит выбор «где живёт транспорт»
  напрямую решает, теряется ли он при смерти.
- ADR-172: `floor(quantity × penalty)` на строках qty=1 давал ноль; дробный остаток теперь
  разыгрывается броском под `combat.death.craft_fractional_loss`.
- `durability_count` исторически замусорен константой 100 — читать только через
  `effectiveCharges()`.
- `getItemByCraftedItemIdAndCharacterId()` — `first()` без `orderBy`: при дублях строк
  вернёт произвольную.
- `WipeManifest` — новая таблица обязана получить запись, иначе падает
  `WipeManifestCoverageTest`.
- В `CraftedResourcesAction:123` уже есть заголовок «🚚 *Транспорт* 🚚».

## E. Прод-цифры (снято напрямую, 2026-08-19)

- Уровни: 1–4 → 616 игроков, 5–9 → 19, 10–14 → 10, 15–19 → 8, 20–29 → 14, 30+ → 22.
- Активны за 7 дней: 687.
- Походов за 14 дней: **953** (добыча — 267). Поход — самое частое действие.
- Владение транспортом: 21 строка на 10 позиций, все от бага PvE-крана.
