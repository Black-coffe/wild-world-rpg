# ROADMAP-CRAFT-vNext.md — следующие 30 сессий (v2)

> Синтезирован в **S30** (2026-05-20) — финальная сессия ROADMAP-CRAFT v1 (S1–S29 shipped).
> Приоритезация — через **обязательный 5-осевой алгоритм** (§S30), а не свободный выбор.
> Полные 17-секционные карточки каждой сессии пишутся в начале соответствующей фазы
> (как в v1); здесь — **приоритезированный outline** (методология + ранжирование + 6×5 структура).

---

## Журнал сессий vNext

| # | Сессия | Статус | Тег | Дата |
|---|---|---|---|---|
| V1 | Весеннее пробуждение (5 heal-консумаблов) | ✅ SHIPPED | v0.51.213 | 2026-05-20 |
| V2 | Летняя жара (5 прохладительных consumable) | ✅ SHIPPED | v0.51.214 | 2026-05-20 |
| V3 | Осенняя жатва (5 урожайных consumable) | ✅ SHIPPED | v0.51.215 | 2026-05-20 |
| V5 | Seasonal images backfill (15 картинок) | ✅ SHIPPED | v0.51.216 | 2026-05-20 |
| V4 | Seasonal-events tie-in (4 события 1:1) | ✅ SHIPPED | v0.51.217 | 2026-05-20 |
| — | 🐛 hotfix: building/craft completion (character_id mixed→int, 7 хендлеров) | ✅ SHIPPED | v0.51.218 | 2026-05-20 |
| V6 | Farming seed-cycle foundation (ADR-033) — активная посадка слоем поверх теплицы | ✅ SHIPPED | v0.51.219 | 2026-05-21 |
| — | 🎨 V6 image-tail: 4 seed-картинки (loot.seeds, gpt-image-2 V4) | ✅ SHIPPED | v0.51.220 | 2026-05-21 |
| V7 | Урожай-качество + скорость по уровню теплицы (привязка V6 к S13) | ✅ SHIPPED | v0.51.221 | 2026-05-21 |
| V8 | Campfire cooking — 5 блюд из фарм-урожая (farm-to-table) | ✅ SHIPPED | v0.51.222 | 2026-05-21 |
| V9 | «Сытость» — food-buff продуктивности (крафт+добыча, PvE, ADR-034) | ✅ SHIPPED | v0.51.223 | 2026-05-21 |
| V10 | Свежесть еды + консервы (ADR-035) — 🏁 закрытие Фазы 2 | ✅ SHIPPED | v0.51.224 | 2026-05-21 |
| V11 | Quest-chain foundation (prerequisite-цепочки, ADR-036) — 🏛 старт Фазы 3 | ✅ SHIPPED | v0.51.225 | 2026-05-21 |
| V12 | Strategic quest-chains Bunker+Technopark + objective-движок (ADR-037) | ✅ SHIPPED | v0.51.226 | 2026-05-21 |
| V13 | Strategic quest-chains GhostCity+IslandFarm + craft_item-фикс (ADR-037) | ✅ SHIPPED | v0.51.227 | 2026-05-22 |

🏁 **ФАЗА 1 ЗАКРЫТА (V1-V5, 2026-05-20):** 20 рецептов × 4 сезона + 15 картинок + 4 сезонных события (Snowfall→winter / SpringFlood→spring / Dryness→summer / BerryBoom→autumn). Авто-ротация 21 день. Бонус: prod-хотфикс краша завершения построек/крафта (daily-log-review находка).

🌱 **ФАЗА 2 СТАРТОВАЛА (V6, 2026-05-21):** активное земледелие — независимый слой ПОВЕРХ пассивной теплицы (0 регрессии). Семена (craft + light drop) → посадка → рост по таймеру (реюз `character_tasks`) → урожай + бонус севооборота. 4 seed-ресурса, `output_type=resource` в generic-craft, `PlantCropCompletionHandler` (handler_key=planting), 13 `farming.*` GameSettings (live-tunable), killswitch `farming.enabled`. +14 тестов (724), phpstan L9, testbot e2e ✅. **Image-tail закрыт (v0.51.220):** 4 seed-картинки (loot.seeds, gpt-image-2 V4, 0-text). Фундамент для V7 (harvest-quality), V8 (cooking), V9 (food-buffs), V10 (хранение).

**Следующее: Фаза 2 — V7-V10** (harvest scheduling → campfire cooking → food-buffs → хранение). V6 фундамент seed/plant/grow переиспользуется.

**Image-tail'ы прочие** (WatchTower S26b + 4 strategic-объекта) — отдельный scope (Фаза 6 V28).

🏛 **ФАЗА 3 (V11-V13): endgame quest-chains.** V11 — prerequisite-фундамент (ADR-036). V12 — objective-движок (discover/craft/explore/level) + цепочки Bunker+Technopark (ADR-037). **V13 (2026-05-22)** — цепочки GhostCity (Партизаны) + IslandFarm (Фермеры): Capture(discover)→mid(char_level)→final(craft signature weapon). Теперь все 4 фракции имеют полную цепочку фракция→объект→quest→оружие. **Audit-first вскрыл латентный баг V12** (исправлен в V13): `craft_item`-objective не видел faction-weapons (output_type=weapon в `characters_weapons` мимо `crafted_items_log`) → финалы были недостижимы (BUILT-BUT-DEAD); фикс — `craftedCount` суммирует все output-пути. +5 тестов (762), phpstan L9, testbot e2e 11/11 + 4 реальных Telegram-уведомления.

---

## §0. Методология приоритезации (5 осей)

Каждая кандидатная тема оценена по 5 осям; итог = сумма. Top-кандидаты группируются
в фазы по зависимости (foundation-темы — раньше).

1. **Lore-debt** (+1..+5) — закрывает ли канон/code mismatch.
2. **Архитектурная зависимость** (+1..+5) — unlock'ает ли несколько следующих тем.
3. **Stack-fit** (−3..+3) — реюз CI4/PSR/Longman vs новая внешняя зависимость.
4. **Setting-coherence** (−5..+5) — укладывается ли в постапок «Найденная фотоплёнка».
5. **Player impact** (+1..+5) — сколько из 10 портретов выигрывает; контрактные (П2/П3/П9) не страдают.

### Входные данные: открытые tail'ы из v1 (S1–S29)

- **Seasonal framework живой, но 3 сезона пусты** (S28): spring/summer/autumn = 0 рецептов. Framework готов → дешёвый высокоценный контент.
- **Strategic-объекты — фундамент без глубины** (S21–S24): discovery + +500 есть, но полноценных quest-цепочек внутри Bunker/Technopark/GhostCity/Island-Farm нет.
- **GAME_DESCRIPTION canon drift** (5 mismatches, отложены как отдельный scope в S29).
- **Robotics T2/T3** отложены в S14 (нишево, ~6 крафтеров — но фундамент есть).
- **Greenhouse/farming мелкий** (S13b foundation): per-level production есть, но без seed-cycle/rotation.
- **Image-tails**: WatchTower (S26b) + 4 strategic-объекта (text-fallback работает).

---

## §1. Ранжирование кандидатов (5-осевой прогон)

| # | Тема | Lore | Arch | Stack | Setting | Player | Σ | Фаза |
|---|---|---:|---:|---:|---:|---:|---:|---|
| 1 | **Farming/agriculture deepening** (seed-cycle, rotation, harvest scheduling) | +2 | +3 | +2 | +5 | +4 | **16** | 2 |
| 2 | **Strategic-objects quest-chains** (полные цепочки в 4 объектах) | +4 | +2 | +2 | +4 | +4 | **16** | 3 |
| 3 | **GAME_DESCRIPTION canon reconciliation** (5 lore tails) | +5 | +1 | +3 | +5 | +2 | **16** | 6 |
| 4 | **Seasonal content completion** (spring/summer/autumn × 5 = 15 рецептов) | +2 | +1 | +3 | +5 | +4 | **15** | 1 |
| 5 | **Cooking/food crafts** (campfire, food-buffs, сохранение продуктов) | +1 | +3 | +2 | +5 | +4 | **15** | 2 |
| 6 | **Lore quest-chain T3→T4** (мульти-этапные квесты) | +3 | +3 | +1 | +4 | +4 | **15** | 3 |
| 7 | **Tier 4 Unique crafts** (faction-specific, требует endgame-объект) | +2 | +2 | +2 | +4 | +3 | **13** | 3 |
| 8 | **Workshop specialization / branching** (оружейник/медик/инженер) | +1 | +4 | +1 | +3 | +4 | **13** | 4 |
| 9 | **Robotics depth** (T2/T3 роботы, specialized researcher/medic/scout) | +2 | +2 | +2 | +3 | +3 | **12** | 4 |
| 10 | **Map-driven gather rebalance** (rare biome-spots от S10, по фидбэку) | +2 | +1 | +2 | +3 | +3 | **11** | 5 |
| 11 | **NPC services: repair shops / insurance** (реюз S5 repair) | +1 | +1 | +2 | +3 | +3 | **10** | 5 |
| 12 | **Faction-cooperative crafts** (contribution 2+ игроков фракции) | +1 | +3 | −1 | +3 | +3 | **9** | 4 |
| 13 | **Trade routes / NPC caravans** (нужна settlement-система) | +1 | +2 | −2 | +3 | +3 | **7** | 5 |
| 14 | **Crafting economy dashboard** (admin: цены/оборот/инфляция; реюз S30 CSV) | 0 | +2 | +3 | 0 | +1 | **6** | 5 |

**Отсечка:** все 14 проходят в 30 сессий (с разбивкой каждой темы на 2–3 сессии).
**Исключено (отрицательный/спорный setting):** 5-я фракция (🔴 ломает 1:1 mapping),
magic-крафт (🔴), crafted-as-currency market (🟠 inflation), permadeath default (🟠).

---

## §2. Структура vNext: 6 фаз × 5 сессий = 30

### 🔄 Фаза 1 — Seasonal content completion (дешёвый высокоценный старт)
Framework S28 готов → нужен только контент. Каждый сезон = 5 рецептов + 5 images + heal/effect GameSettings.
- **V1** ✅ **SHIPPED v0.51.213 (2026-05-20)** — Весеннее пробуждение: 5 heal-консумаблов (🌿 Чай из первой травы / 🍯 Берёзовый сок / 🌸 Настой первоцвета / 🥗 Весенняя зелень / 🌱 Отвар молодых побегов), `required_season='spring'`, зеркало S28 winter. **Scope-решение:** семена/удочка из исходного outline отложены (требуют механики посадки V6 / tool-механики → BUILT-BUT-DEAD риск). Активен ~10 июня 2026. Картинки → V5. 704→705 тестов.
- **V2** ✅ **SHIPPED v0.51.214 (2026-05-20)** — Летняя жара: 5 прохладительных heal-консумаблов (🧊 Холодный квас / 🥤 Лесной морс / 🍉 Фруктовая вода / 🌿 Мятный отвар / 🧴 Алоэ-бальзам), `required_season='summer'`, зеркало V1. **Scope:** «солнечная экипировка/лёгкая броня» из outline отложены (требуют armor-механики → BUILT-BUT-DEAD риск). Ресурсы из реальной таблицы (Вода/Ягоды/Фрукты/Алоэ/Зерновые). Активен ~1 июля 2026. Картинки → V5.
- **V3** ✅ **SHIPPED v0.51.215 (2026-05-20)** — Осенняя жатва: 5 урожайных heal-консумаблов (🍯 Ягодное варенье / 🍄 Грибное рагу / 🌰 Ореховая смесь / 🍎 Сидр / 🥫 Овощные консервы), `required_season='autumn'`. **Scope:** «бочки» из outline отложены (storage-механика). Все 4 сезона теперь укомплектованы (20 рецептов). Активен ~22 июля 2026. Картинки → V5.
- **V4** ✅ **SHIPPED v0.51.217 (2026-05-20)** — Seasonal-events tie-in: 4 существующих события привязаны к сезону 1:1 (Snowfall→winter / SpringFlood→spring / Dryness→summer / BerryBoom→autumn) через `required_season` + `EventActivationHandler::filterBySeason()` (killswitch-safe). Аудит: события уже в DB+config, идеально тематичны. 0 миграций. +5 unit-тестов.
- **V5** ✅ **SHIPPED v0.51.216 (2026-05-20)** — Seasonal images backfill: 15 картинок (spring+summer+autumn × 5) в стиле «Найденная фотоплёнка» (gpt-image-2, V4, lexicon loot.medicine), все визуально проверены на 0-text, ≤292KB, HTTP 200 prod. Закрытие фазы — после V4.

### 🌾 Фаза 2 — Farming & cooking foundation (P5/P10, lore-coherent)
Greenhouse (S13b) → углубление + новая ось «еда».
- **V6** ✅ **SHIPPED v0.51.219 (2026-05-21)** — Seed-cycle / crop rotation foundation (ADR-033). Активная посадка СЛОЕМ поверх пассивной теплицы (`GreenhouseProductionHandler` не тронут, 0 регрессии). Цикл: 4 семени (`BerrySeeds`/`MushroomSeeds`/`FruitSeeds`/`CropSeeds` — craft `output_type=resource` + light gather-drop) → посадка в теплице (реюз `character_tasks`, handler_key=planting) → рост по таймеру → урожай (существующие ресурсы) + бонус севооборота (`characters.last_planted_crop`, детерминир.). `FarmingService` (live-tunable reader), `PlantCropCompletionHandler`, `SeedSelectAction`/`SeedPlantPreviewAction`/`PlantCropActionStart`, кнопка «🌱 Грядки» в карточке теплицы. 13 `farming.*` GameSettings (category=resources, rich rationale ADR-024), killswitch `farming.enabled`. Fairness: слои не делят water-бюджет, slot-кап (2), семена из добытого (не P2W), rotation без RNG. +14 тестов (724), phpstan L9, testbot e2e ✅ (craft→deposit→plant→harvest+rotation→passive жив). **Smoke-находка:** `last_planted_crop` забыт в CharacterModel allowedFields → fix v0.51.219. **Image-tail закрыт (v0.51.220):** 4 seed-картинки (LEXICON `loot.seeds`, gpt-image-2 V4, все 0-text, ≤293KB, прод HTTP 200).
- **V7** ✅ **SHIPPED v0.51.221 (2026-05-21)** — Урожай-качество + скорость по уровню теплицы (привязка V6 к S13). Pure-overlay (S11-S15 паттерн, default ×1.0, 0 регрессии, L4-L10 plateau cascade): `BuildingEffectsService::getGreenhouseYieldMultiplier` (КАЧЕСТВО: L2 +15%, L3 +30%) + `getGreenhouseGrowTimeMultiplier` (СКОРОСТЬ: L2 −10%, L3 −20%). 4 GameSettings `building.greenhouse.l2/l3.{harvest_yield,grow_time}_multiplier` (category=buildings, rich rationale). `FarmingService::growMinutes/harvestYield` приняли `extraMult` (backward-compat). Wiring: grow-time при посадке (замораживается), yield при сборе (по уровню теплицы на момент сбора); UI показывает скорректированные цифры. **User pick:** «Качество + Скорость» (2 множителя). **Аудит:** прод 5 теплиц (3×L1, 2×L2) — фича даёт повод качать теплицу. +11 тестов (724→735), phpstan L9, testbot smoke + **прод read-only e2e** (реальный сервис: L1→×1.0, L2→×1.15/×0.90 на живых владельцах). Всё live-tunable.
- **V8** ✅ **SHIPPED v0.51.222 (2026-05-21)** — Campfire cooking: 5 блюд из фарм-урожая (farm-to-table payoff для V6/V7). Аудит: «Костёр» = ярлык craft-tree (`crafting_location`, НЕ здание/гейт); сезонные блюда V1-V3 уже type='drug' → консистентно. Reuse-max (user pick): блюда type='drug', heal через `UsePharmacyAction` (data-driven S19), ингредиенты = урожай V6 (Ягоды/Грибы/Фрукты/Зерновые) + Вода, готовить где угодно (без гейта). 2 миграции (5 crafted_items drug crafting_location='Campfire' + 5 tasks generic_craft + 10 `medical.<meal>.heal_*` GameSettings category=combat) + 5 рецептов + `CampfireCookingSelect` (callback `cook`) + кнопка «🔥 Костёр» в Общем крафте + Worker maps. Еда восстанавливает больше ВЫНОСЛИВОСТИ (ниша против медицины): 🍄 Грибная похлёбка 25/35 · 🫐 Ягодный взвар 20/40 · 🍎 Печёные фрукты 30/30 · 🥣 Зерновая каша 35/35 · 🍲 Сытное рагу 45/55. 5 картинок (loot.cooked-food, gpt-image-2 V4, 0-text, berry_brew перегенерён). +2 теста (735→737), phpstan L9, testbot e2e ✅, прод verify (5/5 items+tasks, heal settings, 5 img HTTP 200). Всё live-tunable.
- **V9** ✅ **SHIPPED v0.51.223 (2026-05-21, ADR-034)** — «Сытость»: food-buff продуктивности от еды (V8). **User pick: Продуктивность (PvE)** — поел блюдо → сыт N минут → крафт быстрее (×0.90) + добыча щедрее (×1.15). **Аудит:** per-character buff-инфры не было (net-new → ADR-034); боевые статы в 6 PVE-файлах (RNG-fence/PvP-fairness) → намеренно НЕ трогали; реген <L20 → не годится. Выбран контейнерный PvE-слой (multiplier-паттерн S11-S15). `characters.well_fed_until` (lazy expiry) + `FoodBuffService` + 8 `food.*` GameSettings (category=craft) + хуки: eat (UsePharmacyAction ставит well_fed), craft (GenericCraftActionStart × mult), gather (GatherTaskHandler × mult). Pure-bonus (null=прежнее поведение), killswitch `food.buffs.enabled`. Длительность по блюдам (похлёбка 30 / взвар-фрукты 25 / каша 40 / рагу 60 мин). +6 тестов (737→743), phpstan L9, testbot e2e + прод verify. Всё live-tunable.
- **V10** ✅ **SHIPPED v0.51.224 (2026-05-21, ADR-035) — 🏁 ЗАКРЫТИЕ ФАЗЫ 2.** Свежесть еды + консервы. **User pick: Свежесть (pure-bonus, non-hostile).** «Порча» = свежесть влияет ТОЛЬКО на длительность buff'а «Сытость» (V9): свежее блюдо (2 дня) → полная сытость, залежалое → ×50% — но еда НИКОГДА не теряется и всегда лечит (не player-hostile, контракт П2/П10 цел). «Консервация» = 2 shelf-stable заготовки (🥫 Тушёнка, 🎒 Сухпаёк) — не портятся, дольше держат сытость, дороже. Аудит: реюз неиспользуемого `durability_time` (DATE) как fresh_until; деструктивная порча отклонена (player-hostile). 2 миграции (2 preserve items+tasks + 9 GameSettings: heal/satiety/freshness) + `perishable`-флаг на 5 блюдах V8 + FoodBuffService freshness-методы + cook-хук (durability_time) + eat-хук (× свежесть) + 2 картинки (loot.cooked-food V4, обе перегенерены). +5 тестов (743→748), phpstan L9, testbot e2e + прод verify. killswitch `food.freshness.enabled`.

🏁 **ФАЗА 2 ЗАКРЫТА (V6-V10, 2026-05-21):** полная ось «еда» — активное земледелие (семена→посадка→урожай+севооборот) → масштаб по уровню теплицы → готовка на костре (7 блюд+консервы) → buff «Сытость» (продуктивность) → свежесть/консервация. 2 ADR (033/034/035), ~25 GameSettings, ~20 картинок, 0 player-hostility, всё live-tunable. Прод-теги v0.51.219→224.

### 🏛 Фаза 3 — Endgame content deepening (P4/P6 — раскрыть фундамент S21–S25)
- **V11** ✅ **SHIPPED v0.51.225 (2026-05-21, ADR-036) — 🏛 СТАРТ ФАЗЫ 3.** Quest-chain foundation. **User pick: prerequisite-цепочки** (contained, не трогаем хрупкие bespoke quest-handler'ы). **Аудит:** квесты single-step (bespoke handler+action+хардкод-награда); цепочек/prerequisites не было; `quest_requirements` — dead; `step_order` abused как флаг. Решение: `quests.prerequisite_quest` (nullable VARCHAR = title_en предусловия; null = доступен сразу, 0 регрессии) + `QuestChainService` (chainsEnabled + prerequisiteMet, pure) + `QuestModel::getCompletedQuestTitles` + availability-gate в `AvailableQuests` (locked-тизер «🔒 после X») + admin quest-form поле + killswitch `quests.chains_enabled`. Многоэтапность = цепочка связанных квестов (A→B→C); T4-крафт гейтится на финальный квест через S25 `required_quest`. +4 теста (748→752), phpstan L9, testbot e2e (B locked→unlocked) + прод verify (column + setting + 0 gated quests). Foundation для V12/V13 (strategic-цепочки) + T3→T4.
- **V12** ✅ **SHIPPED v0.51.226 (2026-05-21, ADR-037).** Strategic quest-chains Bunker+Technopark + objective-движок. **User pick: Fix + objective-движок + цепочки.** **Аудит вскрыл крупный баг:** strategic-квесты нерабочие (dead start-кнопки → не стартуют → не завершаются → T4 faction-weapons на required_quest недостижимы). **(1) Fix:** `StrategicLootHandler` авто-создаёт+завершает capture-квест на discovery + advanceChain → разблокировал 4 T4-оружия. **(2) Objective-движок:** `quests.objective_type/target/qty` + генерик `QuestObjectiveHandler` (everyMinute: craft_item/explore_cells/char_level → завершение+награда+уведомление+advanceChain); discover_object — в StrategicLootHandler; старые bespoke не тронуты. `QuestChainService::advanceChain` — авто-назначение след. этапа (без manual-start). **(3) Цепочки:** Bunker (Военные): Capture(discover)→Armory(lvl18)→Dominance(craft BunkerRifle); Technopark (Инжен.): Capture(discover)→Research(lvl21)→Breakthrough(craft TechnoBeamShotgun). GhostCity/IslandFarm: только fix (цепочки в V13). AvailableQuests: objective-квесты исключены из manual start (kill dead-кнопки). phpstan: исправлен legacy offset-access (baseline −15, не вырос). +3 теста (752→755), L9. testbot e2e (advanceChain + objective-complete + auto-progress) + прод verify (4/4 capture + 4/4 chain).
- **V13** — Strategic quest-chains: GhostCity + Island-Farm.
- **V14** — Tier 4 Unique crafts: 4 faction-specific (gate = endgame-объект + квест-цепочка, реюз S25 `required_quest`+`required_faction`).
- **V15** — T4 images + endgame-блок polish + закрытие фазы.

### ⚙️ Фаза 4 — Player progression branching (P5/P8)
- **V16** — Workshop specialization foundation (выбор ветки: оружейник/медик/инженер; GameSettings + char-поле).
- **V17** — Specialization perks (per-ветка бонусы крафта, реюз BuildingEffectsService S11-S15 pattern).
- **V18** — Robotics T2 (specialized researcher/medic/scout — отложено в S14).
- **V19** — Robotics T3 + robot-апгрейды.
- **V20** — Faction-cooperative crafts (contribution 2+ игроков) + закрытие фазы.

### 💹 Фаза 5 — Economy & services (P7/P8)
- **V21** — Crafting economy dashboard (admin; реюз S30 CSV + craft-tree; графики цен/оборота/инфляции).
- **V22** — Map-driven gather rebalance (rare biome-spots от S10, по прод-фидбэку + dashboard-данным).
- **V23** — NPC repair shops (реюз S5 repair + GameSettings cost).
- **V24** — Crafted-item insurance (NPC-сервис; P2 оффлайн-защита).
- **V25** — Trade routes / NPC caravans (MVP; если settlement-системы нет — упрощённый inter-base обмен) + закрытие фазы.

### 📚 Фаза 6 — Canon, polish & vNext-of-vNext
- **V26** — GAME_DESCRIPTION canon reconciliation (5 lore tails — отдельный scope из S29).
- **V27** — Content-pass v2 (lying descriptions sweep по новому контенту V1–V25).
- **V28** — Image-tails backfill (WatchTower S26b + 4 strategic-объекта + любые pending).
- **V29** — Tech-writing/ADR sweep + `last_reviewed` актуализация + admin-tools polish.
- **V30** — Retrospective v2 + ROADMAP-CRAFT-vNext2.md (тот же 5-осевой алгоритм поверх V1–V29). 🏁

---

## §3. Контракт (как v1)

- Каждая сессия: **audit-first** (SSH-прод + grep + Glob ДО follow-through — 23/25 сессий v1 имели drift).
- 🟠-сессии → ADR + validation-card (7 ворот) ДО кода.
- Balance-параметры → **GameSettings** (constitutional ADR-024), не магические числа.
- Tech-writing нота синхронно (ADR-009). Картинки — стиль «Найденная фотоплёнка» (ADR-022).
- Smoke-tiers: composer test + phpstan L9 → testbot e2e → prod + verify.
- **Анонсы игрокам — батчем в конце** (после V30), не по ходу (`feedback_announce_after_roadmap_done`).
- Throwaway smoke-команды — **scp → run → delete**, не через git/CI (L9-gate; урок S26b).

## §4. Источники

- v1 итоги: `ROADMAP-CRAFT.md` §0 (S1–S29), retrospective `mmorpg-vault/inbox/2026-05-20-roadmap-v1-retrospective.md`.
- Алгоритм: `ROADMAP-CRAFT.md` §S30. Валидация: `GAME_RULES_AND_VALIDATION_FRAMEWORK.md` (7 ворот, 10 портретов).
