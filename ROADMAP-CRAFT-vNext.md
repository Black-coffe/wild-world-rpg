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
| V14 | Faction-unique armor (4 signature-брони, re-scope: weapons уже в S25) (ADR-046) | ✅ SHIPPED | v0.51.242 | 2026-05-24 |
| — | 🎨 V14 image-tail: 4 картинки faction-брони (loot.armor, gpt-image-2 V4, 0-text) | ✅ SHIPPED | v0.51.243 | 2026-05-25 |
| V15 | Endgame polish + 🏁 закрытие Фазы 3 (честный показ сопротивлений + канон-броня + фикс картинки осмотра) | ✅ SHIPPED | v0.51.244 | 2026-05-25 |
| V16 | Workshop specialization foundation (оружейник/медик/инженер + craft-time перк) — 🏛 старт Фазы 4 (ADR-047) | ✅ SHIPPED | v0.51.245 | 2026-05-25 |
| — | 🐛 UX-фикс: убран спам «Используйте меню внизу экрана» из карточки перса (reply-keyboard персистит) | ✅ SHIPPED | v0.51.246 | 2026-05-25 |
| V17 | Specialization perks — scaling craft-time по уровню (per-branch кривые L5↔L25, ADR-048) | ✅ SHIPPED | v0.51.247 | 2026-05-25 |
| V18 | Robotics T2 — специализированные роботы Разведчик/Промышленник (tier-aware рантайм + 999-фикс, ADR-049) | ✅ SHIPPED | v0.51.248 | 2026-05-25 |
| — | 🎨 V18 image-tail: 2 картинки T2-роботов (robot.scout/robot.industrial, gpt-image-2 V4, 0-text) | ✅ SHIPPED | v0.51.249 | 2026-05-25 |
| V19 | Robot repair — восстановление durability роботов (закрытие dead promise здания, re-scope с T3, ADR-050) | ✅ SHIPPED | v0.51.250 | 2026-05-25 |
| V20 | Faction communal project — async вклад → фракц-buff крафта (re-scope с «2+ одновременно», ADR-051) — 🏁 закрытие Фазы 4 | ✅ SHIPPED | v0.51.251 | 2026-05-25 |
| V21 | Crafting economy dashboard (admin-аналитика gold/turnover/inflation + период-фильтр, ADR-053) — 💹 старт Фазы 5 | ✅ SHIPPED | v0.51.252 | 2026-05-25 |
| V22 | «Биомы решают» — biome-driven gather rebalance (BiomeResourceModifier → GameSettings, 9 биомов, re-scope с rare biome-spots, ADR-054) | ✅ SHIPPED | v0.51.253 | 2026-05-25 |
| V23 | NPC-мастер на базе — gold-only мгновенный ремонт tools как gold-sink эндгейма (re-scope c literal «NPC repair shop», ADR-055) | ✅ SHIPPED | v0.51.259 | 2026-05-27 |
| V24 | Селективная страховка крафта — pre-paid вечный полис на robots/workbench/transport (NPC-агент, complement PersonalInsurance, ADR-056) | ✅ SHIPPED | v0.51.260 | 2026-05-27 |
| V25 | Странствующие NPC-караваны на карте — fix-price offer редких ресурсов со скидкой (literal «trade routes», 🏁 закрытие Фазы 5, ADR-057) | ✅ SHIPPED | v0.51.261 | 2026-05-27 |
| V26 | GAME_DESCRIPTION canon reconciliation — V16-V21 6 секций (Специализация / T2-роботы / Ремонт роботов / Фракц-проект / Дашборд экономики) — 📚 старт Фазы 6 | ✅ SHIPPED | v0.51.262 | 2026-05-27 |
| V27 | Content-pass v2 — sweep советов под V14-V25 (2 update + 7 new tips: FactionArmor / WorkshopSpecialization / RobotsT2 / FactionProject / BiomeProfile / CraftInsurance / Caravans) | ✅ SHIPPED | v0.51.263 | 2026-05-27 |
| V28 | Image-tails backfill — 3 defensive здания (WoodenWall / BarbedFence / WatchTower) + 3 LEXICON-записи; 4 strategic уже в проде; ADR-041 honoured | ✅ SHIPPED | v0.51.264 | 2026-05-26 |
| V29 | Tech-writing closer — CaravanService.md + CaravanLook/Buy handler-ноты + handlers/services index обновлены (constitutional ADR-009 для V25); vault-only docs | ✅ SHIPPED | v0.51.264 | 2026-05-26 |

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
- **V13** ✅ **SHIPPED v0.51.227 (2026-05-22, ADR-037).** Strategic quest-chains GhostCity (Партизаны) + IslandFarm (Фермеры): Capture(discover)→mid(char_level)→final(craft signature weapon). Теперь **все 4 фракции** имеют полную цепочку фракция→объект→quest→оружие. **Audit-first вскрыл латентный баг V12** (исправлен здесь): `craft_item`-objective не видел faction-weapons (`output_type=weapon` в `characters_weapons` мимо `crafted_items_log`) → финалы недостижимы (BUILT-BUT-DEAD); фикс — `craftedCount` суммирует все output-пути. +5 тестов (762), phpstan L9, testbot e2e 11/11 + 4 реальных Telegram-уведомления.
- **V14** ✅ **SHIPPED v0.51.242 (2026-05-24, ADR-046).** Faction-unique armor. **Audit-first вскрыл drift:** literal-scope «4 faction-specific weapons» уже закрыт S25/ADR-029 (4 оружия live на проде, финалы цепочек V11-V13); «Tier 4» — не отдельный верстак. **Re-scope (user pick): faction-БРОНЯ** — по 1/фракцию, симметрично оружию → полный faction-лут (оружие+броня). 4 net-new Legendary outfit'а (armor 22-32, дифференц. по архетипу: тяж. Военные ↔ лёгк. Партизаны+стелс), gate `required_faction`+`required_quest` (reuse ADR-029) + `output_type=outfit` (S18). Reuse-only (0 новой логики): меню `FactionArmorCraftSelect` + `ArmorRecipePreviewT3Action` расширен faction-gate + динамич. back. 4 GameSettings `tier3.faction_armor.*` (ADR-024). +6 тестов (FactionArmorLockTest), phpstan L9 ✅. testbot e2e (faction-gate дискриминирует 3/4 заблок.) + прод verify (SHA 40499a8, 4/4/4). Картинки — image-wave tail.
- **V15** ✅ **SHIPPED v0.51.244 (2026-05-25) — 🏁 ЗАКРЫТИЕ ФАЗЫ 3.** Endgame polish (честный closer, user pick). **Audit-first вскрыл drift:** literal-scope «T4 images» уже закрыт V14 image-tail (v0.51.243) → re-scope в polish + закрытие. **Аудит эндгейм-блока:** все 4 фракции укомплектованы (объект → 3-шаговая цепочка → signature оружие+броня), меню/маршруты/гейты/картинки на месте; нашлись 4 реальных хвоста: (1) **канон-дрейф** — GAME_DESCRIPTION упоминал только фракц. оружие, не броню; (2) **gear-detail картинка** — owned фракц. броня в осмотре падала на `default_armor.jpg` (`GearArmorDetailAction` держит карту только для standard/, V14-картинки в professional/); (3) **полу-честность** — сопротивления игроку вообще не показывались (`special_bonus` — dead column); (4) **🔴 баг шкалы (вскрыт Tier-3 prep)** — 4 фракц. брони авторились с сопротивлениями в шкале 0–1 (0.20/0.25…), а вся остальная броня + боёвка — в 0–100 (`PvpDamageCalculator` делит на 100). Эффект фракц. брони выходил ~0 (0.2% вместо 20%) → тематические сопротивления (ADR-046) были **мертвы даже в PvP**. **Фиксы (display + data + docs):** новый pure-хелпер `App\Services\Display\OutfitDisplayHelper` (резолв professional/-картинки + рендер 3 PvP-сопротивлений `physical/fire/poison` как есть в шкале 0–100 + честная PvP-сноска; `speed/stealth` не показываем — нигде в боёвке не читаются); проброшен в `GearArmorDetailAction` (картинка + блок «Спец-свойства» + сноска) и `ArmorRecipePreviewT3Action`; **миграция `V15FixFactionArmorResistanceScale`** — 4 брони ×100 к шкале 0–100 (идемпотентно; исходник V14 тоже исправлен) → сопротивления реально работают в PvP как в ADR-046; GAME_DESCRIPTION += фракц. броня (честная PvP-оговорка). Показ корректен для ВСЕЙ брони (TacticalArmorSuit +40%, не +4000%; раньше ×100-баг). **+8 тестов (OutfitDisplayHelperTest, 789→797), phpstan L9 ✅, composer test 797/797.** Картинки — V14 tail (4× HTTP 200). Tier-3: render-proof deployed-хелпера против реального DB-row + testbot migrate verify (значения 20/25/12… на 0–100). RNG-fence: данные детерминированы. ADR-046 V15-аддендум.

🏁 **ФАЗА 3 ЗАКРЫТА (V11-V15, 2026-05-21…25):** эндгейм quest-chains + faction-лут. V11 prerequisite-фундамент (ADR-036). V12 objective-движок (discover/craft/explore/level) + цепочки Bunker+Technopark (ADR-037). V13 цепочки GhostCity+IslandFarm + craft_item-фикс. V14 faction-броня (ADR-046) — полный 1:1 лут оружие+броня для всех 4 фракций. V15 честный closer (канон + честный показ сопротивлений + фикс картинки осмотра). Итог: каждая фракция = объект → 3-шаговая цепочка → signature оружие + броня; objective-движок переиспользуем; 2 ADR (036/037) + 1 (046); прод-теги v0.51.225→244. **Следующее: Фаза 4 (V16 Workshop specialization — шире аудитория).**

### ⚙️ Фаза 4 — Player progression branching (P5/P8)
- **V16** ✅ **SHIPPED v0.51.245 (2026-05-25, ADR-047) — 🏛 СТАРТ ФАЗЫ 4.** Workshop specialization foundation. **User pick: foundation + базовый перк** (выбор без эффекта = BUILT-BUT-DEAD). **Audit-first:** системы специализаций не было; крафт уже категоризирован полем `zone_name` в CraftRecipes → чистый 3-веточный маппинг без новой инфры; хук под перк готов (`calculateCraftingDuration` уже множит на building/food). **3 ветки:** оружейник(`оружие`)/медик(`медицина`)/инженер(`производство`+`защита`+`инструменты`); матч-ветка крафтит на −10% быстрее (live-tunable, мультипликативно с верстаком/сытостью). `characters.specialization` + `specialization_changed_at` (+allowedFields, не повторили disable_media-косяк). `SpecializationService` (зеркало FoodBuffService, pure GameSettings-reader). Выбор: гейт по уровню (L5), первый раз бесплатно, смена платно (5000🪙)+кулдаун(7д) — не player-hostile. UI: строка+кнопка «🎓 Специализация» в карточке перса → `SpecializationAction` (меню) + `SpecializationChooseAction` (выбор/смена, edit-in-place, media-off safe). 5 GameSettings `specialization.*` (category=craft, rich rationale ADR-024) + killswitch. **+9 тестов (SpecializationServiceTest, 797→806), phpstan L9 ✅.** testbot e2e: миграция (2 колонки + 5 настроек) + все 3 ветки против deployed-кода (weaponsmith→оружие 0.90, medic→медицина 0.90, engineer→производство/инструменты 0.90, off-branch 1.00). **V17 углубит (per-branch/per-tier рост).**
- **V17** ✅ **SHIPPED v0.51.247 (2026-05-25, ADR-048).** Specialization perks — scaling по уровню. **User pick: ось=уровень персонажа + форма=скорость крафта (разные кривые).** **Audit-first:** cascade-паттерн `BuildingEffectsService::resolveLevelMultiplier` (интерполяция L3↔L10, ADR-042) реюзаем; mastery/craft-count tracking нет (net-new state → отклонено). Множитель времени крафта матч-ветки **растёт по уровню** (интерполяция anchor'ов L5↔L25 per-branch): weaponsmith 0.90→0.80 (−10%→−20%, узкая/высокоценная → сильнее), medic →0.83 (−17%), engineer →0.85 (−15%, широкая → слабее per-craft, компенсируется охватом). Старт равный (−10% на L5 = V16). Без anchor'ов — fallback на V16 global (0 регрессии). `SpecializationService::craftTimeMultiplierForLevel` (интерполяция) + `getCraftTimeMultiplierFor(+$charLevel)`; хук `GenericCraftActionStart` передаёт уровень; UI меню/confirmation показывают эффективный −X% + per-branch диапазон + что растёт. 6 GameSettings `specialization.<branch>.l5/l25.craft_time_multiplier`. **+4 теста (806→809), phpstan L9 ✅.** testbot e2e (3 ветки × 6 уровней против deployed-кода: интерполяция точна, clamp >L25) + **Tier-3 real-Telegram** (меню per-branch диапазоны, выбор Оружейник@L306→−20% endgame, edit-in-place, markdown) — full PASS.
- **V18** ✅ **SHIPPED v0.51.248 (2026-05-25, ADR-049).** Robotics T2 — специализированные роботы. **Audit-first вскрыл дрейф:** «researcher/medic/scout» из роадмапа — мёртвые кнопки (сняты в N1); реальных роботов 2 — `RobotExplorer` (открывает клетки) + `RobotGatherer` (добыча). Роботы НЕ нишевые: RoboticsWorkshop = 6-е по популярности здание (6 владельцев L1-L10), роботов юзают 6-9 из 23 игроков L10+ (~26-39% эндгейма), ~1900 крафтов. **🔴 BUILT-BUT-DEAD trap:** completion-хендлеры игнорировали личность робота (ключ — task-handler) → новый рецепт вёл бы себя как T1. **🐛 Латентный баг:** `CompleteRobotExplorationHandler` искал `building_id=999` (placeholder) → workshopLevel всегда=1, knob per-level cells-rate МЁРТВ (L10 ~3000 вместо ~8400 клеток). **User pick (re-scope): T2-варианты двух реальных роботов** с настоящей tier-aware-логикой. **Сделано:** 2 net-new T2-робота — 🔭 `RobotScout` (explorer T2, `robots.scout.cells_multiplier`=1.6 → больше карты) + 🏭 `RobotIndustrial` (gatherer T2, `yield_multiplier`=1.5 + `extra_cells`=+1); гейт RoboticsWorkshop **L2** (через `required_building_levels`, S16/ADR-026 — 0 нового gate-кода). Tier-seam = `task_settings.crafted_item_id` (старт-экшены/coords-команда пишут id → completion резолвит name_eng → множитель из нового `RobotService`); T1/неизвестный/killswitch-off → нейтрально (0 регрессии). Фикс 999-бага (резолв workshop по `name_en`). Баланс роботов мигрирован `Config\GameBalance`→GameSettings (7 ключей `robots.*`, category=resources, killswitch `robots.t2.enabled`). Coords-команда: хардкод `robotId=81`→резолв (предпочитая T2). UI: 2 кнопки (killswitch-aware) + `RobotT2PreviewAction` (читает рецепт из config) + 2 preview-сабкласса + 2 CallbackRoutes; активация — switch name_eng в `ActivateRobotHandler` → существующие активаторы. Reuse-only рантайм (generic_craft, существующие work-задачи singleton-на-тип). **+8 тестов (RobotServiceTest, 809→817), phpstan L9 ✅.** testbot e2e (миграции + RobotService deployed reads + T2-крафт→crafted_items_log type=robots + 999-фикс) + **Tier-3 real-Telegram** (меню 2 ряда T1/T2, preview все req+L2-гейт+component-name-resolution+markdown, крафт-старт с точным списанием ресурсов) — full PASS. Картинки — placeholder (копии T1), dedicated art в image-tail. **V19 углубит (T3 + апгрейды/ремонт).**
- **V19** ✅ **SHIPPED v0.51.250 (2026-05-25, ADR-050).** Robot repair — восстановление durability. **Audit-first вскрыл:** ремонт роботов — мёртвое обещание здания (`buildings.description` «создавать **и ремонтировать**», но 0 кода ремонта; роботы — расходники durability); T2 только что отгружен (0 крафтов) → T3 = tier-bloat. **User pick (re-scope, подтверждён): ремонт**, не T3/новые роботы — остаёмся на **4 роботах** (2 семьи × 2 тира), глубина > ширина. **Сделано:** 🔧 Ремонт восстанавливает durability текущего (частично израсходованного) робота до базового за долю стоимости крафта (`computeCost`: gold + resources × `cost_fraction` 0.6 × restored/base; компоненты не берутся; cost_fraction<1 → дешевле перекрафта = maintenance-loop + устойчивый sink). `RobotRepairService` (pure калькулятор) + `RobotRepairBaseAction`/`RobotRepairAction` (превью have/need) / `RobotRepairConfirmAction` (транзакция: списание + `durability_count=base`); кнопка 🔧 Ремонт на обоих активаторах (когда `current<base`); `robotRepair`/`robotRepairConfirm` exactRoutes (ключ = сегмент до 1-го `_`, без коллизии). 2 GameSettings `robots.repair.*` (killswitch + cost_fraction, rich rationale ADR-024). Building-описание стало честным (ремонт реально есть). **+6 тестов (817→823), phpstan L9 ✅.** testbot e2e (миграция + RobotRepairService deployed + e2e durability 600→1200 + списание) + **Tier-3 real-Telegram** (база→Постройки→Мастерская→Роботы→активация→🔧 Ремонт→превью have/need→Восстановить; durability 600→1200, gold −12000, ресурсы −4/−21/−17 точно) — full PASS. **🐛 Tier-3 поймал** `canAfford(array)` vs `CharacterEntity` TypeError (unit/phpstan не ловят) → фикс `array|CharacterEntity`. **Робото-арка Фазы 4 закрыта** (V16-V17 специализация, V18-V19 роботы); далее V20 — faction-coop crafts.
- **V20** ✅ **SHIPPED v0.51.251 (2026-05-25, ADR-051) — 🏁 ЗАКРЫТИЕ ФАЗЫ 4.** Faction communal project. **Audit-first вскрыл:** фракции 1-6 членов (Фермеры=**1**!), игра асинхронная (нет «online»), cross-player shared-state НЕТ (net-new); тема самая низкоранжированная (Σ=9). Жёсткий «2+ одновременно» невозможен (1-member фракция) + не подходит async-игре. **User pick (re-scope): async фракц-проект → buff.** **Сделано:** члены фракции АСИНХРОННО вносят золото в общий пул (`faction_projects`, one-per-faction); при достижении порога — ВСЕ члены получают временный buff крафта (−10% 24ч), пул сброс (carry), cycles++, broadcast всем. Работает при любом числе членов (1 = медленно-соло, много = быстро вместе), опционально (П2/П3/П9 целы), чистый эндгейм gold-sink. `FactionProjectService` (deposit-транзакция + craftTimeMultiplierFor) + `FactionProjectModel`; хук в `calculateCraftingDuration` (× faction buff, цепочка building×food×spec×faction); UI `FactionProjectAction`/`DepositConfirmAction` + кнопка 🤝 в карточке (faction 1-4); 5 GameSettings `faction.project.*` (killswitch+threshold+deposit+duration+mult). **+8 тестов (823→831), phpstan L9 ✅.** testbot e2e (deposit→порог→buff→craft-mult, на Фермерах=1 член) + **Tier-3 real-Telegram** (карточка→🤝→экран→Внести ×2→Цель достигнута+buff активен+broadcast; gold −2000 точно) — full PASS. **🏁 Фаза 4 закрыта** (V16-17 специализация + V18-19 роботы + V20 фракц-кооп).

🏁 **ФАЗА 4 ЗАКРЫТА (V16-V20, 2026-05-25):** player progression branching. V16-17 крафт-специализация (3 ветки + scaling по уровню, ADR-047/048). V18-19 роботы (T2 Разведчик/Промышленник tier-aware + ремонт, ADR-049/050). V20 фракц-кооп (async communal project → фракц-buff, ADR-051). 6 ADR, прод-теги v0.51.245→251. **Следующее: Фаза 5 — Economy & services (V21 crafting dashboard).**

### 💹 Фаза 5 — Economy & services (P7/P8)
- **V21** ✅ **SHIPPED v0.51.252 (2026-05-25, ADR-053) — 💹 СТАРТ ФАЗЫ 5.** Crafting economy dashboard — read-only admin-аналитика `/admin/crafting-economy`. **Audit-first:** scaffold готов (BaseAdminController + admin login-filter + sidebar + `templates/dashboard`); S30 CSV = `CraftTreeController::export()` (reuse 1:1); данные populated на проде (108.7M золота/365 чаров/кит 68M, 6432 крафт-лога 14 мес, 340 транзакций, 1611 ресурс-строк → НЕ BUILT-BUT-DEAD). **Сделано:** `CraftingEconomyService` (read-only агрегаты) + `CraftingEconomyController` (index/data/export); KPI-карточки + **5 ApexCharts** (концентрация золота=инфляц-сигнал, оборот крафта по месяцам, топ крафтов, топ ресурсов, транзакции buy/sell) + CSV-экспорт + sidebar «Экономика крафта». **По фидбэку user'а:** добавлен **фильтр периода** (пресеты 3/12/24 мес / всё время + календарный диапазon С—По; применяется к временны́м метрикам; золото/ресурсы=текущий снимок) + CSV синхронит период. **+7 тестов (831→838), phpstan L9 ✅** (view `$this` — конвенц. baseline как все 44 view). **Tier-2 real-admin (Chrome MCP):** все 5 графиков рисуются, KPI заполнены, фильтр-пресеты/календарь переключают данные, CSV-href синхронит период — full PASS. **🐛 Tier-2 поймал:** `vendor/apexcharts/apexcharts.min.js` → 404 (public/vendor в .gitignore, не деплоится; все theme-vendor 404) → графики не рисовались при заполненных KPI; фикс — self-host ApexCharts в tracked `public/assets/js/admin/`. Урок → memory `feedback_admin_theme_vendor_not_deployed`. Read-only: баланс не меняет (правки — через GameSettings).
- **V22** ✅ **SHIPPED v0.51.253 (2026-05-25, ADR-054).** «Биомы решают» — biome-driven gather rebalance. **Audit-first re-scope:** литеральный S10 «rare biome-spots» = BUILT-BUT-DEAD (3/4 редких ресурса held=0, 0 рецептов их потребляют, ~21 eligible-игрок). Реальная боль: 81% игроков в Лесу, 6 из 9 биомов без модификатора, `BiomeResourceModifier` = хардкод-магия (нарушение ADR-024). **User pick: «Биомы решают».** **Сделано:** `BiomeResourceModifier` (хардкод ×3/×10/÷2 на 3 биома, N+1) → `BiomeGatherProfileService` (GameSettings-driven, 9 биомов, batch-резолв имён) + `Config\GatherBiomeProfiles` (data-shape biome_id→slug/signature[]/scarce[]). 11 `gather.biome.*` GameSettings (killswitch + offbiome + 9 per-biome signature, category=resources). **Pure non-hostile:** legacy 3 биома сохранены точно (Лес Древесина ×10, Горы Камни ×3, Пустыни Песок ×10 + scarce ×0.5=÷2); 6 новых биомов + deserts — только бусты. Детерминированно (RNG-fence). Discoverability: строка «🌟 Этот биом богат: …» в результате добычи (media-off safe). **+14 тестов (838→852), phpstan L9 ✅** (baseline −12 obsolete). **testbot e2e** (11 ключей + deployed reads: forest ×10/scarce ×0.5/caves ×3 + anti-drift NONE) + **Tier-3 real-Telegram** (Тропики: Фрукты 654/Лианы 543/Орхидеи 126 ×3 vs non-sig той же редкости, хинт-строка чисто) — full PASS. **🐛 Tier-3 anti-drift поймал:** deserts scarce=Вода был no-op (Вода в биоме 9 не добывается) → deserts scarce=[]. **✅ ОТГРУЖЕНО НА ПРОД (v0.51.253).**
- **V23** — NPC repair shops (реюз S5 repair + GameSettings cost).
- **V24** — Crafted-item insurance (NPC-сервис; P2 оффлайн-защита).
- **V25** — Trade routes / NPC caravans (MVP; если settlement-системы нет — упрощённый inter-base обмен) + закрытие фазы.

### 📚 Фаза 6 — Canon, polish & vNext-of-vNext
- **V26** — GAME_DESCRIPTION canon reconciliation (5 lore tails — отдельный scope из S29).
- **V27** — Content-pass v2 (lying descriptions sweep по новому контенту V1–V25).
- **V28** ✅ **SHIPPED v0.51.264 (2026-05-26).** Image-tails backfill. **Audit-first вскрыл re-scope:** literal-формулировка «WatchTower S26b + 4 strategic» проходит проверку как **3 defensive здания** (WatchTower + WoodenWall + BarbedFence из ADR-041) — 4 strategic-объекта (Bunker/Technopark/GhostCity/IslandFarm) уже сгенерированы в S21-S25, файлы на проде, LEXICON есть. Реальный pending = defensive trio (`camp/watch_tower.jpg` / `wooden_wall.jpg` / `barbed_fence.jpg` отсутствовали → handler рендерил caption-only через MediaSender media-off safe fallback). **Сделано:** 3 LEXICON-записи (`building.wooden-wall` / `building.barbed-fence` / `building.watch-tower`) + 3 ImageRegistry entries (V4 mode, минимум артефактов, читаемая постройка центром кадра) + `php spark images:generate --missing` (132/272/216 KB JPG, все < 300 KB cap, 0-text проверены визуально, gpt-image-2 V4). LEXICON-сцены тематичны: бревна+металл-накладки+мешки песка / колючка+тряпки+мутная глина / лашёные брёвна+подзорная+колокол+тарп. **Tier-1 ✅** php -l + phpstan L9 NO ERRORS на ImageRegistry. **Tier-3 на проде:** открыть defensive здание из карточки базы → картинка рендерится. Closer image-debt'а Фазы 6.
- **V29** ✅ **SHIPPED v0.51.264 vault-only (2026-05-26).** Tech-writing closer (constitutional ADR-009 для V25). **Audit-first:** ADR-нумерация 001-057 чиста; устаревших last_reviewed нет (ноты V14-V27 от 2026-05-25/27). Единственный реально missing — V25 пакет (CaravanService + CaravanLookAction + CaravanBuyAction, handlers/caravan/ дир не существовала; constitutional debt). Admin-polish (CSV-exports, destructive confirms, AuditLog period) — отложен на post-V30, чтобы не размывать closer Фазы 6. **Сделано:** `tech-writing/services/CaravanService.md` (по шаблону service-doc: публичный API 6 методов, 6 GameSettings caravan.*, формула `ceil(market × (1−discount))`, уроки V25 — апострофы/cell-update race) + `tech-writing/handlers/caravan/{CaravanLookAction,CaravanBuyAction,index}.md` + обновлены `tech-writing/services/index.md` (1 CaravanService строка + 9 ссылок V6-V25 в `Services/Player/`) + `tech-writing/handlers/index.md` (новый раздел «Caravan/»). 0 кода — vault-only. Closer constitutional contract.
- **V30** — Retrospective v2 + ROADMAP-CRAFT-vNext2.md (тот же 5-осевой алгоритм поверх V1–V29). 🏁

---

## §2.7 Inbox для vNext2 (post-V30 кандидаты)

> Кандидатные темы для **ROADMAP-CRAFT-vNext2.md** (будет синтезирован в V30 по тому
> же 5-осевому алгоритму). Записываются по ходу vNext, не теряются.

### 🚁 Идея: Разведывательный квадрокоптер / летающий ручной дрон (зафиксирована 2026-05-26 user'ом)

**User-formulation (буквальная):** игрок на любой ячейке, где он находится, запускает
ручной разведывательный дрон. Сам игрок **остаётся на месте**. Дрон очень быстро
изучает ячейки вокруг (радиус ~10 при взлёте). У дрона есть **расход
электроэнергии**, который пополняется только когда игрок на базе проводит больше
N часов (черновик N=2). Дрон видит вещи, которые не видит пеший игрок без
передвижения — подсвечивает ~10 ячеек в радиусе. Цена/баланс — продуманные, чтобы
не имба и не useless. Дрон можно **крафтить** ИЛИ **покупать готовый** у редких
NPC-торговцев (потенциальный connect с V25 caravans).

**Зачем (player-impact):** новый player-initiated scouting слой против пассивных
RobotScout/RobotExplorer (V18). Дрон даёт **мгновенный контролируемый радиус** vs
авто-фоновый таск роботов. Ниша: «мне нужно сейчас увидеть что за углом — не идти
самому». Конкурирует с движением (V14 «Поход» / [[ADR-019]]) — оптимизирует
исследование, но платит electricity-ресурсом.

**Канон/сеттинг-проверка (предварительная):** ✅ постапок-scrap-tech, квадрокоптер
из проводов, солнечных пластин, salvaged motors — реалистично, «Найденная фотоплёнка»-friendly.
Не магия, не sleek sci-fi, не настоящие military/IP-drone bodies. Канон образ:
DIY-квадрокоптер с открытой проводкой, propeller-guards из колец wire, видимая
sky-bracketed мачта камеры.

**Архитектурная зависимость:**
- Реюз RobotService паттерна (V18) для item-balance + GameSettings.
- Новый ресурс/state: `characters.drone_battery_charge` (0..max) + lazy charge-on-base-time
  (~зеркало FoodBuffService well_fed_until).
- Per-cell радиус через ExploredCellsModel (S26/N6-friendly).
- UI: кнопка «🚁 Запустить дрон» в кнопочной строке клетки (когда уровень+условия).
- Optional: edit-in-place rendering scan-результата (батч #12 паттерн).

**Балансировочные knobs (всё через GameSettings, ADR-024):**
- `drone.scout.radius_cells` (10)
- `drone.scout.battery_drain_per_launch` (100)
- `drone.scout.battery_max` (100 = 1 запуск)
- `drone.scout.base_charge_minutes_per_full` (120 = 2 часа на базе)
- `drone.scout.craft_cost_gold` (расчёт по 5-осевому)
- `drone.scout.caravan_offer_chance` (редкость в V25 offer'ах)
- killswitch `drone.scout.enabled`

**5-осевая оценка (черновик для S30/vNext2 sort):**
| Ось | Балл | Аргумент |
|---|---:|---|
| Lore-debt | 0 | net-new фича, ничего не закрывает |
| Arch-зависимость | +3 | unlock для drone-семейства (Combat/Cargo/Repair drone в дальнейшем) |
| Stack-fit | +2 | реюз CI4/RobotService/GameSettings/Cron, 0 новых либ |
| Setting-coherence | +5 | DIY-scrap-tech, постапок, рукоделие, fits «Найденная фотоплёнка» canon |
| Player-impact | +4 | ниша «мгновенный контролируемый scouting» vs пассивные V18-роботы; П1/П4/П5/П6/П7 выигрывают; П2/П3/П9 не страдают (опционально) |
| **Σ** | **14** | top-1 кандидат vNext2 по предварительному прогону |

**Открытые вопросы (для V30/audit-first vNext2):**
1. Battery — глобальная (1 общая для всех drone-варианты) ИЛИ per-drone? (Решение влияет на семейство расширений Combat/Cargo).
2. Charge-condition — «N часов на базе» (предлагается user'ом) ИЛИ «N часов sleep-сессий» (P2-friendly) ИЛИ «уровень здания Workshop/SolarStation»?
3. Каков FOV scan — все ресурсы клеток ИЛИ только специфическое (NPC-spotting, ресурс-deposit, hidden-loot)?
4. Каравaн (V25) даёт **готовый** дрон ИЛИ **редкий рецепт-чертёж**? (Чертёж = более gold-sink, готовый = быстрый shortcut)
5. Что с PvP — дрон над чужой базой = разведка перед атакой ИЛИ запрет (anti-snooping)?
6. Дрон одноразовый (взлёт = успешный scan, потеря возможна) ИЛИ durability-style как робот?

→ **Ответы на 1-6 — задача S30/audit-first vNext2**, не сейчас.

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

- v1 итоги: `mmorpg-vault/reference/ROADMAP-CRAFT-v1.md` §0 (S1–S29, архив), retrospective `mmorpg-vault/inbox/2026-05-20-roadmap-v1-retrospective.md`.
- Алгоритм: `mmorpg-vault/reference/ROADMAP-CRAFT-v1.md` §S30. Валидация: `GAME_RULES_AND_VALIDATION_FRAMEWORK.md` (7 ворот, 10 портретов).
