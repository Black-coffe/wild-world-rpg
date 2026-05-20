# Wild World — Craft / Build / Resources Roadmap на 30 сессий

> **Версия документа:** v1.1 (2026-05-17, decisions locked)
> **Базовая линия:** v0.51.179, ~358 активных персонажей, develop @ commit 676b9d9
> **Направление:** crafting / building / resources
> **Срок исполнения:** 30 сессий (≈ v0.51.180 → v0.51.220)
> **Стиль документа:** roadmap-источник-правды, **не маркетинг**. Каждая сессия — самостоятельный work-item.

> **🎯 v1.1 update (decisions locked by user 2026-05-17):**
> 1. S5 Repair cost = **50% default**, вынесено в **GameSettings admin framework** (новый foundation в S5, ADR-024).
> 2. S10 Rare resources = events + strategic objects + **rare biome-spots** (Volcanic/Caves/Tropical/Mountains).
> 3. S16 T3 Workbench level = **L20** (повышено с L16).
> 4. S26 Defensive structures = все параметры **через GameSettings**, default-ы зашиты (см. таблицу в S26).
> 5. S28 Seasonal = **4 сезона × 21 день** default, **через GameSettings**, контракт «возвращается каждый год».
> 6. Image-генерации = **per-session** (не batch), для smoke во время сессии.
> 7. ROADMAP-vNext S30 = **алгоритм приоритезации по 5 осям** (lore-debt / архитектура / stack / setting / impact).
>
> Полная таблица решений — см. **§12.2 Decisions Log**.

---

## §0 Session log (live status)

> **Каждый shipped session отмечается здесь.** Не редактируй вручную — обновляется
> Claude'ом в конце каждой сессии после прод-релиза. Для деталей — daily-нота
> в `mmorpg-vault/daily/<YYYY-MM-DD>.md`. Полный технический разбор сессии — там.

Легенда: ✅ shipped | ⏳ in progress | ⬜ pending | 🔀 scope-changed

| S# | Title | Status | Prod tag | Date | Daily | Notes |
|---|---|---|---|---|---|---|
| S1 | Cleanup 12 legacy `Build*Construction` → `GenericBuildingInfoAction` | ✅ shipped | v0.51.183 | 2026-05-17 | [`daily/2026-05-17`](file:///C:/Projects/mmorpg-vault/daily/2026-05-17.md) | 3 hotfix'а + polish (Entity narrowing уроки) |
| S2 | Normalize `crafted_items.required_resources` to JSON | ✅ shipped | v0.51.184 | 2026-05-17 | same | 5 форматов (не 3 как в ROADMAP), runtime read-only |
| S3 | Building.level wiring → UI suffix | ✅ shipped | v0.51.185 | 2026-05-17 | same | 🔀 ROADMAP устарел — вся upgrade-инфра уже была wired; реальный gap = level в списке |
| S4 | Broken-tool Telegram notification | ✅ shipped | v0.51.186 | 2026-05-17 | same | 🔀 ROADMAP описывал `is_broken` flag — реальность deletion model; +1 hotfix русские имена |
| S5a | GameSettings live-tunable balance framework (ADR-024) | ✅ shipped | v0.51.187 | 2026-05-17 | same | 🔀 split S5 на S5a (foundation) + S5b (repair UI); foundation для S26/S28/S10 |
| S5b | Repair mechanic UI (tool repair action + completion handler) | ✅ shipped | v0.51.188 | 2026-05-17 | same | 3 hotfix'а: id_characters column, ResourceEntity narrowing, fresh Model per loop (CI4 builder state quirk) |
| S6 | Missing resources (Ironstone / Oil / Sulfur / RareMetals / Coal) | ✅ shipped 🔀 | — (docs-only) | 2026-05-18 | [`daily/2026-05-18`](file:///C:/Projects/mmorpg-vault/daily/2026-05-18.md) | 🔀 ROADMAP полностью устарел: все 5 ресурсов давно в БД (id=72/40/51/55/73), активно добываются ~20 чарами на проде. Реальный gap = только docs (close lore tail #4 в GAME_DESCRIPTION). Coal=Coalbed alias. Нет прод-тега. |
| S7 | Biome↔Resource explicit mapping config | ✅ shipped 🔀 | v0.51.189 | 2026-05-18 | same | 🔀 ROADMAP описывал «GatherService::getResourceRates() 150 LOC match» — такой функции нет, gather уже data-driven через `resources.biome_id` CSV. Реальный gap = silent substring-leak (LIKE `%1%` матчил `'10'`/`'11'`/`'1,2,5'`) — fix через FIND_IN_SET в 4 hot-path callsites. + admin reverse-view `/admin/biomes/{id}/resources`. +4 DB-tests. |
| S8 | English ingredient cleanup (CraftRecipes) | ✅ shipped 🔀 | v0.51.190 | 2026-05-18 | same | 🔀 ROADMAP полностью fake: «Iron Rods / Rubber / Gears + Mountain Bike / Wooden Fence» — ни одного нет в коде. Locally + testbot DB checks: 37 ru + 6 en уникальных ингредиентов, **0 dead refs**. Shipped как anti-drift guard test (3 теста). Без code change прод-behaviour. |
| S9 | Scheduled price update cron | ✅ shipped 🔀 | v0.51.191 | 2026-05-18 | same | 🔀 ROADMAP описывал «перевести `updateResourcePrices()` на cron» — cron УЖЕ работает (`ResourceBankUpdateHandler::process()` зарегистрирован `everyMinute()` в `Tasks.php:56`). Реактивный вызов в `Sell\SellAction:26` был **silent loser** в race: cron перетирал результат в 60s другой формулой. Cleanup: -41 LOC (метод в Model + wrapper в BaseAction + 1 live + 2 commented). +1 anti-drift test. baseline -4 stale ignores. |
| S10 | Rare late-game resources | ✅ shipped 🔀 | v0.51.192 | 2026-05-19 | [`daily/2026-05-19`](file:///C:/Projects/mmorpg-vault/daily/2026-05-19.md) | 🔀 ROADMAP описывал создание `RareNodeService`/`RareNodeRollHandler`/`CharacterGatherHistoryModel` — **дубликат** F7.2 `RareResourceGrantEffect` (4 events уже live). Семантика rarity обратная (1=rare, 9-10=common). Shipped: +4 биом-специфичных events (Volcanic/Caves/Tropical/Mountains) через тот же effect + 4 ресурса (rarity=1) + 24 GameSettings keys (8 events × 3 params: chance/amount_min/amount_max) с rich rationale + RareResourceGrantEffect refactor с GameSettings cascade. +12 tests (575 total), phpstan L9 ✅ baseline unchanged. |
| S11 | Workshop L2 + L3 craft time multiplier | ✅ shipped 🔀 | v0.51.193 | 2026-05-19 | same | 🔀 ROADMAP описывал «BuildingUpgrades.php Workshop entry + CraftService::getCraftTime()» — drift: `BuildingUpgrades.php` = generic per-level **cost** (не effects); `CraftService::getCraftTime()` не существует — реал `GenericCraftActionStart::calculateCraftingDuration()`; `buildings.effects='crafting_bonus'` = dead string. На проде уже Workshop L1/L2/L5 (3+2+2 chars) без эффекта. Shipped foundation для S11-S15 (BuildingEffectsService): 2 GameSettings keys (L2=0.90, L3=0.75) + cascade lookup (L4-L10 plateau на L3 user pick) + hook. +10 tests (585 total), phpstan L9 ✅ baseline unchanged. Tier 3 smoke ✅ (char 491: no Workshop→1.0/22min, L2→0.90/20min, L5 cascade→0.75/17min). +1 hotfix apostrophe в migration text. |
| S12 | BlastFurnace L2 + L3 → MetalFragments yield multiplier | ✅ shipped 🔀 | v0.51.194 | 2026-05-19 | same | 🔀 Drift: `BuildingUpgrades.php` BlastFurnace entry (повтор S11), `CraftService` не существует, `MetalFragments` recipe не требует BlastFurnace (нет `required_buildings`), `buildings.effects='smelt_metal'`=dead string, 6+1 chars на L1/L2 без эффекта. **User pick: pure bonus** (no breaking — chars без BF продолжают крафтить с baseline). Extension S11 foundation: `getCraftYieldMultiplier(charId, boostBuilding)` + новое recipe field `boost_building='BlastFurnace'` + hook в `GenericCraftCompletionHandler` (max($qty, round($qty × mul))). 2 GameSettings keys (L2=1.15, L3=1.35, L4-L10 plateau cascade). +7 tests (592 total). **Tier 3 smoke ✅**: char 491 BF L5→1.35, end-to-end task qty=10 → +14 fragments (qty_before 50 → after 64). phpstan L9 ✅ baseline unchanged. |
| S13 | Laboratory time stack + Greenhouse service wrapper | ✅ shipped 🔀 | v0.51.195 | 2026-05-19 | same | 🔀 Drift: Greenhouse уже wired (per-level table в GameBalance, 3+2 chars L1/L2 на проде); Laboratory = pure cosmetic (`buildings.effects='research_and_development'`=dead string). User pick: оба пункта без conflict. **S13a Lab**: 2 GameSettings keys (L2=0.90, L3=0.75), extend `getCraftTimeMultiplier(extraBuilding)` multipliers stack (Workshop × Lab → L3+L3=0.5625 -44% для medical), новое recipe field `boost_building_time` в 8 medical recipes. **S13b Greenhouse**: `getGreenhouseProductionForLevel(level)` read-through от GameBalance (foundation для future GameSettings migration), `GreenhouseProductionHandler` через service. +8 tests (600 total). **Tier 3 smoke ✅** (Workshop L2+Lab L2 → 0.81 / 22→18 min; L3+L3 → 12 min; Greenhouse parity 10/10 levels identical). phpstan L9 ✅. |
| S14 | RoboticsWorkshop L2/L3 → robot craft time multiplier | ✅ shipped 🔀 | v0.51.196 | 2026-05-19 | same | 🔀 Drift: ROADMAP premise «робото-час працює тільки L1» **хибний** — `6 × workshopLevel` (Explorer) і `2 × workshopLevel` (Gatherer) у Start*Action вже скейлиться лінійно; на проді є RoboticsWorkshop L1..L10 (6 чарів). T2/T3 robot recipes (4 нові крафти + handlers + 6 images) **відкладено** як нішевий feature (~6 крафтерів роботів / 358 чарів). User pick: **pure-multiplier scope** замість T2/T3. Pattern reuse S13a: `boost_building_time => 'RoboticsWorkshop'` у RobotExplorer + RobotGatherer recipes; 2 GameSettings keys (L2=0.90, L3=0.75); L4-L10 plateau cascade. Stack Workshop L3 + Robotics L3 = 0.5625 (-44% для робот-крафту). +4 tests (604 total). **Smoke matrix on testbot ✅** (9 scenarios end-to-end через real GameSettings reader: no-bldg→1.0, L1→1.0, L2-only→0.9, L3-only→0.75, stack L2+L2→0.81, L3+L3→0.5625, L5+L10 cascade→0.5625). phpstan L9 ✅. |
| S15 | TeleportationCenter L2/L3 → teleport cost multiplier | ✅ shipped 🔀 | v0.51.197 | 2026-05-19 | same | 🔀 Drift: ROADMAP claim «больше доступных маяков (5/8 от base 3)» **хибний** — `BeaconPlacementValidator:132-134` вже скейлить ліміт лінійно: `min(10, intdiv(playerLevel, 10)) + max(1, min(10, buildingLevel))` (L1=+1, ..., L10=+10). Numbers ROADMAP фейкові. Beacon-ліміт НЕ зачіпається. **Реальний gap** = cost reduction. `TeleportCostService::calculateTeleportCost` = `1000 × character.level` без врахування TC level. На проде 5 чарів з TC (2×L1, 1×L2, 2×L3) платили однаково. User pick: **pure-multiplier scope** (S11-style). + `getTeleportCostMultiplier(charId)` у BuildingEffectsService; 2 GameSettings keys (L2=0.85, L3=0.70); L4-L10 plateau cascade. Backward-compat: `calculateTeleportCost(level, ?charId)` — без $charId baseline формула (legacy preview/info actions). +11 tests (615 total). **Smoke matrix on testbot ✅** (7 scenarios end-to-end: L10 no TC→10000g, TC L1→10000g, L2→8500g (-15%), L3→7000g (-30%), L10 cascade→7000g, L20+L3→14000g, L25+L2→21250g). phpstan L9 ✅ baseline +1 same-pattern count. |
| S16 | Tier 3 Professional Workbench (ADR-026) — старт Фази 4 🟠 | ✅ shipped 🔀 | v0.51.198 | 2026-05-19 | same | 🔀 Drift: ROADMAP «dedicated `CraftCompletionProfessionalWorkbenchHandler`» stale (WorkbenchOne уже через `GenericCraftCompletionHandler` з F3.B8 mature pattern — -250 LOC дубля). ROADMAP прод-тег v0.51.195 stale (вже shipped 197). **ADR-026 створено** — обґрунтування канон-розширення (двохрівневий → 3 тиры). User pick: apply drift fixes. Shipped: 2 migrations (crafted_items+tasks row + 2 GameSettings keys `tier3.workbench.character_level_required=20`, `tier3.workbench.craft_duration_hours=8`) + `Config\CraftRecipes['ProfessionalWorkbench']` (resources: Редкие металлы×30, Доколлапсная электроника×3, Промышленный пластик×5, wiring×20, gold=50000) + **НОВІ recipe schema fields** `required_level_setting_key` + `duration_override_setting_key` + `required_building_levels` (reusable ADR-026 pattern для tier-gated крафтів) + `GenericCraftActionStart` extension (GameSettings live-tunable level override + building level check + duration override) + `WorkbenchProfessionalAction` (entrance UI) + Worker.php route + CallbackRoutes entry. Gate: char L20 + BlastFurnace L3 + Laboratory L3 + база. Duration: 8h. +4 tests (619 total). phpstan L9 ✅ baseline unchanged. |
| S17 | T3 weapons (5 recipes) 🟡 | ✅ shipped 🔀 | v0.51.199 | 2026-05-19 | same | 🔀 **Major drift**: ROADMAP S17 предлагал INSERT 5 новых junk-tier weapons (`MasterCrossbow`, `HeavyRebar`, `PipeShotgun`, `SteelMachete`, `ScrapRifle`) + 5 dedicated handlers. **Audit-finding**: 20 weapons L1-L26 уже в БД (seed legacy), incl. 4 Legendary L20-L26 (`Ion/Flame/Exo/HydraPlasma`). Junk-tier L20 не вписывается рядом с Legendary energy. Реальный gap — **16 lootable weapons L4-L26 без craft option**. User pick: Option B — рецепты для existing (`GaussPistol` L16 Epic / `RailCarbineVikhr` L18 Epic / `IonDestabilizer` L20 Legendary / `FlamethrowerAid` L20 Legendary / `ExoRailgunBehemoth` L24 Legendary). 0 weapon row inserts. Shipped: 2 migrations (5 tasks rows + 5 GameSettings keys per-recipe duration override `tier3.weapons.<key>.craft_duration_hours` 2-6h) + 5 recipes в CraftRecipes (T2-weapon pattern + new field `required_crafted_items: ['ProfessionalWorkbench' => 1]`) + `GenericCraftActionStart` extension (checkRequiredCraftedItems gate, **reusable для S18-S20**) + dual-mode `WorkbenchProfessionalAction` (pre-build → gate screen / post-build → T3 craft menu) + `WeaponsCraftT3Select` (меню 5 weapons) + **1 generic** `WeaponRecipePreviewT3Action` (5 callbacks через prefix route `craftPreviewT3_` — DRY вместо 5 boilerplate Actions) + 5 Worker.php routes + 2 CallbackRoutes entries (1 exact + 1 prefix). +1 lexicon `loot.tech-weapon` + 6 images (5 T3 weapons + S16 backfill `professional_workbench`). +8 tests (627 total). phpstan L9 ✅ baseline unchanged. |
| S18 | T3 armor (4 recipes) ⚡ 🟡 | ✅ shipped 🔀 | v0.51.200 | 2026-05-19 | same | 🔀 **Identical drift to S17**: ROADMAP S18 предлагал INSERT 4 новых armor (`PlatedArmor`/`KevlarPatchwork`/`TacticalVest`/`HardenedHelmet`). **Audit**: 20 outfits L0-L25 уже в БД (seed legacy на проде), incl. 5 Legendary L18-L25 (`TitanPowerArmor`, `TeslaShardArmor`, `ShadowArmor`, `PhantomApparel`, `JuggernautBattleArmor`). 16/20 outfits без craft option (0 чаров владеют), 4 T2 craftable активны. `HardenedHelmet` (helmet) — drift: все 20 outfits `slot='body'`, helmet slot не существует. User pick: Option B (Generic extension + рецепты для existing). Shipped: 2 migrations (4 tasks rows + 4 GameSettings keys `tier3.armor.<key>.craft_duration_hours` 2-6h) + **NEW recipe schema** `output_type='outfit'` (3rd dispatch path после weapon + crafted_item) + `GenericCraftCompletionHandler::handleOutfitOutput` (зеркало handleWeaponOutput но для characters_outfits + updateAgilityAndIntellect; reusable для T2 armor refactor — -1500 LOC будущая) + 4 recipes в CraftRecipes (output_type='outfit', outfit_name_en matches DB, required_crafted_items=ProfessionalWorkbench) + кнопка «🛡 Броня T3» в WorkbenchProfessionalAction + ArmorCraftT3Select (меню 4 armor) + ArmorRecipePreviewT3Action (generic preview через prefix route `craftPreviewT3Armor_` — DRY S17 reuse) + 4 Worker.php routes + 2 CallbackRoutes (1 exact + 1 prefix) + 4 ImageRegistry entries (lexicon `loot.armor` V4) + 4 images. **Pick**: `TacticalArmorSuit` L16 Epic 22 armor / `ExoskeletonStrekoza` L16 Epic 10 armor light / `TitanPowerArmor` L20 Legendary 30 armor heavy / `TeslaShardArmor` L25 Legendary 27 armor electric. +8 tests (635 total). phpstan L9 ✅ baseline regenerated. |
| S19 | T3 medical (3 recipes) ⚡ 🟡 | ✅ shipped 🔀 | v0.51.201 | 2026-05-20 | [`daily/2026-05-20`](file:///C:/Projects/mmorpg-vault/daily/2026-05-20.md) | 🔀 **Triple drift**: (1) «3 dedicated handlers» stale — GenericCraftCompletionHandler default crafted_item path покрывает (как S16-S18). (2) `SyntheticMedicine` «лечит любую болезнь» — **системы болезней НЕТ** (drug-эффекты только health/tired cap 100; «Эпидемия»/«Лихорадка» = transient damage-события). User pick: переосмыслить как премиум-хилки. (3) 3 предмета **НЕ в БД** (на проде 10 drug items) — genuine new content (≠ S17/S18 «recipe for existing»). **Bonus-находки**: heal-эффект был захардкожен в `UsePharmacyAction` switch (новые drug не лечили); 2 orphan-предмета (headache_tablets, Common cold tincture) в БД без рабочего эффекта; callback-парсинг `explode('_')[1]` ломался на name_eng с `_`/пробелом. Shipped: 3 migrations (3 crafted_items drug + 3 tasks; 3 GameSettings duration keys category=craft; **10 GameSettings heal keys** category=combat — 3 new + 2 orphans × heal_health/heal_tired, rich rationale) + 3 recipes (default crafted_item output, required_crafted_items=ProfessionalWorkbench gate) + **data-driven heal в UsePharmacyAction** (читает GameSettings medical.<snake>.heal_health/.heal_tired; legacy 8 switch intact = 0 регрессии) + callback-парсинг fix (substr) + MedicalCraftT3Select + MedicalRecipePreviewT3Action (generic preview prefix `craftPreviewT3Medical_`) + кнопка «💊 Медицина T3» + 3 Worker routes (dual map) + 2 CallbackRoutes + 3 images (loot.medicine V4) + 3 ImageRegistry. Picks: 💉 SyntheticMedicine L20 full-restore (100HP/60), 🩸 EmergencyTransfusion L18 +80HP, 🧰 SurgicalKit L22 multi-use ×3 (50/30). +10 tests (635→645), phpstan L9 ✅ baseline unchanged. Новая category `combat` в admin GameSettings. |
| S20 | T3 utility (3 recipes) ⚡ 🟡 | ✅ shipped 🔀 | v0.51.202 | 2026-05-20 | [`daily/2026-05-20`](file:///C:/Projects/mmorpg-vault/daily/2026-05-20.md) | 🔀 Drift: ROADMAP `CombinationTool`/`PortableForge`/`AdvancedFishingNet` не существуют. Audit вскрыл existing-but-incomplete: **DiamondPickaxe** (УЖЕ в `ToolManager` bonus-map, работает, owned 5 чарами, до +220% по глине — но БЕЗ рецепта); **Sapper Shovel / Golden Hoe** (в БД, owned, но НЕ в map → ничего не делали); **7 `utility`-type items** (Portable Furnace/Animal Trap/Hydroponic Farm…) — 0 ссылок в коде (мёртвые). User pick: Option A (3 existing tools) + bonus через GameSettings overlay. Shipped: 3 migrations (3 tasks — items уже в crafted_items; 3 duration keys category=craft; 2 tool gather-bonus keys `tools.<tool>.gather_bonus` category=resources, rich rationale) + 3 recipes (default crafted_item output, item_name_eng С ПРОБЕЛАМИ для 2 из 3, ProfessionalWorkbench gate) + **ToolManager GameSettings overlay** (`T3_TOOLS` const resource-set + `t3Bonus()` memoized, fallback default; legacy map + DiamondPickaxe не тронуты = 0 регрессии) + UtilityCraftT3Select + UtilityRecipePreviewT3Action (prefix `craftPreviewT3Utility_`) + кнопка «🔧 Утилиты T3» + 3 Worker routes (dual map) + 2 CallbackRoutes + 3 images (loot.tool V4) + 3 ImageRegistry. Picks: ⛏️ DiamondPickaxe L20, 🪏 Sapper Shovel L16 (+70% копаемые), 🌾 Golden Hoe L16 (+60% фермерские). +13 tests (645→658; 2 ToolManager DB-skip локально), phpstan L9 ✅ baseline unchanged. **Testbot smoke ✅** (throwaway `smoke:s20`: overlay resource→tools / legacy preserved / no-leak / spaces-name lookup / pickBestTool). **Prod LIVE**, 0 errors. 🏁 **Фаза 4 ЗАКРЫТА 5/5.** |
| S21 | Strategic-объекты (Bunker/Technopark/GhostCity) — старт Фази 5 🟠 | ✅ shipped 🔀 | v0.51.203 | 2026-05-20 | [`daily/2026-05-20`](file:///C:/Projects/mmorpg-vault/daily/2026-05-20.md) | 🔀 **Самый радикальный drift фазы**: ROADMAP «построить Bunker с нуля + DiscoverStrategicObjectAction/BunkerCaptureHandler/ADR-027 заново» — но вся цепочка (3 world_objects + StrategicLootHandler + discovery + endgame +500 + auto-complete квестов `StrategicCapture*`) построена к v0.51.117. Прод-аудит: цепочка **МЁРТВАЯ** с 2026-05-08 — объекты `inactive` + у `WorldObjectGeneratorHandler` нет spawn-case (`default: break`) → 0 спавнов за всё время, 0 quest_steps, 0 discovery-очков. User pick: **оживить все 3** (S21+S22+S23 схлопнуты — generic-работа общая). Shipped: spawn-case `generateStrategicObject()` (random-offset placer, БЕЗ O(N) distanceCheck) + `effectiveMaxCount()` (cap из GameSettings, не flood-колонки) + 2 migrations (активация status+rare max_count 5/4/8+ослабление непроходимых multi-tool гейтов; 3 GameSettings keys `world.strategic.*.max_spawns` category=world rich rationale) + `STRATEGIC/SUPPORTED_SPAWN_TYPES` anti-drift const. ADR-027. +9 tests (658→667), phpstan L9 ✅ baseline unchanged. **Testbot e2e ✅** (`smoke:s21`: cron заспавнил Bunker×5/Techno×4/Ghost×8 точно по cap; discovery char491 → gold +5000, Милитари 0→600 (+500+100), quest auto-complete, Bunker cleared, loot +3 res). |
| S22–S23 | Technopark (Engineers) / GhostCity (Partisans) | ✅ покрыто S21 | v0.51.203 | 2026-05-20 | same | Схлопнуты в S21 — generic spawn-case + активация покрывают все 3 объекта. Остаются per-факционные анонсы (поэтапно). |
| S24 | Island-Farm (Farmers) 🟠 | ✅ shipped 🔀 | v0.51.204 | 2026-05-20 | [`daily/2026-05-20`](file:///C:/Projects/mmorpg-vault/daily/2026-05-20.md) | 🔀 Drift: ROADMAP «привязать discovery к FarmersHarvest» — но FarmersHarvest уже работает (auto-complete на Greenhouse L3, `UpgradeBuildingAction` v0.51.118). User pick: новый квест `StrategicCaptureIslandFarm` (консистентно с 3 другими), FarmersHarvest не трогаем. Закрывает асимметрию — Фермеры были единственной из 4 фракций без discovery-source +500. Genuine net-new: world_object Island-Farm отсутствовал. Полное переиспользование S21-инфры (4-я точка): `IslandFarm` в STRATEGIC/SUPPORTED_SPAWN_TYPES + switch + ObjectDiscoveryService map/match + EndgameScoring `IslandFarm=>4`. 3 migrations (world_object born active, биом Поля, gate Hoe, фермер-лут; квест L10/4000g; GameSettings `world.strategic.islandfarm.max_spawns=8`). +1 wiring assert + anti-drift consistency-test mirrors обновлены. composer test 667 ✅, phpstan L9 ✅. |
| S25 | Faction-unique weapons (×4) 🟠 — 🏁 ЗАКРЫВАЕТ ФАЗУ 5 | ✅ shipped 🔀 | v0.51.205 | 2026-05-20 | [`daily/2026-05-20`](file:///C:/Projects/mmorpg-vault/daily/2026-05-20.md) | 🔀 Drift: ROADMAP «4 dedicated handlers Weapons/Faction/» + «recipe-hidden-without-quest» (механизма не было). Audit: 4 weapon отсутствуют в `weapons` (genuine net-new). User pick: **true faction-exclusive (фракция + квест)**, не quest-only (любой может захватить любой объект → quest-gate не эксклюзивен). Завершает 1:1 цепочку фракция→объект→signature weapon. Shipped: НОВЫЕ recipe-поля `required_quest`+`required_faction` (gate в `GenericCraftActionStart`, зеркало required_crafted_items) + 4 recipes (output_type=weapon, ProfessionalWorkbench gate, ADR-029) + 3 migrations (4 weapons Legendary niche 32-40 dmg + 4 tasks + 4 GameSettings duration keys) + `FactionWeaponsCraftSelect` меню + reuse generic preview (расширен gate-показом + dynamic back) + Worker/CallbackRoutes. +4 tests (667→671, FactionWeaponLockTest 1:1 bijection). phpstan L9 ✅. **Testbot e2e ✅**: char491 (Фермеры, оба квеста Bunker+IslandFarm done) — BunkerRifle blocked (faction 4≠1), FarmersHarvestScythe allowed → materialized в characters_weapons (доказывает что faction-gate блокирует там, где quest один — нет). |
| S26 | Defensive structures (walls/fence) — старт Фазы 6 🟠 | 🔄 audit + ADR-030 done, **билд pending** | — | 2026-05-20 (audit) | [`daily/2026-05-20`](file:///C:/Projects/mmorpg-vault/daily/2026-05-20.md) | 🔀 **Audit-drift**: PvP = полевые дуэли (НЕ осада базы); round loop под RNG-fence (только детерминированные эффекты); `building_type`=MySQL ENUM (нужен ALTER); ROADMAP-пути неверны. User pick: WoodenWall+BarbedFence, эффект только у своей базы, GameSettings+cap 40%, WatchTower→S26b. **ADR-030** ([[mmorpg-vault/decisions/ADR-030-Defensive-structures-PvP]]) с fairness-аудитом + 10-шаговым build-планом. **User-решение: билд отдельной сессией** (PvP-экономика + RNG-fence = самая опасная сессия, нужен свежий контекст). Front-half (🟠 card+ADR) сделан. |
| S27–S30 | queue UI / seasonal rotation / content-pass / admin tools | ⬜ pending | — | — | — | см. §9 |

**Прод-теги дня 2026-05-17 (foundation marathon):** `v0.51.178` foundation → `v0.51.179` defensive thumbs → `v0.51.180` roadmap v1 → `v0.51.181` decisions locked → `v0.51.182` constitutional admin-tunable rule → **v0.51.183 (S1)** → **v0.51.184 (S2)** → **v0.51.185 (S3)** → **v0.51.186 (S4)** → **v0.51.187 (S5a)** → **v0.51.188 (S5b)**. **11 прод-релизов в день. Фаза 1 (Tech Foundation) полностью закрыта.**

**Прод-теги дня 2026-05-18 (Resource expansion):** **v0.51.189 (S7)** → **v0.51.190 (S8)** → **v0.51.191 (S9)**. S6 — docs-only (без тега). 3 прод-релиза + 1 docs-fix.

**Прод-теги дня 2026-05-19 (BuildingEffects marathon + Phase 4 start):** **v0.51.192 (S10)** → **v0.51.193 (S11)** → **v0.51.194 (S12)** → **v0.51.195 (S13)** → **v0.51.196 (S14)** → **v0.51.197 (S15)** → **v0.51.198 (S16)** → **v0.51.199 (S17)** → **v0.51.200 (S18)**. **9 прод-релизов в день (record). Фаза 2 (Resource Expansion, S6-S10) полностью закрыта. 🏁 Фаза 3 (Building progression, S11-S15) полностью закрыта 5/5.**

**Прод-теги дня 2026-05-20 (🏁 Фаза 4 + 🏁 Фаза 5 закрыты):** **v0.51.201 (S19)** → **v0.51.202 (S20)** → **v0.51.203 (S21)** → **v0.51.204 (S24)** → **v0.51.205 (S25)**. 🏁 **Фаза 4 (T3 expansion, S16-S20) ЗАКРЫТА 5/5** (15 T3-рецептов). 🏁 **Фаза 5 (Endgame faction, S21-S25) ЗАКРЫТА** — S21 оживил strategic-объекты (S22+S23 схлопнуты), S24 добавил Island-Farm (паритет фракций), S25 — 4 faction-unique weapons (true faction-exclusive). **5 прод-релизов за день.** Полная 1:1 цепочка эндгейм-войны для всех 4 фракций.

**Сводка по фазам (на 2026-05-20):**
- ✅ **Фаза 1 (S1-S5b)** — Tech foundation, GameSettings framework, repair UI. Closed v0.51.188.
- ✅ **Фаза 2 (S6-S10)** — Resource expansion, biome mapping fix, anti-drift, cron cleanup, rare drops. Closed v0.51.192.
- ✅ **Фаза 3 (S11-S15)** — Building progression. 5/5 done (S11/S12/S13/S14/S15 — Workshop/BlastFurnace/Lab+Greenhouse/Robotics/TeleportCenter через `BuildingEffectsService`). Closed v0.51.197.
- 🏁 **Фаза 4 (S16-S20) ЗАКРЫТА** — T3 expansion (15 рецептов). **S16 ✅** v0.51.198 (Professional Workbench + ADR-026 reusable pattern). **S17 ✅** v0.51.199 (5 T3 weapons + schema `required_crafted_items`). **S18 ✅** v0.51.200 (4 T3 armor + `output_type='outfit'` dispatch). **S19 ✅** v0.51.201 (3 T3 medical + data-driven heal через GameSettings + 2 orphan-фикса). **S20 ✅** v0.51.202 (3 T3 utility tools + ToolManager GameSettings overlay; recipe-for-existing DiamondPickaxe + оживление Sapper Shovel/Golden Hoe). Closed v0.51.202.
- 🏁 **Фаза 5 (S21-S25) ЗАКРЫТА** — Endgame faction content. **S21 ✅** v0.51.203 (оживление strategic-объектов Bunker/Technopark/GhostCity; **S22+S23 схлопнуты в S21**). **S24 ✅** v0.51.204 (Island-Farm для Фермеров — паритет, новый квест StrategicCaptureIslandFarm). **S25 ✅** v0.51.205 (4 faction-unique weapons, true faction-exclusive gate `required_quest`+`required_faction`, ADR-029). Полная 1:1 цепочка для всех 4 фракций: фракция→сценарий→strategic-объект→quest→+500→signature weapon. Closed v0.51.205.
- ⬜ **Фаза 6 (S26-S30)** — Polish + ROADMAP-vNext (defensive structures, queue UI, seasonal rotation, content-pass, admin tools).

**Ключевой урок первых 23 сессий**: ROADMAP описание устаревает к моменту исполнения (**22 из 23 сессий S1-S25 имели drift** с реальным кодом; S22+S23 схлопнуты в S21). S25 drift: ROADMAP «4 dedicated handlers + recipe-hidden-without-quest» — механизма не было, реализован generic gate `required_quest`+`required_faction`. **Каждая сессия теперь начинается с audit'а реального состояния** перед follow-through. **S21 — рекордный drift**: целая эндгейм-фича (strategic-объекты, построена v0.51.109-117) была мёртвой с 2026-05-08 (объекты `inactive` + spawn-case отсутствовал → 0 спавнов, 0 discovery за всё время); ROADMAP предлагал строить заново то, что уже существовало (но не работало). Реальный gap = оживить (spawn-case + активация), а не построить. S5a — единственная «свежая» сессия с актуальным описанием. Радикальные drift'ы: S6 — миграция NOOP; S7 — `GatherService::getResourceRates()` не существует; S8 — recipes fake; **S9 — cron УЖЕ работал**; **S10 — `RareNodeService` дубликат F7.2**; **S11 — `CraftService::getCraftTime()` не существует**; **S12 — MetalFragments не требует BlastFurnace**; **S13 — Greenhouse УЖЕ wired** через per-level table; **S14 — Roboticum УЖЕ скейлится** через `6 × workshopLevel`, на проде L1..L10; **S15 — beacon-ліміт УЖЕ скейлиться** (L1=+1, ..., L10=+10); **S16 — dedicated handler stale** (GenericCraftCompletionHandler покрывает); **S17 — 20 weapons УЖЕ в БД** (junk-tier ROADMAP лишний, рецептим existing L16-L24); **S18 — 20 outfits УЖЕ в БД** + ROADMAP HardenedHelmet drift (нет helmet slot, only body); **S19 — нет системы болезней** (SyntheticMedicine «cure-all» нереализуем → премиум-хилки) + heal-эффект был захардкожен в switch (новые drug не лечили) + 2 orphan-предмета без эффекта; **S20 — `CombinationTool`/`PortableForge`/`AdvancedFishingNet` не существуют** (рецептим existing DiamondPickaxe + оживляем мёртвые Sapper Shovel/Golden Hoe; 7 utility-items вообще не подключены к коду). Audit-first **mandatory**.

---

## §1 Преамбула

### 1.1 Цель документа

Этот файл — **тех-roadmap на 30 сессий** по направлению `crafting / building / resources` в Wild World. Документ построен под двух читателей:

1. **Андрей (solo-разработчик).** Должен открыть, выбрать следующую неначатую сессию (S-N), увидеть весь скоуп: канон-ссылку, список файлов, миграцию, картинки, smoke, тесты, прод-тег.
2. **Claude Code в новой сессии.** Открывает roadmap (по адресу `ROADMAP-CRAFT.md`) → читает §3 «Стратегическая карта» → находит активную фазу → выполняет одну сессию по её шаблону → коммитит → переходит к следующей.

Документ **самостоятельный** — кто-то, кто не видел репо, должен из него понять что и как делать. Реальные технические детали (модели, сервисы, БД-схемы) — через wiki-link на `mmorpg-vault/tech-writing/` и canon-link на `GAME_DESCRIPTION.md`.

### 1.2 Контракт с CLAUDE.md

Документ обязан не противоречить:

- **`CLAUDE.md`** — constitutional rule ADR-009 (tech-writing нота при каждом изменении).
- **`GAME_DESCRIPTION.md`** — слитный канон геймплея. Любая сессия, расходящаяся с каноном, помечается красным флагом и предлагает синхронное обновление канона.
- **`GAME_RULES_AND_VALIDATION_FRAMEWORK.md`** — 7 ворот валидации + 4 категории правил (🔴/🟠/🟡/🟢). Каждая сессия в §4–§9 проходит через сокращённую валидационную карточку (§11 хранит полные карточки).
- **`mmorpg-vault/decisions/ADR-009`** — sync tech-writing.
- **`mmorpg-vault/decisions/ADR-017`** — фреймворк валидации (карточка идеи).
- **`mmorpg-vault/decisions/ADR-022`** — image style (Найденная фотоплёнка).

### 1.3 Текущая базовая линия

| Метрика | Значение | Источник |
|---|---|---|
| Тег прод | v0.51.179 | git tag |
| Активных персов | ~358 | prod DB |
| Рецептов крафта | 46 | `app/Config/CraftRecipes.php` (963 LOC) |
| Зданий | 12 | `app/Config/Buildings.php` (404 LOC; **все 12 уже мигрированы на GenericBuildingAction** — F2.1 закрыта; legacy `Build*Construction.php` ждут удаления-cleanup) |
| Биомов | 9 | `BiomeModel` (danger 3–10) |
| Ресурсов | ~70 в БД | `resources` таблица |
| Действующих фракций | 4 (+ нейтралы) | `Config\EndgameScoring` |
| Apply'нутых миграций | актуально на `2026-05-14-100000` | `app/Database/Migrations/` |
| phpstan level | 9, baseline ~4910 | `phpstan.neon.dist` |
| Тестов | ~4434 | `composer test` |

### 1.4 Шесть фаз

| Фаза | Сессии | Фокус | Прод-теги | Файл §  |
|---|---|---|---|---|
| **Фаза 1 — Tech foundation** | S1–S5 | required_resources JSON, building upgrade wiring, durability decay, repair | v0.51.180 → v0.51.184 | §4 |
| **Фаза 2 — Resource expansion** | S6–S10 | missing resources, biome↔resource mapping, english ingredient cleanup, price cron, rare resources | v0.51.185 → v0.51.189 | §5 |
| **Фаза 3 — Building upgrades live** | S11–S15 | Workshop / BlastFurnace / Lab+Greenhouse / Robotics+robots T2-T3 / TeleportCenter L2-L3 | v0.51.190 → v0.51.194 | §6 |
| **Фаза 4 — Craft depth Tier 3** | S16–S20 | T3 Workbench ADR, weapons, armor, medical, utility | v0.51.195 → v0.51.199 | §7 |
| **Фаза 5 — Endgame faction content** | S21–S25 | Bunker / Technopark / GhostCity / Island-Farm + 4 faction-unique weapons | v0.51.200 → v0.51.204 | §8 |
| **Фаза 6 — Polish + ROADMAP-vNext** | S26–S30 | defensive structures, queue UI, seasonal rotation, content-pass, admin tools | v0.51.205 → v0.51.209 (резерв до v0.51.220) | §9 |

### 1.5 Метрики успеха

- **LOC delta.** Цель — net **−1500 LOC** по итогам 30 сессий за счёт удаления legacy `Build*Construction.php` (F2.1 cleanup), `WorkbenchGeneral/Components/*`, дубль-handler'ов; +600 LOC новых рецептов/конфигов.
- **+Tests.** Не менее **+150 unit-тестов** за 30 сессий (5 на сессию в среднем; больше — на S2/S4/S5/S16/S21).
- **phpstan baseline.** Не регрессит ни на одной сессии. Цель — −300 от текущего baseline 4910 (через явные type-аннотации новых конфигов).
- **Прод-теги.** 30 минорных тегов v0.51.180 → v0.51.209 (резерв до v0.51.220 если будут хотфиксы).
- **Активных игроков.** Не падает ниже 350 (метрика «не сломали ничего критичного»).
- **Анонсов в чате.** 5–7 крупных анонсов (после S2 контентный re-balance, после S6 новые ресурсы, после S11/12/13/14/15 видимые улучшения зданий, после S16 T3 верстак, после S21–S25 эндгейм-объекты, после S30 ROADMAP-vNext).

### 1.6 Параллелизация — ⚡

Сессии, помеченные **⚡**, могут идти параллельно с предыдущей или одновременно с другой:

- S13 (Lab + Greenhouse L2/L3) — ⚡ параллель с S12 (BlastFurnace) — независимые цепочки построек.
- S18 (T3 armor) — ⚡ параллель с S17 (T3 weapons) — общий T3 верстак уже стоит к этому моменту.
- S19 (T3 medical) — ⚡ параллель с S20 (T3 utility) — тот же T3 верстак.
- S22 (Technopark) — ⚡ параллель с S23 (GhostCity) — независимые building+quest пары.
- S24 (Island-Farm) — ⚡ параллель с S25 (faction weapons) — последний не зависит от S24.

Параллелизация = «можно делать в одной сессии Claude Code, релизить раздельными тегами». Не путать с «git branch». Develop остаётся линейным; параллель — это **планирование**, не VCS.

### 1.7 Как читать сессию

Каждая сессия в §4–§9 содержит **17 секций** (Цель / Канон / Боль / Меняем / Создать / Изменить / Миграции / Image-assets / Validation-card mini / Smoke / Tests / Tech-writing / Прод-тег / Параллель / Зависимости / Анонс / Замечания). Полные validation-cards (по 7 воротам каждой) собраны в §11. Image-budget и LEXICON-планы — в §10.

### 1.8 «Что значит закрытая сессия»

По CLAUDE.md §«Что считается завершённой задачей»:

- ✅ Идея прошла фреймворк валидации (карточка в §11)
- ✅ Код написан, PHPUnit зелёный (`composer test`)
- ✅ Миграция применена и smoke-протестирована
- ✅ Документация в репо обновлена (если задели лор/процесс)
- ✅ Tech-writing нота в vault обновлена/создана
- ✅ Если значимое решение — ADR в `mmorpg-vault/decisions/`
- ✅ `mmorpg-vault/wiki/hot.md` обновлён
- ✅ Если новая картинка — LEXICON-запись + строка в `Config\ImageRegistry` + сгенерённая картинка (`php spark images:generate`)
- ✅ Прод-тег выкачен, smoke на проде зелёный
- ✅ Анонс игрокам (если применимо)

---

## §2 Constitutional rules (выжимка для каждой сессии)

### 2.1 7 ворот валидации (обзор)

```
0 Формулировка → 1 Канон → 2 10-персон → 3 Баланс/системы → 4 Техно-чек → 5 Smoke-план → 6 Релиз+vault
```

Каждая сессия проходит ворота **в указанном порядке**. Провал ворот = стоп до устранения. Мелкие задачи проскакивают за минуты; крупные — за несколько итераций.

### 2.2 4 категории правил

| Категория | Что значит | Действие |
|---|---|---|
| 🔴 СТРОГО ЗАПРЕЩЕНО | Стоп. Исключений нет. Если "очень надо" — мета-обсуждение смены философии. | Сессия не стартует. Если уже стартовали — откат. |
| 🟠 ЗАПРЕЩЕНО, но могут быть исключения | "Да" только с обоснованием, расчётом, ADR, (если видимо) анонсом. | Создаётся ADR в `mmorpg-vault/decisions/`, validation-card полная. |
| 🟡 РАЗРЕШЕНО, но подумать | "Да" после чек-листа категории. Без ADR, но через все ворота + tech-writing. | Стандартный workflow. |
| 🟢 ВСЕГДА НУЖНО | Гигиенический минимум **каждой** задачи. | Невыполнение = задача не закрыта. |

### 2.3 Smoke plans по типу изменения

| Тип | Обязательный smoke |
|---|---|
| Cutover / action-side ↔ handler-side | **Telegram smoke** на testbot (unit-тесты НЕ ловят desync — урок F2.2 Bandage prod-bug) |
| View / рендеринг | **HTTP smoke** `curl <route>` (phpstan + unit НЕ покрывают runtime view — урок F5 saga) |
| Background task | `php spark tasks:run` на testbot + проверка идемпотентности (двойной запуск без двойного эффекта) |
| Migration (контент-строка) | UPDATE + reread, повторный прогон = no-op (idempotent) |
| Migration (структурная) | `migrate` + `migrate:rollback` + `migrate` (round-trip) |

### 2.4 Stepwise release pattern

```
develop (feature work)
  ↓ commit
  ↓ tag v0.51.X-step1
  ↓ rsync → testbot.wildworld.fun
  ↓ smoke ✅
  ↓ cherry-pick → master
  ↓ tag v0.51.X-step1 на master
  ↓ rsync → prod
  ↓ smoke на проде ✅
  ↓ 12–24h pause (наблюдение в логах)
  ↓ next step ↗ повторить
  ↓ финал-анонс если видимо
```

### 2.5 Шаблон validation-card (для копипаста)

```markdown
## Идея: <one-liner>

**Тип:** content | refactor | balance | fix | infra
**Подсистемы:** crafting | building | resources | (другие)
**Источник:** ROADMAP-CRAFT.md §X S-N

### Ворота 0 — Формулировка
- Проблема:
- Что меняем:

### Ворота 1 — Канон & сеттинг
- GAME_DESCRIPTION conflict? [нет / да → diff]
- Постапок-сеттинг сохранён? [да]
- Тексты говорят правду? [да]

### Ворота 2 — 10-персон чек
| Персона | Голос | Комментарий |
|---|---|---|
| П1 Хардкорщик | | |
| П2 Казуал | | |
| П3 Лорщик | | |
| П4 PvP-хищник | | |
| П5 Билдер | | |
| П6 Социальщик | | |
| П7 Завершенец | | |
| П8 Экономист | | |
| П9 Анти-P2W | | |
| П10 Новичок | | |

### Ворота 3 — Баланс & системы
- Экономика:
- Прогрессия:
- Бой:
- Тайм-гейты:
- Крафт/базы:
- Фракции/эндгейм:
- Оффлайн-безопасность:

### Ворота 4 — Техно-чек
- Миграции:
- Тесты:
- phpstan:
- Идемпотентность:
- Tech-writing нота:

### Ворота 5 — Smoke-план
- Тип → smoke:
- Шаги:

### Ворота 6 — Релиз & vault
- Stepwise шаги:
- Commit message:
- Доки:
- ADR нужен?:
- hot.md / daily:
- Анонс:

### ИТОГ: категория [ 🔴 / 🟠 / 🟡 / 🟢 ]
```

---

## §3 Стратегическая карта 30 сессий

### 3.1 Граф зависимостей (ASCII)

```
                     ┌──────── ФАЗА 1: TECH FOUNDATION ────────┐
                     │                                          │
        S1 (cleanup legacy Build*) ─────────┐                   │
                                            │                   │
        S2 (required_resources → JSON) ─────┼─── precondition for
                                            │   S11–S15 upgrades
        S3 (building level wire) ───────────┤
                                            │
        S4 (durability decay) ──────────────┤
                                            │
        S5 (repair system) ─────────────────┘
                     │
                     ▼
                     ┌──────── ФАЗА 2: RESOURCE EXPANSION ─────┐
                     │                                          │
        S6 (Ironstone/Oil/Sulfur/RareMetals/Coal) ──── precondition for
                                              │       S11+ (refers existing)
        S7 (biome↔resource mapping) ──────────┤
                                              │
        S8 (english ingredient cleanup) ──────┤
                                              │
        S9 (price cron) ──────────────────────┤
                                              │
        S10 (rare late-game resources) ───────┘
                     │
                     ▼
                     ┌──────── ФАЗА 3: BUILDING UPGRADES ──────┐
                     │                                          │
        S11 (Workshop L2/L3) ───────────────┐
                                            │
        S12 (BlastFurnace L2/L3) ───────────┤
                          ⚡                 │
        S13 (Lab+Greenhouse L2/L3) ─────────┤
                                            │
        S14 (Robotics L2/L3 + robot T2/T3) ─┤
                                            │
        S15 (TeleportCenter L2/L3 + beacons)┘
                     │
                     ▼
                     ┌──────── ФАЗА 4: T3 CRAFT DEPTH ─────────┐
                     │                                          │
        S16 (T3 Workbench + ADR-024) ───────┐
                                            │
        S17 (T3 weapons ×5) ────────────────┤
                          ⚡                 │
        S18 (T3 armor ×4) ──────────────────┤
                          ⚡                 │
        S19 (T3 medical ×3) ────────────────┤
                          ⚡                 │
        S20 (T3 utility ×3) ────────────────┘
                     │
                     ▼
                     ┌──────── ФАЗА 5: ENDGAME FACTION ────────┐
                     │                                          │
        S21 (Bunker + Military quest + ADR-025) ─┐
                          ⚡                       │
        S22 (Technopark + Engineers quest) ──────┤
                          ⚡                       │
        S23 (GhostCity + Partisans quest) ───────┤
                          ⚡                       │
        S24 (Island-Farm + Farmers quest) ───────┤
                          ⚡                       │
        S25 (4 faction-unique weapons) ──────────┘
                     │
                     ▼
                     ┌──────── ФАЗА 6: POLISH + vNEXT ─────────┐
                     │                                          │
        S26 (defensive structures) ─────────┐
                                            │
        S27 (craft queue UI) ───────────────┤
                                            │
        S28 (seasonal craft rotation) ──────┤
                                            │
        S29 (content-pass lying descr.) ────┤
                                            │
        S30 (admin tools + ROADMAP-vNext) ──┘
```

### 3.2 Прод-теги план

| Сессия | Тег | Заметка |
|---|---|---|
| S1 | v0.51.180 | F2.1 cleanup — удаление legacy `Build*Construction.php` (8–11 файлов, ~−4200 LOC) |
| S2 | v0.51.181 | required_resources JSON migration |
| S3 | v0.51.182 | building.level wired to UI |
| S4 | v0.51.183 | durability decay handler |
| S5 | v0.51.184 | repair system |
| S6 | v0.51.185 | +5 resources (Ironstone, Oil, Sulfur, RareMetals, Coal) |
| S7 | v0.51.186 | biome→resource explicit mapping config |
| S8 | v0.51.187 | English ingredient cleanup |
| S9 | v0.51.188 | price update cron |
| S10 | v0.51.189 | rare resources (Spent Fuel Rods / Pre-Collapse Electronics / etc) |
| S11 | v0.51.190 | Workshop L2/L3 |
| S12 | v0.51.191 | BlastFurnace L2/L3 |
| S13 | v0.51.192 | Lab + Greenhouse L2/L3 |
| S14 | v0.51.193 | Robotics + robot T2/T3 |
| S15 | v0.51.194 | TeleportCenter + beacon network |
| S16 | v0.51.195 | T3 Workbench Professional (ADR-024) |
| S17 | v0.51.196 | T3 weapons (5 recipes) |
| S18 | v0.51.197 | T3 armor (4 recipes) |
| S19 | v0.51.198 | T3 medical (3 recipes, incl. synthetic-medicine) |
| S20 | v0.51.199 | T3 utility (3 recipes incl. combination tools) |
| S21 | v0.51.200 | Bunker + Military endgame quest + ADR-025 |
| S22 | v0.51.201 | Technopark + Engineers quest |
| S23 | v0.51.202 | GhostCity + Partisans quest |
| S24 | v0.51.203 | Island-Farm + Farmers quest |
| S25 | v0.51.204 | 4 faction-unique weapons |
| S26 | v0.51.205 | Defensive structures (walls/traps/towers) |
| S27 | v0.51.206 | Craft queue UI visualization |
| S28 | v0.51.207 | Seasonal craft rotation |
| S29 | v0.51.208 | Content-pass + lying descriptions cleanup |
| S30 | v0.51.209 | Admin tools + tech-writing back-fill + ROADMAP-vNext draft |

Резерв v0.51.210–v0.51.220 — хотфиксы (twin-hotfix grep, view smoke catches, FK shtorm fixes).

---

## §4 Фаза 1 (S1–S5): Tech Foundation

### S1 — Cleanup legacy `Build*Construction.php` 🟡

**Цель.** Удалить ~11 уже-замещённых legacy action-handler'ов `Build*Construction.php` после подтверждения, что `GenericBuildingAction` покрывает все 12 зданий.

**Канон-ссылка.** `GAME_DESCRIPTION.md` § «🏗️ Строительство баз» (контракт зданий не меняется); `mmorpg-vault/lore/base/Постройки.md`; `mmorpg-vault/decisions/` (искать ADR про F2.1).

**Текущая боль.** `app/Config/Buildings.php` уже содержит все 12 зданий (Arsenal, Workshop, BlastFurnace, Warehouse, Laboratory, SolarStation, Gym, Greenhouse, HandPump, RoboticsWorkshop, TeleportationCenter, CommunicationTower) — но legacy `app/Controllers/Telegram/Commands/Actions/Camp/Build*Construction.php` (8–11 файлов, ~4187 LOC) до сих пор лежат рядом. Дублируют логику, путают grep, не покрыты тестами рядом с generic.

**Что меняем.**
- Grep `genericStartBuild_` в `CallbackqueryCommand` — убедиться, что все 12 зданий маршрутятся через generic.
- Grep `class StartBuild*Construction` — список файлов на удаление.
- Удалить файлы, callback-routes (legacy), упоминания в `CallbackRouter`.

**Файлы к созданию.** Нет.

**Файлы к изменению/удалению.**
- `app/Controllers/Telegram/Commands/Actions/Camp/StartBuildArsenal*.php` — удалить
- `app/Controllers/Telegram/Commands/Actions/Camp/StartBuildWorkshopConstruction.php` — удалить
- `app/Controllers/Telegram/Commands/Actions/Camp/StartBuildLabConstruction.php` — удалить
- (полный список — после `grep "class StartBuild"` в S1 пред-этапе)
- `app/Controllers/Telegram/CallbackRouter.php` — удалить legacy routes
- `tests/unit/Actions/Camp/` — удалить тесты legacy (если есть) или мигрировать на generic

**Миграции.** Нет (cleanup кода без структурных изменений БД).

**Image-assets.** Нет.

**Validation-card mini.**
- Категория: 🟡 (рефактор с 0 behavior change)
- 10-персон: все нейтрально/+; П5 Билдер + (меньше шума в кодовой базе); никто не предан
- Smoke: Telegram smoke на каждое из 12 зданий — попытка построить (cancel сразу после старта = достаточно, проверяем callback wiring)
- ADR: не нужен (выполнение уже принятого ADR F2.1)

**Smoke plan.**
1. `php spark migrate:status` на testbot — все миграции applied.
2. Telegram smoke: «База → Строительство → каждое здание» × 12, нажать «Построить» (callback `genericStartBuild_<Key>` должен ответить корректным сообщением).
3. Для одного здания (Workshop) — довести до конца, дождаться completion-handler'а.
4. Проверить, что legacy callback `startBuild_Arsenal_old` (если был) возвращает 404 / no handler.

**Tests добавить.**
- Нет новых; убедиться, что `tests/unit/Actions/Camp/Building/GenericBuildingActionTest.php` (если есть) покрывает все 12 ключей через data provider.

**Tech-writing.**
- `mmorpg-vault/tech-writing/handlers/camp/GenericBuildingAction.md` — обновить `last_reviewed`, добавить запись "F2.1 cleanup завершён".
- `mmorpg-vault/apps/buildings/index.md` — снять отметку «legacy `Build*Construction.php` ждут удаления».

**Прод-тег.** `v0.51.180`.

**Параллелизация.** Можно с S2, если файлы не пересекаются (S2 трогает БД-схему).

**Зависимости.** Нет (предыдущая работа F2.1 уже выполнена).

**Анонс.** Не нужен (internal refactor).

**Замечания.** Перед удалением каждого файла — `grep -r "<ClassName>" app/ tests/` чтобы не оставить мёртвых ссылок. Стандартный twin-grep с aliases (`StartBuild`, `BuildArsenal`, `arsenalConstruction`).

> **Статус:** ✅ shipped **v0.51.183** (2026-05-17). Master commits: `e8da314` (main) + `1cc37c6` + `22908f1` + `79d2d5d` (hotfixes) + `d618151` (polish). 12 files deleted, GenericBuildingInfoAction +370 LOC. 3 hotfix'а — все на F1.4 Entity vs array narrowing. Создан `rowToArr()` helper.

---

### S2 — `crafted_items.required_resources` free-text → JSON 🟠

**Цель.** Перевести колонку `crafted_items.required_resources` из 3-форматного free-text в строгий JSON. Это **самый большой техдолг** crafting-системы и precondition для S16 (T3 верстак) и S22 (ввод новых typed-рецептов).

**Канон-ссылка.** `GAME_DESCRIPTION.md` § «🔨 Система крафта»; `mmorpg-vault/lore/craft/`.

**Текущая боль.**
- Поле хранится в трёх несовместимых форматах:
  1. **Free-text v1.** `"Травы - 2 шт., Кора деревьев - 2 шт."` — парсится регуляркой.
  2. **PHP-array-like v2.** `"['Травы' => 2, 'Кора деревьев' => 2]"` — `eval()`-парсинг (😱).
  3. **JSON v3 (часть buildings).** `{"Травы": 2}` — уже корректно.
- Дублирующий source-of-truth: `CraftRecipes.php` хранит то же самое в `resources`, `crafted_items` — но сама БД-таблица `crafted_items.required_resources` лежит в legacy формате.

**Что меняем.**
- Миграция: ALTER колонки тип не нужен (varchar TEXT остаётся), но **idempotent UPDATE** конвертирует все строки в JSON v3.
- Сервис: `CraftingService::parseRequiredResources()` → одна реализация (`json_decode` + fallback warning в лог при corrupt).
- Все callers старой регулярки (~5 мест) — снять.

**Файлы к созданию.**
- `app/Services/Crafting/RequiredResourcesParser.php` — единая точка парсинга
- `tests/unit/Services/Crafting/RequiredResourcesParserTest.php`

**Файлы к изменению.**
- `app/Models/CraftedItemsModel.php` — `parseRequiredResources()` метод
- `app/Services/CraftService.php` — заменить регулярки на парсер
- `app/Services/Player/CraftingValidator.php` — adopt парсер
- `app/Controllers/Admin/CraftedItemsController.php` — admin UI хранит JSON

**Миграции.**
```
app/Database/Migrations/2026-05-18-100000_NormalizeCraftedItemsRequiredResourcesToJson.php
```
**up:**
```php
public function up(): void
{
    $rows = $this->db->table('crafted_items')->select('id, required_resources')->get()->getResultArray();
    foreach ($rows as $row) {
        $raw = trim($row['required_resources'] ?? '');
        if ($raw === '' || $raw === '[]') {
            continue;
        }
        $json = $this->tryParseToJson($raw);
        if ($json === null) {
            log_message('warning', 'crafted_items #' . $row['id'] . ' required_resources unparseable: ' . $raw);
            continue;
        }
        if ($json === $raw) {
            continue; // already JSON
        }
        $this->db->table('crafted_items')->where('id', $row['id'])->update(['required_resources' => $json]);
    }
}

private function tryParseToJson(string $raw): ?string
{
    // 1. Already valid JSON
    json_decode($raw, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        return $raw;
    }
    // 2. Free-text v1: "Травы - 2 шт., Кора - 3 шт."
    if (preg_match_all('/([\p{L}\s]+?)\s*-\s*(\d+)\s*шт/u', $raw, $m)) {
        $out = [];
        foreach ($m[1] as $i => $name) {
            $out[trim($name)] = (int) $m[2][$i];
        }
        return json_encode($out, JSON_UNESCAPED_UNICODE);
    }
    // 3. PHP-array-like v2: "['Травы' => 2]"
    if (preg_match_all('/[\'"]([^\'"]+)[\'"]\s*=>\s*(\d+)/u', $raw, $m)) {
        $out = [];
        foreach ($m[1] as $i => $name) {
            $out[$name] = (int) $m[2][$i];
        }
        return json_encode($out, JSON_UNESCAPED_UNICODE);
    }
    return null;
}
```
**down:**
```php
public function down(): void
{
    // No-op: we cannot reliably reverse v1/v2 from JSON, and idempotent re-runs of up() are safe.
}
```

**Image-assets.** Нет.

**Validation-card mini.**
- Категория: 🟠 (ретро-миграция данных, требует ADR — `ADR-024-Crafted-items-required-resources-JSON.md`)
- 10-персон: П3 + (single source of truth), П7 + (backwards-compat — данные сохранены), П5 + (предсказуемые парсинги), П8 + (admin может править прозрачно)
- Без анонса (internal)
- Smoke: на testbot прогон, прогон с UPDATE + reread, double-run должен быть no-op

**Smoke plan.**
1. `php spark migrate` на testbot.
2. SELECT всех `required_resources` где `JSON_VALID(required_resources) = 0` — должно быть 0 (или warning'и в логе с conscious skip).
3. Создать через admin крафт с ru-resources → должен прочитаться корректно.
4. Запустить рецепт в Telegram («Аптечка») → completion-handler читает JSON → ресурсы списываются.
5. `php spark migrate` повторно → 0 rows updated (idempotent).

**Tests добавить.**
- `RequiredResourcesParserTest::testParseV1FreeText()` — `"Травы - 2 шт., Кора - 3 шт."`
- `RequiredResourcesParserTest::testParseV2PhpArray()` — `"['Травы' => 2]"`
- `RequiredResourcesParserTest::testParseV3Json()` — passthrough
- `RequiredResourcesParserTest::testCorruptInput()` — returns null + warning
- `RequiredResourcesParserTest::testEmptyInput()` — returns empty array

**Tech-writing.**
- `mmorpg-vault/tech-writing/services/RequiredResourcesParser.md` — НОВАЯ нота
- `mmorpg-vault/tech-writing/models/CraftedItemsModel.md` — обновить (упоминание JSON-формата)
- `mmorpg-vault/decisions/ADR-024-Crafted-items-required-resources-JSON.md` — НОВЫЙ ADR

**Прод-тег.** `v0.51.181`.

**Параллелизация.** Можно с S1 (разные файлы).

**Зависимости.** Нет.

**Анонс.** Не нужен (admin-видимая правка).

**Замечания.** Twin-hotfix grep: `required_resources` встречается в ~15 файлах. Все callers — через парсер.

> **Статус:** ✅ shipped **v0.51.184** (2026-05-17). Master commit: `4ed2732`. **5 форматов на проде** (v1a newline, v1b comma, v2 PHP-array-like, v3 JSON object — целевой, v3-bis `[{"resource_id":N,"quantity":N}]` — у крафтов оружия id ≥ 11) — не 3 как описано в ROADMAP. **Открытие**: колонка runtime read-only — `Config\CraftRecipes` source of truth. Category 🟠 → 🟡 (no runtime risk). Prod migration: 66/86 normalized, 0 unparseable.

---

### S3 — Building.level wiring → UI 🟡

**Цель.** Связать существующий `BuildingUpgrades.php` (config для L2–L10) с фактическим `character_buildings.level` — чтобы апгрейды S11–S15 могли работать.

**Канон-ссылка.** `GAME_DESCRIPTION.md` § «🏗️ Строительство баз»; `mmorpg-vault/lore/base/Постройки.md`.

**Текущая боль.**
- `BuildingUpgrades.php` (109 LOC) — config с ценами L2/L3 уровней для каждого здания **существует**, но не wired в UI.
- `character_buildings.level` — колонка есть, по умолчанию 1, никогда не инкрементируется.
- `BuildingFactionMap` смотрит на `level` → даёт +200 endgame очков **за апгрейд**, но триггер не срабатывает (некому инкрементить).

**Что меняем.**
- Action-handler `UpgradeBuildingAction` для показа диалога апгрейда.
- Task-handler `BuildingUpgradeCompletionHandler` (extends `BaseTaskHandler`).
- View: показывать текущий уровень здания в `DetailedBaseInfoAction`.
- Hooked endgame-scoring +200 на каждый успешный апгрейд (Building→Faction map уже есть).

**Файлы к созданию.**
- `app/Controllers/Telegram/Commands/Actions/Camp/Buildings/Upgrades/UpgradeBuildingAction.php`
- `app/TaskHandlers/Built/BuildingUpgradeCompletionHandler.php`
- `tests/unit/TaskHandlers/Built/BuildingUpgradeCompletionHandlerTest.php`

**Файлы к изменению.**
- `app/Controllers/Telegram/Commands/Actions/Camp/DetailedBaseInfoAction.php` — показать `level`
- `app/Config/Tasks.php` — зарегистрировать `buildingUpgrade` task
- `app/Controllers/Telegram/CallbackRouter.php` — `upgradeBuilding_<Key>` callback
- `app/Services/Endgame/EndgameProgressionService.php` — вызов `recordBuildingUpgrade()` (если ещё нет)

**Миграции.**
```
app/Database/Migrations/2026-05-19-100000_AddBuildingUpgradeTaskRow.php
```
Inserts `tasks` row для `buildingUpgrade` (duration 30 min для L2, 60 min для L3 — конкретные значения в `BuildingUpgrades.php`).

**Image-assets.** Нет (на этой сессии — общая инфра; картинки L2/L3 — в S11–S15 per building).

**Validation-card mini.**
- Категория: 🟡 (контент в существующих системах, есть BuildingUpgrades config)
- 10-персон: П5 + (deep building progression), П7 + (новая видимая прогрессия), П6 + (endgame очки за апгрейд → faction race), П2 0 (long task, оффлайн-дружественно)
- Smoke: cutover-обязателен (Telegram smoke); idempotency task-handler'а

**Smoke plan.**
1. testbot: персонажа Андрея довести до L1 Workshop.
2. «База → Workshop → ⬆️ Улучшить» → подтвердить → задача создалась.
3. Дождаться completion (или UPDATE end_time на testbot).
4. `php spark tasks:run` → completion срабатывает → `character_buildings.level = 2`.
5. Повторный `tasks:run` без новой задачи — no-op.
6. Endgame score проверить: `+200` в `faction_endgame_scores` под `farming` (или фракцию здания).

**Tests добавить.**
- `BuildingUpgradeCompletionHandlerTest::testIdempotentCompletion()`
- `BuildingUpgradeCompletionHandlerTest::testLevelIncrement()`
- `BuildingUpgradeCompletionHandlerTest::testEndgameScoreRecorded()`
- `UpgradeBuildingActionTest::testResourcesValidated()`
- `UpgradeBuildingActionTest::testLevelCapL3()` (пока only L2/L3 wired)

**Tech-writing.**
- `mmorpg-vault/tech-writing/handlers/camp/UpgradeBuildingAction.md` — НОВАЯ
- `mmorpg-vault/tech-writing/tasks/built/BuildingUpgradeCompletionHandler.md` — НОВАЯ
- `mmorpg-vault/tech-writing/config/BuildingUpgrades.md` — обновить (snimat «config есть, UI не работает»)

**Прод-тег.** `v0.51.182`.

**Параллелизация.** После S2 (нужен JSON parser для resources в upgrade-cost).

**Зависимости.** S2 (required_resources JSON).

**Анонс.** Да — «Теперь здания можно улучшать! Workshop L2 → +X% эффективности крафта». Драфт: см. §11 S3.

**Замечания.** L4–L10 в `BuildingUpgrades.php` остаются для будущих сессий (S26+). На этой сессии — только L2 и L3 wiring.

> **Статус:** ✅ shipped **v0.51.185** (2026-05-17). Master commit: `1d836b6`. 🔀 **ROADMAP описание устарело**: `UpgradeBuildingAction` + `BuildingUpgradeValidator/Applier/Formatter` + endgame-hook + кнопка «🆙 Поднять уровень» во всех 12 handler'ах — **уже были wired** с v0.51.57–62. Реальный gap — отсутствие level в списке построек `DetailedBaseInfoAction::showBuildings`. Изменение: 1 файл, 8 LOC, добавлен suffix «L{level}» к каждой кнопке. F1.4 Entity narrowing.

---

### S4 — Durability decay handler 🟡

**Цель.** Реализовать handler, декрементирующий `crafted_items.durability_count` при использовании инструмента в gather-задаче. Сейчас колонка `durability_count` существует, но dead — никто не пишет.

**Канон-ссылка.** `GAME_DESCRIPTION.md` § «⛏️ Добыча ресурсов» — «Система прочности — инструменты изнашиваются»; `mmorpg-vault/glossary/Прочность-инструмента.md`.

**Текущая боль.**
- Канон обещает: «инструменты изнашиваются при использовании».
- Реальность: `durability_count` существует, но handler декремента отсутствует. Инструменты вечны. **Lying description** (🔴 категория) → должны починить либо механику, либо канон.
- Решение: реализовать (фикс) — это уже в каноне, ожидаемо игроками.

**Что меняем.**
- Сервис `DurabilityService::decrement(int $characterCraftedItemId, int $amount = 1): void`
- Hook в `gather`/`fish`/`chop` completion-handler'ы — вызов decrement(1) на каждом задействованном инструменте.
- При `durability_count <= 0` — `is_broken = 1`, инструмент не даёт бонус, Telegram-уведомление.

**Файлы к созданию.**
- `app/Services/Crafting/DurabilityService.php`
- `tests/unit/Services/Crafting/DurabilityServiceTest.php`

**Файлы к изменению.**
- `app/TaskHandlers/CompleteGatherResourceHandler.php` — call DurabilityService
- `app/TaskHandlers/CompleteFishingHandler.php` — same
- `app/TaskHandlers/CompleteChopHandler.php` — same
- `app/Services/Player/ToolBonusService.php` — отфильтровать `is_broken = 1`
- `app/Models/CraftedItemsModel.php` — `markBroken()` метод

**Миграции.** Нет (колонки `durability_count`, `is_broken` уже есть).

**Image-assets.**
- LEXICON entry уже есть (`loot.tool`).
- Для «сломанный инструмент» уведомления — добавить LEXICON ключ `loot.tool-broken`:
  - **`tool.broken`** (`uploads/telegram/craft/tool_broken.jpg`): scene-tail: "the same hand-forged tool now visibly broken — the head snapped off a corded handle, the haft split, on a workbench amid wood shavings". Mode: V4. Price: $0.04. Status: pending.

**Validation-card mini.**
- Категория: 🟡 (fix: канон обещал, не было реализовано) — но трогает кор-добычу, поэтому близко к 🟠
- 10-персон: П1 + (риск/реворд), П3 + (текст не врёт), П5 0 (новая зависимость в цепочке), П2 − (наказание за активность) — нужна митигация: декремент **только на successful gather**, при поломке — Telegram-уведомление за 24h до этого
- П7 ✗ предательство? Нет — durability_count уже хранится в БД, игроки видят его в инвентаре.

**Smoke plan.**
1. Testbot: дать персонажу `IronPickaxe` с `durability_count = 3`.
2. Запустить 3 gather-задачи на камень.
3. После 3-й — `is_broken = 1`, Telegram-уведомление получено.
4. 4-я gather-задача — bonus не применяется (durability check).
5. Idempotency: повторный tasks:run не декрементит дважды (флаг `decrement_applied` в task_settings).

**Tests добавить.**
- `DurabilityServiceTest::testDecrementHappyPath()`
- `DurabilityServiceTest::testBreaksAtZero()`
- `DurabilityServiceTest::testBrokenItemNoDecrement()`
- `DurabilityServiceTest::testIdempotentDecrement()` — двойной вызов = одно списание

**Tech-writing.**
- `mmorpg-vault/tech-writing/services/DurabilityService.md` — НОВАЯ
- `mmorpg-vault/glossary/Прочность-инструмента.md` — обновить (status: implemented)
- `GAME_DESCRIPTION.md` — таблица расхождений: убрать запись «durability dead column» если была

**Прод-тег.** `v0.51.183`.

**Параллелизация.** Зависит от S2 (через models).

**Зависимости.** S2.

**Анонс.** Да — «Инструменты теперь действительно изнашиваются — следите за прочностью в инвентаре». Драфт: см. §11 S4.

**Замечания.** Балансовый расчёт: средняя добыча 10 единиц/инструмент/час = 240/день. С durability=100 инструмент живёт ~10ч активной добычи. Это «расход ресурсов на крафт инструментов» → S5 repair сделает альтернативу.

> **Статус:** ✅ shipped **v0.51.186** (2026-05-17). Master commits: `12ff47a` (main) + `2871319` (hotfix). 🔀 **ROADMAP описание устарело**: `ToolManager::updateToolDurability` + `ToolDurabilityProcessor::consumeAndRefresh` (F2.7b, v0.51.107) **уже декрементировали** durability с момента F2.7b. `is_broken` column **никогда не существовала** — игра использует **deletion model** (при `durability=0 AND quantity=1` → row deleted). Реальный gap — **silent disappearance**. Изменение: `ToolDurabilityProcessor::getBrokenTools()` + section «💔 Инструменты сломались» в `GatherMessageFormatter`. Hotfix: русские имена сломанных через merge keys `usedToolsCount` + `brokenTools` перед preload.

---

### S5 — Repair system + **GameSettings admin framework** 🟠

> **🎯 Решение user'а (2026-05-17):** стоимость ремонта = 50%, **но вынесено в админку как настройка**. Эта сессия **дополнительно вводит универсальный admin-tunable settings framework**, на который опираются S26 (defensive damage cap) и S28 (seasonal cadence). Категория повышена 🟡 → 🟠 (требует ADR-024 «Game Settings live-tunable framework»).

**Цель.** (a) Repair-механика: 50% ресурсов, 15 мин, default. (b) Foundation `GameSettings` — DB-backed key/value таблица + service + admin UI `/admin/game-settings`, через которую админ меняет числовые/булевые параметры **без redeploy**.

**Канон-ссылка.** Нет прямого упоминания repair в GAME_DESCRIPTION; **lore tail #N открыт**. GameSettings — инфраструктурное расширение, ADR-024 описывает.

**Текущая боль.**
- После S4 инструменты ломаются → без repair единственный путь = крафт нового по полной цене.
- Сейчас все балансные константы в `Config\GameBalance` — изменение требует deploy. У админа нет рычага «сделать ремонт чуть дешевле на проде после фидбэка».

**Что меняем.**
- **Часть A — GameSettings framework (constitutional foundation, см. CLAUDE.md §🎛️):**
  - Таблица `game_settings` — **расширенная схема с rich rationale** (обязательно по constitutional rule):

    ```sql
    CREATE TABLE game_settings (
        id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        setting_key     VARCHAR(64) NOT NULL UNIQUE,         -- 'category.subcategory.name'
        category        VARCHAR(32) NOT NULL,                -- craft|buildings|resources|combat|world|endgame|experimental
        value_type      ENUM('int','float','bool','string') NOT NULL,
        value_int       INT NULL,
        value_float     DECIMAL(12,4) NULL,
        value_bool      TINYINT(1) NULL,
        value_string    VARCHAR(255) NULL,
        default_value_text  TEXT NOT NULL,                   -- Reset-to-default читает это поле
        rationale_text      TEXT NOT NULL,                   -- ПОЧЕМУ сейчас именно это значение
        effect_text         TEXT NOT NULL,                   -- НА ЧТО влияет (механики/формулы)
        above_effect_text   TEXT NOT NULL,                   -- ЧТО будет если поставить выше
        below_effect_text   TEXT NOT NULL,                   -- ЧТО будет если поставить ниже
        recommended_min     VARCHAR(64) NULL,                -- soft-граница (warning жёлтый в UI)
        recommended_max     VARCHAR(64) NULL,
        hard_min            VARCHAR(64) NULL,                -- save запрещён вне этих границ
        hard_max            VARCHAR(64) NULL,
        updated_at          DATETIME NOT NULL,
        updated_by          VARCHAR(128) NULL,               -- email/name админа из Shield auth
        INDEX idx_category (category)
    );
    ```
  - `App\Services\GameSettings\GameSettingsService::get(string $key, mixed $default): mixed` — кешируется (`spark/cache`, TTL 60s, инвалидация на UPDATE), fallback на `$default`, типизация по `value_type`.
  - `GameSettingsService::all(?string $category = null): array` — для admin UI.
  - `GameSettingsService::reset(string $key): void` — UPDATE value_* ← parsed `default_value_text`, audit-log.
  - **Admin UI** — `/admin/game-settings` (overview всех категорий) + `/admin/game-settings/<category>` (детально по группе):
    - Inline editing с валидацией (тип + hard_min/max + warning при превышении recommended_min/max).
    - **Кнопка «🔄 Сбросить к default» на каждой строке.**
    - Tooltip / expand на каждой строке показывает `rationale_text` / `effect_text` / `above` / `below` — админ читает почему именно так и как менять.
    - Audit-log пишется через `BaseAdminController::audit()` (action `GAME_SETTING_UPDATE`, payload содержит old_value/new_value/key).
  - **Seed-migration с начальным набором (только repair-keys для финала Фазы 1):**
    - `repair.cost_fraction` (float 0.50, category=craft)
    - `repair.task_duration_minutes` (int 15, category=craft)
    - `repair.restore_durability_to_percent` (int 100, category=craft)

  **Пример seed-row** (для `repair.cost_fraction`):

  ```php
  [
      'setting_key' => 'repair.cost_fraction',
      'category' => 'craft',
      'value_type' => 'float',
      'value_float' => 0.50,
      'default_value_text' => '0.50',
      'rationale_text' => 'Половина — компромисс между "ремонт почти бесплатный" (П2 win, П8 sink loss) и "ремонт дешевле полного крафта в 2 раза, но всё ещё ощутимый sink" (баланс economy + dolce vita для casuals).',
      'effect_text' => 'Множитель ресурсов для action `repair` — итоговая стоимость = ceil(original_required_resources × значение). Не влияет на durability/время ремонта.',
      'above_effect_text' => 'При 0.70 ремонт = 70% от полного крафта — sink сохраняется почти полностью, П8 трейдеры довольны, но П2/П5 теряют ощущение "ремонт вместо нового крафта". При 0.90+ — repair становится бессмысленным.',
      'below_effect_text' => 'При 0.30 ремонт почти бесплатный — П1 хардкорщики жалуются на тривиализацию decay system, П8 теряют sink, инфляция золота возрастает. При 0.10 — economy ломается, ремонт превращается в обнуление durability бесплатно.',
      'recommended_min' => '0.30',
      'recommended_max' => '0.70',
      'hard_min' => '0.10',
      'hard_max' => '0.90',
  ]
  ```

  **Это invariant.** Любой seed без полных 4 полей (`rationale_text` / `effect_text` / `above_effect_text` / `below_effect_text`) — fail в миграции (CHECK constraint или валидация в seed).
- **Часть B — Repair-механика:**
  - Action-handler `RepairCraftedItemAction` — выбор сломанного инструмента → подтверждение → задача `repair` (duration = `GameSettings::get('repair.task_duration_minutes', 15)`).
  - Стоимость ремонта: `ceil(required_resources × GameSettings::get('repair.cost_fraction', 0.50))`.
  - Completion-handler `RepairCompletionHandler` — `durability_count = original_durability × repair.restore_durability_to_percent / 100`, `is_broken = 0`. Идемпотентен.

**Файлы к созданию.**
- `app/Models/GameSettingsModel.php`
- `app/Services/GameSettings/GameSettingsService.php`
- `app/Services/GameSettings/GameSettingValidator.php` (типизация + hard/soft bounds)
- `app/Controllers/Admin/GameSettingsController.php` (extends `BaseAdminController`)
- `app/Views/admin/game_settings_index.php` (overview всех категорий — карточки + count)
- `app/Views/admin/game_settings_category.php` (детальная таблица по `<category>`)
- `app/Views/admin/partials/_setting_row.php` (один setting — inline edit + tooltip с rationale)
- `tests/unit/Services/GameSettings/GameSettingsServiceTest.php` (cache invalidation, type coercion, default fallback, reset)
- `tests/unit/Services/GameSettings/GameSettingValidatorTest.php` (bounds, type-coercion)
- `app/Controllers/Telegram/Commands/Actions/Crafting/RepairCraftedItemAction.php`
- `app/TaskHandlers/Craft/RepairCompletionHandler.php`
- `tests/unit/Controllers/Telegram/Actions/Crafting/RepairCraftedItemActionTest.php`
- `tests/unit/TaskHandlers/Craft/RepairCompletionHandlerTest.php`

**Файлы к изменению.**
- `app/Controllers/Telegram/Commands/Actions/CraftedResourcesAction.php` — кнопка «🔧 Ремонт»
- `app/Config/Tasks.php` — task `repairCraftedItem`
- `app/Config/Routes.php` — `/admin/game-settings` + `/admin/game-settings/<category>` + POST `/admin/game-settings/update/<key>` + POST `/admin/game-settings/reset/<key>`
- `app/Views/templates/sidebar.php` — **новый раздел «⚙️ Параметры баланса»** с подпунктами по категориям (см. Admin sidebar map ниже)
- `app/Controllers/Telegram/CallbackRouter.php`

**Миграции.**
```
app/Database/Migrations/2026-05-21-090000_CreateGameSettingsTable.php
app/Database/Migrations/2026-05-21-091000_SeedGameSettingsRepairKeys.php
app/Database/Migrations/2026-05-21-100000_AddRepairTaskRow.php
```

**Admin sidebar map — структурированная навигация (НЕ хаос):**

```
NAVIGATION
├── 📦 Настройки игры                  (существующее)
│   ├── Биомы
│   ├── Ресурсы
│   ├── Задачи
│   ├── События
│   ├── Объекты
│   └── Квесты
├── 🌳 Дерево крафта                   (v0.51.180)
├── ⚙️  Параметры баланса              (НОВОЕ — S5)
│   ├── 🔧 Крафт и ремонт              → /admin/game-settings/craft
│   ├── 🏗 Стройка и постройки         → /admin/game-settings/buildings
│   ├── 💎 Ресурсы и редкость           → /admin/game-settings/resources
│   ├── ⚔ Бой и PvP                    → /admin/game-settings/combat
│   ├── 🌐 Мир и события                → /admin/game-settings/world
│   ├── 🎯 Эндгейм                       → /admin/game-settings/endgame
│   └── 🧪 Экспериментальные            → /admin/game-settings/experimental
├── 💡 Советы в игре
├── 📨 Сообщение всем
├── 🔄 Сброс персонажа
└── 🗳️ Опросы
```

Пустая категория показывается с надписью «Пока нет настроек в этой группе» — категория появляется по мере того как сессии S6-S30 регистрируют ключи. На момент завершения S5 — только category=craft имеет 3 ключа (repair.*).

**Admin UI для одной категории** (пример):

```
┌────────────────────────────────────────────────────────────────────────┐
│ ⚙️  Параметры баланса → 🔧 Крафт и ремонт                                │
├────────────────────────────────────────────────────────────────────────┤
│ Ключ                          │ Значение │ Default │ Действия           │
├────────────────────────────────────────────────────────────────────────┤
│ repair.cost_fraction   ⓘ      │  0.50   │  0.50   │ ✏️ Изменить  🔄    │
│   Доля ресурсов от оригинала                                            │
│   ▶ Развернуть rationale / effect / above / below                       │
│                                                                          │
│ repair.task_duration_minutes ⓘ│  15     │  15     │ ✏️ Изменить  🔄    │
│   Длительность ремонта в минутах                                        │
├────────────────────────────────────────────────────────────────────────┤
│ Когда меняешь значение, audit-log сохраняет кто/когда/что.              │
│ Soft bounds: [0.30 - 0.70] — вне диапазона предупреждение.              │
│ Hard bounds: [0.10 - 0.90] — сохранение запрещено.                      │
└────────────────────────────────────────────────────────────────────────┘
```

**Image-assets.**
- **`craft.repair-in-progress`** (`uploads/telegram/craft/repair_in_progress.jpg`): LEXICON-key `loot.tool` + scene-tail: "a survivor's hands at a workbench bending steel back into shape on the broken tool — a vice, a hammer, ash and metal shavings on the bench; the tool partly disassembled, mid-fix". Mode: V4. Price: $0.04. Status: pending.
- **`craft.repair-done`** (`uploads/telegram/craft/repair_done.jpg`): LEXICON-key `loot.tool` + scene-tail: "the same hand-forged tool re-assembled on the workbench, the handle re-corded, the head re-seated — clearly mended, slightly different from new, ready to use". Mode: V4. Price: $0.04. Status: pending.

**Validation-card mini.**
- Категория: 🟠 (повышено с 🟡 из-за GameSettings framework, ADR-024 нужен)
- 10-персон: П1 + (extends gear lifecycle), П5 ++ (deep cycle), П2 + (cheaper than re-craft), П8 0 (sink осталась, цена 50% default), П10 + (новичок-friendly default 50%)
- Lore-tail: канон надо допилить — `GAME_DESCRIPTION.md` § «Система прочности» — добавить абзац про repair
- **ADR-024 «Game Settings live-tunable framework»** — обязателен; описывает: какие категории ключей (balance/economy/timing/limits), валидация, audit-trail, кеш-инвалидация, fallback-cascade (DB → constant → hardcoded).
- 🟠-risk: админ ставит экстремальное значение (например `repair.cost_fraction = 0.01`) → ломает экономику. Mitigation: per-key min/max bounds в `value_type`-validation + аудит-лог + опция Reset to default.

**Smoke plan.**
1. Testbot: сломать `IronPickaxe` (durability=0).
2. «Крафт → Сломанные инструменты → Ремонт» → подтвердить.
3. Дождаться 15 мин (или UPDATE end_time).
4. `tasks:run` → durability=100, is_broken=0.
5. Idempotent: повторный run = no-op.

**Tests добавить.**
- `RepairCompletionHandlerTest::testRestoresDurability()`
- `RepairCompletionHandlerTest::testIdempotent()`
- `RepairCraftedItemActionTest::testRejectsIfNotBroken()`
- `RepairCraftedItemActionTest::testRejectsInsufficientResources()`

**Tech-writing.**
- `mmorpg-vault/tech-writing/services/GameSettingsService.md` — НОВАЯ (foundation для S26/S28)
- `mmorpg-vault/tech-writing/controllers/GameSettingsController.md` — НОВАЯ
- `mmorpg-vault/tech-writing/models/GameSettingsModel.md` — НОВАЯ
- `mmorpg-vault/tech-writing/handlers/crafting/RepairCraftedItemAction.md` — НОВАЯ
- `mmorpg-vault/tech-writing/tasks/craft/RepairCompletionHandler.md` — НОВАЯ
- `mmorpg-vault/decisions/ADR-024-Game-Settings-live-tunable-framework.md` — НОВАЯ
- `mmorpg-vault/apps/admin/index.md` — добавить пункт меню «Параметры баланса»
- `GAME_DESCRIPTION.md` — добавить «Ремонт сломанного — 50% ресурсов (настраиваемо), 15 мин»

**Прод-тег.** `v0.51.184` (две части возможно через step-теги: `v0.51.184-step-1` GameSettings infra → smoke → `v0.51.184-step-2` Repair-механика → smoke → финальный `v0.51.184`).

**Параллелизация.** После S4.

**Зависимости.** S2, S4.

**Анонс.** Да — «Сломанный инструмент теперь можно починить за половину ресурсов» + (внутренний) «У админа появилась панель live-tuning параметров».

**Замечания.** Финал Фазы 1 — техфундамент готов: JSON, building.level, durability, repair, + GameSettings admin framework как rails для последующих сессий. **S26/S28/S10 будут регистрировать свои ключи в этом фреймворке.**

> **Статус: 🔀 split на S5a + S5b.**
>
> **S5a (foundation)**: ✅ shipped **v0.51.187** (2026-05-17). Master commit: `c97d3d0`. **Таблица `game_settings` + service + admin UI**: rich schema (rationale/effect/above/below NOT NULL constitutional invariant), 3 seed-row'а repair.* с полными rationale, controller с update/reset + audit-log (`GAME_SETTING_UPDATE`/`_RESET`), view с inline edit + tooltip-collapse + warning жёлтым outside recommended, sidebar entry «⚙️ Параметры баланса». **9 unit-тестов**. ADR-024 создан. Smoke 4 flow'а через Chrome MCP. **Foundation для S26/S28/S10 готов.**
>
> **S5b (repair UI)**: ✅ shipped **v0.51.188** (2026-05-17). Master commits: `1b593ee` + `cc41212` + `158dd89` + `090783a` (3 hotfix'а). Полный flow: Инвентарь → 🔨 Крафтовые ресурсы → 🔧 Ремонт инструментов → tool list → Ремонт → confirm (показ стоимости 50%) → tasks:run → durability restored до template max. 3 hotfix'а: (1) id_characters column name; (2) ResourceEntity → array narrowing; (3) fresh CI4 Model instance per loop iteration (builder state не сбрасывается между ->first() в loop'е). 4 unit-теста. Lesson: F1.4 Entity narrowing + CI4 builder quirk — обязательная проверка в loop-lookup'ах.

---

## §5 Фаза 2 (S6–S10): Resource Expansion

### S6 — Missing resources (Ironstone / Oil / Sulfur / RareMetals / Coal) 🟠

**Цель.** Завести в БД 5 ресурсов, упоминаемых в `Config\Buildings.php` (Arsenal, CommunicationTower) и сайт-каноне `wildworld.fun`, но **отсутствующих** в `resources` таблице. Это закрывает lore tail #4 (GAME_DESCRIPTION.md «Расхождения»).

**Канон-ссылка.** `GAME_DESCRIPTION.md` § «⛏️ Добыча ресурсов» — «Базовые материалы»; lore-tail #4.

**Текущая боль.**
- `Config\Buildings.php` требует `Ironstone => 200`, `RareMetals => 60`, `Oil => 70`, `Sulfur => 50` для Arsenal.
- В БД `resources` этих имён нет → Arsenal **недостижим** (П8 трейдер: «не могу построить ничего сверх Workshop»).
- 🔴 lying — рецепт обещает то, что нет в игре.

**Что меняем.**
- Миграция вставляет 5 ресурсов в `resources` таблицу с rarity, base_price, биом-маппингом.
- Биомы-источники: Ironstone → Mountains/Caves; Oil → Deserts/Volcanic; Sulfur → Volcanic; RareMetals → Caves/Volcanic; Coal → Caves/Mountains.

**Файлы к созданию.** Нет (только миграция + LEXICON).

**Файлы к изменению.**
- `app/Config/ImageRegistry.php` — добавить 5 LEXICON-записей (`resource.ironstone`, `resource.oil`, `resource.sulfur`, `resource.rare-metals`, `resource.coal`)

**Миграции.**
```
app/Database/Migrations/2026-05-22-100000_AddMissingMineableResources.php
```
**up:**
```php
public function up(): void
{
    $resources = [
        ['name_eng'=>'Ironstone','name_rus'=>'Железная руда','rarity'=>7,'base_price'=>15,'description'=>'Грубая железная руда. Сырьё для металлофрагментов.'],
        ['name_eng'=>'Oil','name_rus'=>'Сырая нефть','rarity'=>6,'base_price'=>22,'description'=>'Густая чёрная жидкость из недр. Топливо и сырьё.'],
        ['name_eng'=>'Sulfur','name_rus'=>'Сера','rarity'=>5,'base_price'=>28,'description'=>'Жёлтые кристаллы из вулканических жил. Сырьё для боеприпасов.'],
        ['name_eng'=>'RareMetals','name_rus'=>'Редкие металлы','rarity'=>9,'base_price'=>80,'description'=>'Сплавы и фрагменты редких металлов. Высокотехнологичный крафт.'],
        ['name_eng'=>'Coal','name_rus'=>'Уголь','rarity'=>4,'base_price'=>8,'description'=>'Чёрный пласт из карьеров. Топливо для печи.'],
    ];
    foreach ($resources as $r) {
        $exists = $this->db->table('resources')->where('name_eng', $r['name_eng'])->get()->getRow();
        if ($exists) {
            $this->db->table('resources')->where('name_eng', $r['name_eng'])->update($r);
            continue;
        }
        $this->db->table('resources')->insert($r);
    }
    // biome→resource mapping (rates per gather block)
    $biomeMap = [
        'Mountains' => ['Ironstone' => 30, 'Coal' => 25],
        'Caves'     => ['Ironstone' => 25, 'RareMetals' => 5, 'Coal' => 35],
        'Volcanic'  => ['Sulfur' => 25, 'Oil' => 15, 'RareMetals' => 8],
        'Deserts'   => ['Oil' => 18, 'Sulfur' => 8],
    ];
    // ... idempotent UPSERT into biome_resources (table exists)
}
```
**down:** No-op (idempotent UPDATE — повторный run обновляет).

**Image-assets.**
- **`resource.ironstone`** (`uploads/telegram/craft/ironstone.jpg`): LEXICON `resource.junk` + scene-tail: "a heap of raw iron ore — rust-red rocky chunks streaked with darker iron veins, on torn sacking by a workbench". Mode: V1. Price: $0.04.
- **`resource.oil`** (`uploads/telegram/craft/oil.jpg`): LEXICON `resource.junk` + scene-tail: "two scuffed jerrycans of dark crude oil, lids loose, oil staining the wood beneath; one improvised funnel set aside". Mode: V1. Price: $0.04.
- **`resource.sulfur`** (`uploads/telegram/craft/sulfur.jpg`): LEXICON `resource.junk` + scene-tail: "a wooden bowl of bright yellow sulphur crystals, a small pile spilled, picked from volcanic rock; one chunk shows the dull crystalline interior". Mode: V3. Price: $0.04.
- **`resource.rare-metals`** (`uploads/telegram/craft/rare_metals.jpg`): LEXICON `resource.rare` + scene-tail: "a small padded tin holding a few pieces of bright unidentified pre-collapse alloy, set apart from the rust around it, on a workbench". Mode: V1. Price: $0.04.
- **`resource.coal`** (`uploads/telegram/craft/coal.jpg`): LEXICON `resource.junk` + scene-tail: "a sooted basket of jet-black coal chunks beside a pile of similar lumps; the surface dusty, fingers and bench smudged with black". Mode: V1. Price: $0.04.

Subtotal: 5 картинок × $0.04 = **$0.20**.

**Validation-card mini.**
- Категория: 🟠 (новые ресурсы — балансовое решение; требует ADR `ADR-025-Missing-canonical-resources.md`)
- 10-персон: П3 ++ (канон теперь не врёт), П5 ++ (полная цепочка Arsenal достижим), П8 + (новые ценники), П7 + (5 новых achievement-ресурсов)

**Smoke plan.**
1. testbot: `migrate` → 5 ресурсов в БД.
2. Биом Mountains — gather → выпадает Ironstone (вероятность).
3. Биом Volcanic — gather → Sulfur, Oil.
4. Стоимость Arsenal в callback'е реальна (ресурсы существуют).
5. Repeat migrate — no-op (UPSERT-логика).

**Tests добавить.**
- `MissingResourcesMigrationTest::testFiveResourcesInserted()`
- `BiomeResourceMappingTest::testMountainsHasIronstoneAndCoal()`
- `BiomeResourceMappingTest::testVolcanicHasSulfurAndOilAndRareMetals()`

**Tech-writing.**
- `mmorpg-vault/tech-writing/db/resources.md` — обновить
- `mmorpg-vault/lore/world/Биомы.md` — добавить новые ресурсы в таблицу биом→ресурс
- `mmorpg-vault/decisions/ADR-025-Missing-canonical-resources.md` — НОВЫЙ ADR

**Прод-тег.** `v0.51.185`.

**Параллелизация.** После S2 (миграции зависят от JSON формата).

**Зависимости.** S2.

**Анонс.** Да — «5 новых ресурсов теперь добываются в горах, пещерах, пустынях и вулканах. Арсенал стал реально достижим».

**Замечания.** Балансовый риск: новые ресурсы → пик предложения на рынке. S9 (price cron) корректирует.

---

### S7 — Biome↔Resource explicit mapping config 🟡

**Цель.** Перенести implicit hardcoded biome→resource rates из `GatherService` в `Config\BiomeResources` (явный конфиг).

**Канон-ссылка.** `GAME_DESCRIPTION.md` § «⛏️ Добыча ресурсов» — «Уникальные ресурсы — каждый биом содержит специфические материалы»; `mmorpg-vault/lore/world/Биомы.md`.

**Текущая боль.**
- `GatherService::getResourceRates(int $biomeId): array` использует hardcoded match-statement (~150 LOC).
- Любая правка ratenum требует git diff в hot-path коде.
- Невозможно посмотреть «что добывается в биоме N» из админки без чтения исходников.

**Что меняем.**
- `Config\BiomeResources` с массивом `[biome_eng => [resource_eng => weight]]`.
- `GatherService` читает из конфига.
- Admin UI: `/admin/biomes/<id>/resources` — read-only превью.

**Файлы к созданию.**
- `app/Config/BiomeResources.php`
- `app/Controllers/Admin/BiomeResourcesController.php`
- `tests/unit/Config/BiomeResourcesTest.php`

**Файлы к изменению.**
- `app/Services/Player/GatherService.php` — удалить hardcoded match, читать из конфига
- `app/Views/admin/biomes/*` — добавить раздел

**Миграции.** Нет.

**Image-assets.** Нет.

**Validation-card mini.** 🟡 — рефактор + admin расширение.

**Smoke plan.**
1. `composer test` — `GatherServiceTest` зелёный (поведение не изменено).
2. HTTP smoke `curl /admin/biomes/1/resources` → таблица.
3. Telegram smoke: gather в каждом биоме (хотя бы 3) — ресурсы выпадают как раньше.

**Tests добавить.**
- `BiomeResourcesTest::testAllBiomesHaveAtLeastOneResource()`
- `BiomeResourcesTest::testWeightsAreNonNegativeIntegers()`
- `GatherServiceTest::testReadsFromConfig()` — после рефактора

**Tech-writing.**
- `mmorpg-vault/tech-writing/config/BiomeResources.md` — НОВАЯ
- `mmorpg-vault/tech-writing/controllers/BiomeResourcesController.md` — НОВАЯ

**Прод-тег.** `v0.51.186`.

**Зависимости.** S6.

**Анонс.** Не нужен (internal + admin).

---

### S8 — English ingredient cleanup (CraftRecipes) 🟠

**Цель.** Заменить английские имена ингредиентов (`Iron Rods`, `Rubber`, `Gears`, `Wheels`, `Nails`, `Leather`, `Cloth`, `Electronics`, `Batteries`) в `CraftRecipes.php` на существующие ресурсы из БД. Эти recipe-ингредиенты **не существуют** в `resources` → крафты dead.

**Канон-ссылка.** GAME_DESCRIPTION.md (косвенно — крафт «массовое производство»); lore tail (open).

**Текущая боль.**
- Mountain Bike L12, Wooden Fence L15, Light Cart, Portable Radio, Autonomous Drone — все ссылаются на ингредиенты, которых нет в `resources`.
- Игроки видят рецепт в админ-листе, но не могут его сделать («не хватает Iron Rods» — а такого ресурса нет вообще).
- 🔴 lying description.

**Что меняем.**
- Каждый english ingredient mapped на существующий ru-resource:
  - `Iron Rods` → «Железная руда» + «Металлические фрагменты»
  - `Rubber` → «Кора деревьев» (плантация каучуковых деревьев — постапок-натяжка, но укладывается)
  - `Gears` → «Металлические фрагменты»
  - `Wheels` → новый crafted item `WoodenWheel` (или existing `WoodMaterials`)
  - `Nails` → «Металлические фрагменты»
  - `Leather` → «Шкуры животных»
  - `Cloth` → «Ткань» (уже crafted item)
  - `Electronics` → «Электронные компоненты» (уже crafted item)
  - `Batteries` → новый crafted item `Battery` (S9 предложит как content расширение)

**Файлы к созданию.** Нет (только UPDATE config).

**Файлы к изменению.**
- `app/Config/CraftRecipes.php` — заменить ингредиенты во всех recipe entries

**Миграции.**
```
app/Database/Migrations/2026-05-23-100000_FixEnglishIngredientsInCraftedItems.php
```
Idempotent UPDATE на `crafted_items.required_resources` (теперь JSON после S2) — пересохранить с ru-именами.

**Image-assets.** Нет (рецепты уже имели картинки).

**Validation-card mini.** 🟠 — фикс lying descriptions + content rebalance.

**Smoke plan.**
1. testbot: попытаться сделать `Mountain Bike` — рецепт показывает ru-ресурсы, парсит из БД корректно.
2. Если есть ресурсы — создаётся задача, доходит до completion.
3. Repeat migrate — no-op.

**Tests добавить.**
- `CraftRecipesTest::testAllResourcesExistInDb()` — итеративный assertion для каждого рецепта/каждого ингредиента
- `EnglishIngredientCleanupMigrationTest::testIdempotent()`

**Tech-writing.**
- `mmorpg-vault/tech-writing/config/CraftRecipes.md` — обновить, описать миграцию

**Прод-тег.** `v0.51.187`.

**Зависимости.** S2, S6.

**Анонс.** Да — «5 крафтов исправлены: теперь Mountain Bike, Wooden Fence, Light Cart, Portable Radio и Autonomous Drone используют реальные ресурсы. Раньше были недоступны».

---

### S9 — Scheduled price update cron 🟡

**Цель.** Перевести `ResourceModel::updateResourcePrices()` на cron-расписание (раз в час) вместо реактивного вызова на каждом SellAction.

**Канон-ссылка.** `GAME_DESCRIPTION.md` § «💰 Экономическая система» — «Динамические цены».

**Текущая боль.**
- Цены обновляются только при `SellAction`. Если никто не продаёт — цены замороженные.
- Реактивное обновление = N+1 query на каждой продаже.
- Игроки не видят «рыночный пульс».

**Что меняем.**
- Регистрация в `app/Config/Tasks.php` recurring task `updateResourcePrices` (interval 1h).
- Handler `UpdateResourcePricesHandler` (extends `BaseTaskHandler`).
- В `SellAction` — снять inline update (теперь cron).

**Файлы к созданию.**
- `app/TaskHandlers/Economy/UpdateResourcePricesHandler.php`
- `tests/unit/TaskHandlers/Economy/UpdateResourcePricesHandlerTest.php`

**Файлы к изменению.**
- `app/Config/Tasks.php` — recurring task entry
- `app/Controllers/Telegram/Commands/Actions/Market/SellAction.php` — снять inline call

**Миграции.** Нет (task registered в конфиге).

**Validation-card mini.** 🟡 — экономический рефактор, идемпотентный handler.

**Smoke plan.**
1. testbot: `tasks:run` каждый час — цены обновляются.
2. SellAction — цены НЕ обновляются inline, но видны актуальные из БД.
3. Idempotent: 2 запуска в одну минуту — нет двойного обновления.

**Tests добавить.**
- `UpdateResourcePricesHandlerTest::testPriceMovesDownOnOversupply()`
- `UpdateResourcePricesHandlerTest::testPriceMovesUpOnScarcity()`
- `UpdateResourcePricesHandlerTest::testIdempotentWithinTickWindow()`

**Tech-writing.**
- `mmorpg-vault/tech-writing/tasks/economy/UpdateResourcePricesHandler.md` — НОВАЯ
- `mmorpg-vault/tech-writing/models/ResourceModel.md` — обновить (price model now cron)

**Прод-тег.** `v0.51.188`.

**Зависимости.** S6 (новые ресурсы участвуют).

**Анонс.** Да — «Рынок теперь живёт: цены обновляются каждый час даже если никто не продаёт».

---

### S10 — Rare late-game resources 🟡

> **🎯 Решение user'а (2026-05-17):** rare resources имеют **двойной источник** — events/strategic objects **+ rare biome-spots** (low-probability deep-node добыча в специфических биомах). Биом важен.

**Цель.** Добавить 4 редких ресурса для T3 крафта (S16+): Spent Fuel Rods, Pre-Collapse Electronics, Industrial Plastic, Medical Compound.

**Канон-ссылка.** GAME_DESCRIPTION.md § «Редкие компоненты»; lore-доп нужен (новая lore-нота `lore/craft/Редкие-ресурсы.md`).

**Текущая боль.** T3 верстак (S16) нужны редкие материалы, чтобы tier 3 не был «пересказ tier 2 с цифрами повыше».

**Что меняем.**
- 4 новых ресурса rarity 9–10, base_price 100+.
- **Источники (комбо):**
  - **Events:** loot drops с MeteorImpact / NorthernLights / GeothermalActivity (1–3% chance) — главный поток.
  - **Strategic objects** (S21–S25): гарантированные награды при захвате (Pre-Collapse Electronics из Bunker, Industrial Plastic из Technopark).
  - **🆕 Rare biome-spots:** новый mechanism `rare_resource_nodes` — спавн редкого узла в специфическом биоме с тиком в 24-72 ч (cooldown по биом-spot), даёт 1-3 единицы ресурса.
    - Spent Fuel Rods → **Volcanic** биом, под завалами (1% chance per gather в Volcanic с уровнем ≥15).
    - Pre-Collapse Electronics → **Caves** биом (deep cave node, 0.5% chance, требуется L18+).
    - Industrial Plastic → **Tropical Jungles** (под старыми руинами, 1.5% chance, L12+).
    - Medical Compound → **Mountains** биом (заброшенные склады, 1% chance, L15+).
- Биом-spot настройки live-tunable через `GameSettings` (S5 framework): `rare_resource.fuel_rods.spawn_chance`, `rare_resource.fuel_rods.cooldown_hours`, и т.д. Default values как выше.

**Файлы к созданию.**
- `app/Services/Gather/RareNodeService.php` — логика биом-spot спавнов + cooldown tracking.
- `app/TaskHandlers/Gather/RareNodeRollHandler.php` — обработчик roll'а на rare drop в gather completion.
- `tests/unit/Services/Gather/RareNodeServiceTest.php`

**Файлы к изменению.**
- `app/Config/ImageRegistry.php` — 4 LEXICON-записи
- `app/Services/EventService.php` — loot table расширяется
- `app/TaskHandlers/Gather/GatherCompletionHandler.php` — вызов `RareNodeService::rollRareDrop()`
- `app/Models/CharacterGatherHistoryModel.php` (если нет — create) — для cooldown tracking

**Миграции.**
```
app/Database/Migrations/2026-05-25-100000_AddRareLateGameResources.php
app/Database/Migrations/2026-05-25-110000_AddRareResourceSpotsCooldownTable.php
app/Database/Migrations/2026-05-25-120000_SeedRareResourceGameSettings.php
```

**Image-assets.**
- **`resource.fuel-rods`** — LEXICON `resource.rare` + "a wrapped bundle of lead-shielded spent fuel rods on a wheeled cart, a faint warning daub on the side (no text), gloves and tongs laid aside; ominous, precious". Mode: V1. Price: $0.04.
- **`resource.pre-collapse-electronics`** — `resource.rare` + "a pristine circuit board from before the collapse, set on cloth, the lettering on chips visibly etched (but unreadable scrubbed at micro-scale), copper traces clean; museum-piece-on-a-bench". Mode: V4. Price: $0.04.
- **`resource.industrial-plastic`** — `resource.mid` + "stacked sheets and folded rolls of clean dense plastic on a workbench; bright clean against the rust around, clearly pre-collapse manufacture". Mode: V1. Price: $0.04.
- **`resource.medical-compound`** — `resource.rare` + "a sealed glass ampoule of clear medical-grade compound on padded cloth, faint label scuffed to abstract marks, set apart from the dirty bench around". Mode: V4. Price: $0.04.

Subtotal: 4 × $0.04 = **$0.16**.

**Validation-card mini.** 🟡 — content extension, нет балансового сдвига (loot rare).

**Smoke plan.**
1. **Event-source:** Trigger MeteorImpact event на testbot → Spent Fuel Rods может выпасть (1% chance, прогон 200 раз via spark-команда `smoke:meteor-rolls`).
2. **Strategic-source:** Quest StrategicCaptureBunker reward (когда S21 будет live) → гарантированно Pre-Collapse Electronics.
3. **Biome-spot:** SQL UPDATE chars → put char in Volcanic biome, gather 50 раз, проверить что rare drop случился ≥0 (1% = ~0.5 expected). Проверить cooldown: повторный gather на том же spot в течение 24 ч даёт 0 rare drops.
4. **GameSettings tuning:** UPDATE `rare_resource.fuel_rods.spawn_chance` to 100% via admin UI → 100% gather дают drop → revert.

**Tests добавить.**
- `RareResourceLootTableTest::testFuelRodsFromMeteor()`
- `RareResourceLootTableTest::testStrategicQuestRewards()`
- `RareNodeServiceTest::testCooldownPreventsDoubleDrop()`
- `RareNodeServiceTest::testBiomeFilterRespected()`
- `RareNodeServiceTest::testGameSettingsOverride()`

**Tech-writing.**
- `mmorpg-vault/tech-writing/db/resources.md` — обновить
- `mmorpg-vault/lore/craft/Редкие-ресурсы.md` — НОВАЯ нота

**Прод-тег.** `v0.51.189`.

**Зависимости.** S6, S9.

**Анонс.** Да — «Редкие материалы (топливные стержни, до-катастрофная электроника) теперь падают с событий и стратегических объектов».

---

## §6 Фаза 3 (S11–S15): Building progression upgrades

### S11 — Workshop L2 + L3 🟡

**Цель.** Активировать L2 и L3 апгрейды Мастерской: L2 даёт +10% скорости общего крафта, L3 — +25%.

**Канон-ссылка.** `GAME_DESCRIPTION.md` § «🔧 Мастерская — базовый крафт»; `mmorpg-vault/lore/base/Постройки.md`.

**Текущая боль.** S3 wired generic handler; на этой сессии — конкретные эффекты для Workshop.

**Что меняем.**
- `BuildingUpgrades.php` → Workshop L2 и L3 cost + effect.
- `CraftService::getCraftTime()` — учесть workshop level multiplier.

**Файлы к созданию.** Нет.

**Файлы к изменению.**
- `app/Config/BuildingUpgrades.php` — Workshop entry
- `app/Services/CraftService.php`

**Миграции.**
```
app/Database/Migrations/2026-05-26-100000_AddWorkshopLevelsConfig.php
```
(пишет дефолтные `building_upgrades` row для всех 358 chars — idempotent, level=1 baseline).

**Image-assets.**
- **`building.workshop.l2`** (`uploads/telegram/camp/workshop_l2.png`): LEXICON `building.workshop` + "the workshop visibly expanded — a longer roof, a second workbench, more tools mounted on a board, racks of sorted parts; clearly an upgraded version of the same shed". Mode: V1. Price: $0.04. Iterations: 2 (×$0.08).
- **`building.workshop.l3`** (`uploads/telegram/camp/workshop_l3.png`): LEXICON `building.workshop` + "the workshop now a sprawling hand-built complex — three workbenches under a long sheet roof, a small forge in one corner, racks heavy with tools, an apprentice at a vice; expanded, productive, prosperous-for-postapoc". Mode: V1. Price: $0.04. Iterations: 2.

Subtotal: 4 generations × $0.04 = **$0.16**.

**Validation-card mini.** 🟡 — content (apgrade contract в каноне).

**Smoke plan.**
1. testbot: апгрейд Workshop до L2 — endgame score +200 farming faction.
2. Краф Bandage — на L1 = 15min, на L2 = 13.5min (90%).
3. Апгрейд до L3 — Bandage = 11.25min (75%).

**Tests добавить.**
- `WorkshopLevelEffectTest::testCraftTimeMultiplierL2()`
- `WorkshopLevelEffectTest::testCraftTimeMultiplierL3()`

**Tech-writing.**
- `mmorpg-vault/tech-writing/config/BuildingUpgrades.md` — обновить
- `mmorpg-vault/lore/base/Постройки.md` — добавить уровни

**Прод-тег.** `v0.51.190`.

**Зависимости.** S3.

**Анонс.** Да — «Мастерская теперь улучшается: L2 = крафт на 10% быстрее, L3 = на 25%».

---

### S12 — BlastFurnace L2 + L3 🟡

**Цель.** L2 = выход переплавки +15% (metal fragments per ironstone), L3 = +35%.

**Канон-ссылка.** `GAME_DESCRIPTION.md` § «🔥 Доменная печь — переработка металлов».

**Текущая боль.** До S6 у BlastFurnace не было сырья (Ironstone отсутствовал). После S6 — есть; теперь нужна прогрессия.

**Что меняем.**
- `BuildingUpgrades.php` → BlastFurnace L2/L3
- `CraftService` для рецептов, требующих BlastFurnace — multiplier на output

**Файлы к изменению.**
- `app/Config/BuildingUpgrades.php`
- `app/Services/CraftService.php` (или `MetalFragmentService` если выделен)

**Миграции.** Аналогично S11 (idempotent UPSERT building_levels).

**Image-assets.**
- **`building.blast-furnace.l2`** — LEXICON `building.blast-furnace` + "the smelting furnace now twin-mouthed — two stoke holes, twin bellows, doubled slag heap, a second worker tending; brighter glow, more smoke". Mode: V3 (тёплое освещение). Price: $0.04. Iterations: 2.
- **`building.blast-furnace.l3`** — `building.blast-furnace` + "a small forge complex — three furnaces in a row, a covered cooling rack of ingots, a water trough, soot blackened ground; an industrial mini-yard built from scrap". Mode: V3. Price: $0.04. Iterations: 2.

Subtotal: **$0.16**.

**Validation-card mini.** 🟡

**Smoke plan.** Аналогично S11 — апгрейд → переплавка Ironstone в metalFragments → output растёт.

**Tests.** `BlastFurnaceLevelTest::testSmeltingOutputMultiplierL2/L3()`.

**Tech-writing.** Обновить.

**Прод-тег.** `v0.51.191`.

**Зависимости.** S3, S6, S11.

**Анонс.** Да — «Доменная печь L2/L3: переплавка железной руды эффективнее».

---

### S13 — Laboratory + Greenhouse L2/L3 ⚡ 🟡

**Цель.** Лаборатория L2/L3 = быстрее медицинский крафт (−10%/−25%); Теплица L2/L3 = больше еды (+20%/+45%).

**Канон-ссылка.** GAME_DESCRIPTION.md «🥼 Лаборатория», «🌱 Теплица».

**Что меняем.**
- `BuildingUpgrades.php` — два entry
- `CraftService` — multiplier для medical recipes (Laboratory)
- `GreenhouseProductionHandler` — yield multiplier

**Image-assets.**
- **`building.laboratory.l2`** — `building.laboratory` + "the lab corner expanded — two benches, a wider tarp roof, a methodical row of drying racks with herbs hung upside down, a hand-cranked centrifuge". Mode: V1. Price: $0.04.
- **`building.laboratory.l3`** — `building.laboratory` + "a proper hand-built lab tent — partitioned, glass-fronted, a vacuum hand pump, a small still, a survivor in an improvised apron mixing; serious chemistry". Mode: V1. Price: $0.04.
- **`building.greenhouse.l2`** — `building.greenhouse` + "the polytunnel doubled in length — twin tunnels under cloudy plastic, rows of bigger plants, a hand-cranked irrigation pump, a survivor weeding". Mode: V1. Price: $0.04.
- **`building.greenhouse.l3`** — `building.greenhouse` + "a small greenhouse complex — three tunnels, raised beds, a hand-cranked water mill, baskets of harvested produce stacked outside; productive". Mode: V1. Price: $0.04.

Subtotal: 4 × $0.04 (×2 iterations) = **$0.32**.

**Validation-card mini.** 🟡

**Smoke / Tests / Tech-writing.** Аналогично S11/S12.

**Прод-тег.** `v0.51.192`.

**⚡ Параллель** с S12 — независимые здания.

**Зависимости.** S3.

**Анонс.** Да (комплексный «Лаборатория и теплица улучшаются»).

---

### S14 — Robotics Workshop L2/L3 + Robot T2/T3 🟠

**Цель.** Roboticum L2/L3 = быстрее крафт роботов (−15%/−30%), плюс открывает Robot T2 (durability ×2) и T3 (durability ×3 + faster).

**Канон-ссылка.** `GAME_DESCRIPTION.md` § «🤖 Система роботов»; `mmorpg-vault/lore/base/Роботы.md`.

**Текущая боль.** Каноном описано «Время работы: 6h × уровень роб. мастерской» — но фактически работает только level=1. Нет T2/T3 робота-варианта.

**Что меняем.**
- `BuildingUpgrades.php` — RoboticsWorkshop entry
- 2 новых крафт-рецепта: `RobotExplorerT2`, `RobotGathererT2`, и T3
- 4 новых task-handler completion'ов

**Файлы к созданию.**
- `app/TaskHandlers/Craft/CraftCompletionRobotExplorerT2Handler.php`
- `app/TaskHandlers/Craft/CraftCompletionRobotExplorerT3Handler.php`
- `app/TaskHandlers/Craft/CraftCompletionRobotGathererT2Handler.php`
- `app/TaskHandlers/Craft/CraftCompletionRobotGathererT3Handler.php`

**Миграции.** `2026-05-29-100000_AddRobotT2T3CraftedItems.php`.

**Image-assets.**
- **`building.robotics-workshop.l2`** — `building.robotics-workshop` + "the robotics workbench widened — twin clamping stands, two half-built robots in progress, a wall of organised parts bins". Mode: V1. Price: $0.04 × 2 iter.
- **`building.robotics-workshop.l3`** — `building.robotics-workshop` + "a small robotics yard — three workbenches, a finished gatherer being tested on a treadmill, a wall of robot heads on hooks, sparks flying from welding". Mode: V1. Price: $0.04 × 2.
- **`robot.explorer.t2`** — `robot.explorer` + "a sturdier mid-tier scout robot — bigger treads, a thicker chassis, two camera-eye lenses, taller antenna with a small dish; still scrap-built but clearly better engineered than the T1". Mode: V4. Price: $0.04.
- **`robot.explorer.t3`** — `robot.explorer` + "an advanced scout robot — twin antenna, a small jury-rigged solar panel on top, articulated leg-tread hybrids, a tool arm folded against its body; clearly the best a scrap shop can build". Mode: V4. Price: $0.04.
- **`robot.gatherer.t2`** — `robot.gatherer` + "a sturdier worker robot — bigger scoop, reinforced frame, twin sacks slung on each side, lower-and-wider stance". Mode: V4. Price: $0.04.
- **`robot.gatherer.t3`** — `robot.gatherer` + "an advanced worker robot — articulated multi-tool arm, treads with cleats, a hopper instead of a sack, a small steam vent on top". Mode: V4. Price: $0.04.

Subtotal: 8 generations × $0.04 = **$0.32**.

**Validation-card mini.** 🟠 — новые крафт-предметы влияют на gather/explore баланс.

**Smoke plan.**
1. testbot: апгрейд Robotics до L2.
2. Крафт `RobotExplorerT2` — требует L2 робомастерской → доступен.
3. Запуск T2-исследователя — durability 2× больше.

**Tests добавить.**
- `RobotT2T3CraftTest::testT2RequiresLevel2()`
- `RobotT2T3CraftTest::testT3RequiresLevel3()`
- `RobotExplorerT2DurabilityTest::testDoubleDurability()`

**Tech-writing.**
- `mmorpg-vault/tech-writing/tasks/craft/CraftCompletionRobotExplorerT2Handler.md` — НОВАЯ (и 3 другие)
- `mmorpg-vault/lore/base/Роботы.md` — добавить тиры

**Прод-тег.** `v0.51.193`.

**Зависимости.** S3, S4 (durability), S6, S10.

**Анонс.** Да — «Робомастерская теперь улучшается до L3. Роботы T2 и T3 живут дольше и работают эффективнее».

---

### S15 — TeleportationCenter L2/L3 + Beacon network 🟡

**Цель.** Центр L2/L3 = больше доступных маяков одновременно (5/8 от base 3), снижение стоимости телепортации.

**Канон-ссылка.** `GAME_DESCRIPTION.md` § «🌀 Центр телепортации»; `mmorpg-vault/lore/teleport/`.

**Что меняем.**
- `BuildingUpgrades.php` — TeleportationCenter entry
- `BeaconService::getMaxBeacons(int $teleportLevel): int`

**Image-assets.**
- **`building.teleport-center.l2`** — `building.teleport-center` + "the teleport platform stabilised — sandbagged edges, additional copper coils, twin power feeds from battery banks, faint steadier blue arc-light". Mode: V1. Price: $0.04 × 2.
- **`building.teleport-center.l3`** — `building.teleport-center` + "a refined teleport rig — wider platform, mast-mounted ozone vent, banks of capacitors, a controlled steady glow; the most polished scrap-tech on the camp". Mode: V1. Price: $0.04 × 2.

Subtotal: **$0.16**.

**Validation-card mini.** 🟡

**Smoke / Tests / Tech-writing.** Standard.

**Прод-тег.** `v0.51.194`.

**Зависимости.** S3, S11 (apgrade pattern).

**Анонс.** Да — «Центр телепортации L2/L3: больше маяков одновременно, дешевле прыжки».

---

## §7 Фаза 4 (S16–S20): Craft depth — Tier 3

### S16 — Tier 3 Professional Workbench (ADR-026) 🟠

> **🎯 Решение user'а (2026-05-17):** требование уровня персонажа = **L20** (повышено с L16). Это даёт более жёсткий gate-перед T3, защищает прогрессию от skip'а через карты/события, и оправдывает раздел L17-L19 progression-контентом из Фазы 3 (building upgrades L2/L3).

**Цель.** Ввести 3-й тир верстака — **Professional Workbench**, локация для редких крафтов. Это **расширение канона** (канон молчит про T3) — требует ADR.

**Канон-ссылка.** GAME_DESCRIPTION.md § «🔨 Система крафта» — «двухуровневая система». T3 — расширение.

**Текущая боль.**
- Канон молчит про T3 (резерв).
- Игра упирается в потолок T2: L13–L20 progression gap (8 уровней без новых рецептов, после Фазы 3 — закрыт частично через apgrades).
- П5 (Билдер) — «после T2 — пустота».

**Что меняем.**
- Новый crafted item `ProfessionalWorkbench` (тип `workbench`).
- **Требует: уровень персонажа L20**, BlastFurnace L3, Lab L3, RareMetals ×30, Pre-Collapse Electronics ×3, Industrial Plastic ×5, Wiring ×20.
- Crafting time: 8 hours (Standard tier времени).
- `Config\CraftRecipes` → entry `ProfessionalWorkbench`.
- GameSettings (S5 framework): ключ `tier3.workbench.character_level_required` = 20 (default, tunable). Дополнительно `tier3.workbench.craft_duration_hours` = 8.

**Файлы к созданию.**
- `app/TaskHandlers/Craft/CraftCompletionProfessionalWorkbenchHandler.php`
- `tests/unit/TaskHandlers/Craft/CraftCompletionProfessionalWorkbenchHandlerTest.php`
- `mmorpg-vault/decisions/ADR-026-Tier3-Professional-Workbench.md` — **НОВЫЙ ADR**

**Миграции.** `2026-05-31-100000_AddProfessionalWorkbenchCraftedItem.php`.

**Image-assets.**
- **`craft.professional-workbench`** (`uploads/telegram/craft/professional_workbench.jpg`): scene-tail: "a heavy hand-built workbench — twin vices, a small forge attached, a wall of tool-hooks bristling with hand-forged precision tools, a stained tarp roof; this is where the master-tier work gets done". Mode: V1. Price: $0.04 × 3 iter (важный hero-объект).

Subtotal: **$0.12**.

**Validation-card mini.** 🟠 — расширение канона (требует ADR-026). См. полную карточку §11 S16.

**Smoke plan.**
1. testbot: персонаж L16, все требования.
2. Крафт T3 верстака — 6h задача.
3. После завершения — T3 рецепты unlocked.

**Tests.** `ProfessionalWorkbenchTest::testRequiresL16AndBuildings()`, `testUnlocksT3Recipes()`.

**Tech-writing.**
- ADR-026
- `mmorpg-vault/tech-writing/tasks/craft/CraftCompletionProfessionalWorkbenchHandler.md`
- `mmorpg-vault/lore/craft/Крафт-Workbench-Professional.md` — НОВАЯ нота канона

**Прод-тег.** `v0.51.195`.

**Зависимости.** S6, S10, S11, S12, S13.

**Анонс.** Да — «Профессиональный верстак: новый уровень мастерства. Требует уровень 16 и развитую базу».

---

### S17 — T3 weapons (5 recipes) ⚡ 🟡

**Цель.** 5 T3 рецептов оружия: `MasterCrossbow` (мастер-арбалет), `HeavyRebar` (тяжёлая арматура), `PipeShotgun` (трубчатый дробовик), `SteelMachete` (сталь-мачете), `ScrapRifle` (самопальная винтовка).

**Канон-ссылка.** `GAME_DESCRIPTION.md` § «Продвинутый крафт — Оружие».

**Файлы к созданию.**
- 5 new task-handlers `app/TaskHandlers/Craft/WorkbenchStandard/Weapons/T3/*`

**Файлы к изменению.**
- `app/Config/CraftRecipes.php` — 5 новых entry
- `app/Models/WeaponModel.php` — может быть data-only через миграцию

**Миграции.** `2026-06-02-100000_AddT3Weapons.php` — INSERT 5 oruzhia (weapons + crafted_items rows).

**Image-assets.** 5 weapon-картинок (LEXICON `loot.epic-weapon`):
- `craft.master-crossbow` (V4, $0.04 × 2)
- `craft.heavy-rebar` (V4, $0.04 × 2)
- `craft.pipe-shotgun` (V4, $0.04 × 2)
- `craft.steel-machete` (V4, $0.04 × 2)
- `craft.scrap-rifle` (V4, $0.04 × 2)

Subtotal: 10 generations × $0.04 = **$0.40**.

**Validation-card mini.** 🟡 (content в существующем тире T3, ADR-026 уже создан в S16).

**Smoke plan.** Standard — craft каждого на testbot, проверить, что появляется в инвентаре, equippable, damage values корректны.

**Tests.** 5 craft-tests + 5 weapon-damage-tests.

**Tech-writing.** Нота на каждое оружие в `mmorpg-vault/tech-writing/tasks/craft/`.

**Прод-тег.** `v0.51.196`.

**⚡ Параллель** с S18 (T3 armor).

**Зависимости.** S16.

**Анонс.** Да — «5 новых видов оружия Tier 3 доступны на профессиональном верстаке».

---

### S18 — T3 armor (4 recipes) ⚡ 🟡

**Цель.** 4 T3 брони: `PlatedArmor` (бронепластины), `KevlarPatchwork` (кевларовая лоскутка), `TacticalVest` (тактический жилет), `HardenedHelmet` (укреплённый шлем).

**Канон.** GAME_DESCRIPTION.md «Броня».

**Файлы.** Аналогично S17 (4 handler'а в `Craft/WorkbenchStandard/Armor/T3/`).

**Миграции.** `2026-06-03-100000_AddT3Armor.php`.

**Image-assets.** 4 × LEXICON `loot.armor` mode V4 × 2 iter = **$0.32**.

**Smoke / Tests / Tech-writing.** Standard.

**Прод-тег.** `v0.51.197`.

**⚡ Параллель** с S17.

**Зависимости.** S16.

**Анонс.** Объединить с S17 в один анонс «Tier 3: 5 оружий + 4 брони».

---

### S19 — T3 medical (3 recipes) ⚡ 🟡

**Цель.** 3 T3 medical: `SyntheticMedicine` (синтетическое лекарство — закрывает любую болезнь), `EmergencyTransfusion` (экстренное переливание +80HP), `SurgicalKit` (хирургический набор — multi-use).

**Канон.** GAME_DESCRIPTION.md «Медицинские предметы».

**Файлы.** 3 handler'а + миграция.

**Image-assets.** 3 × LEXICON `loot.medicine` mode V4 × 2 iter = **$0.24**.

**Smoke / Tests.** Standard + integration с EventService (epidemic, fever).

**Прод-тег.** `v0.51.198`.

**⚡ Параллель** с S20.

**Зависимости.** S16.

**Анонс.** Объединить с S20.

---

### S20 — T3 utility (3 recipes) ⚡ 🟡

**Цель.** 3 T3 utility: `CombinationTool` (комбо-инструмент: пика+топор+лопата в одном, durability shared), `PortableForge` (переносная кузница — repair anywhere), `AdvancedFishingNet` (продвинутая рыболовная сеть).

**Канон.** GAME_DESCRIPTION.md «Инструменты».

**Файлы.** 3 handler'а + миграция.

**Image-assets.** 3 × LEXICON `loot.tool` mode V4 × 2 iter = **$0.24**.

**Smoke / Tests / Tech-writing.** Standard.

**Прод-тег.** `v0.51.199`.

**Зависимости.** S16.

**Анонс.** «Tier 3: 3 медицинских + 3 утилит».

---

## §8 Фаза 5 (S21–S25): Endgame faction content

### S21 — Bunker (Military) + quest StrategicCaptureBunker + ADR-027 🟠

**Цель.** Реализовать **Bunker как открываемый объект на карте** (а не только quest-trigger). Закрывает lore tail #3.

**Канон-ссылка.** `GAME_DESCRIPTION.md` § «🌍 Финальные сценарии — Военное господство»; `mmorpg-vault/lore/endgame/Endgame-scenarios.md`.

**Текущая боль.**
- Bunker упоминается как +500 endgame очков для Military (v0.51.116-117), но **объект на карте не реализован** — quest auto-completes по name, никакого реального discovery flow нет.
- П3 Лорщик: «канон обещал стратегические объекты — где они?».

**Что меняем.**
- Миграция вставляет 1 Bunker world_object на координаты в Volcanic biome (rare spawn, 1 per server).
- Action-handler `DiscoverBunkerAction` — при наступлении на клетку (через ExploreCellAction handler).
- Visual marker на карте (зелёная пиктограмма).
- Quest StrategicCaptureBunker уже реализован (v0.51.117) — теперь триггерится реально.

**Файлы к созданию.**
- `app/Controllers/Telegram/Commands/Actions/World/DiscoverStrategicObjectAction.php` (универсальный для S21–S24)
- `app/TaskHandlers/StrategicObjects/BunkerCaptureHandler.php`
- `mmorpg-vault/decisions/ADR-027-Strategic-objects-implementation.md`

**Файлы к изменению.**
- `app/Services/World/ObjectDiscoveryService.php`
- `app/Models/WorldObjectModel.php`

**Миграции.** `2026-06-08-100000_AddBunkerWorldObject.php`.

**Image-assets.**
- **`world.bunker`** (`uploads/telegram/world/bunker.jpg`): scene-tail: "a half-buried concrete bunker dug into a volcanic ridge — a steel blast door pitted with rust, ash heaped against the base, a faded warning mark scratched into the steel (no letters), the silhouette of an antenna up top". Mode: V2. Price: $0.04 × 3 iter = $0.12.
- **`world.bunker.discovered`** (`uploads/telegram/world/bunker_discovered.jpg`): "the same bunker with the blast door now cracked open — a slice of dark interior visible, a cracked lamp inside, a survivor about to step in; tense, unknown". Mode: V2. Price: $0.04 × 2 = $0.08.

Subtotal: **$0.20**.

**Validation-card mini.** 🟠 — endgame mechanic (требует ADR-027), 1:1 mapping Bunker→Military не нарушается.

**Smoke plan.**
1. testbot: handler-spawn Bunker на известную клетку.
2. Двинуть Андрея на клетку — должен пройти discovery flow.
3. `+500` endgame score → Military faction.
4. Если у игрока quest StrategicCaptureBunker — `+100` (quest completion) + 5000 gold.
5. Idempotent: повторное наступание = no re-trigger.

**Tests.**
- `DiscoverBunkerActionTest::testDiscoveryFlow()`
- `DiscoverBunkerActionTest::testIdempotentDiscovery()`
- `EndgameBunkerScoreTest::testFiveHundredPointsToMilitary()`

**Tech-writing.**
- ADR-027
- `mmorpg-vault/tech-writing/handlers/world/DiscoverStrategicObjectAction.md`
- `mmorpg-vault/lore/endgame/Endgame-scenarios.md` — обновить (objects now real)

**Прод-тег.** `v0.51.200`.

**Зависимости.** S6, S10, S16.

**Анонс.** Да — большой анонс: «**Бункер** теперь существует на карте. Найди — и Военные получат +500 очков к финалу».

---

### S22 — Technopark (Engineers) ⚡ 🟠

**Цель.** Аналогично S21 — реализовать Technopark в Mountains/Caves biome.

**Канон.** `mmorpg-vault/lore/endgame/Endgame-scenarios.md` — Engineers→Technopark→Scientific Breakthrough.

**Файлы / миграция / handler.** Шаблон S21.

**Image-assets.**
- **`world.technopark`** (`uploads/telegram/world/technopark.jpg`): "a half-collapsed pre-collapse research compound clinging to a mountainside — broken concrete pillars, dish antennas tipped over, solar panels half-buried; eerie silent technology". Mode: V2. Price: $0.04 × 3 = $0.12.
- **`world.technopark.discovered`** — V2 × 2 = $0.08.

Subtotal: **$0.20**.

**Прод-тег.** `v0.51.201`.

**⚡ Параллель** с S23.

**Зависимости.** S21 (общий handler infrastructure).

**Анонс.** «Технопарк появился в горах».

---

### S23 — GhostCity (Partisans) ⚡ 🟠

**Цель.** GhostCity в Fields/Jungles.

**Канон.** `Partisans→GhostCity→Anarchy`.

**Image-assets.**
- **`world.ghost-city`** — "the silhouette of an abandoned pre-collapse small town visible at distance — empty windows, leaning walls, vegetation reclaiming streets, smoke rising from one chimney suggesting hidden survivors; eerie partisan territory". Mode: V2. Price: $0.04 × 3 = $0.12.
- **`world.ghost-city.discovered`** — V2 × 2 = $0.08.

Subtotal: **$0.20**.

**Прод-тег.** `v0.51.202`.

**⚡ Параллель** с S22.

**Зависимости.** S21.

**Анонс.** «Город-призрак найден».

---

### S24 — Island-Farm (Farmers) ⚡ 🟠

**Цель.** Стратегический объект для Фермеров — древняя плодородная ферма (Island-Farm) в Fields. Реализует quest `FarmersHarvest` через discovery.

**Канон.** Не указано в lore — этой сессией добавляется. ADR-028 (Farmers strategic object).

**Image-assets.**
- **`world.island-farm`** — "a forgotten pre-collapse farmstead in tall grass — barns with sun-bleached wood, an empty silo, fruit trees still bearing, a working windmill; calm productive ruin". Mode: V3. Price: $0.04 × 3 = $0.12.
- **`world.island-farm.discovered`** — V3 × 2 = $0.08.

Subtotal: **$0.20**.

**Прод-тег.** `v0.51.203`.

**⚡ Параллель** с S25.

**Зависимости.** S21.

**Анонс.** «Старая ферма найдена в полях — Фермеры получают +500».

---

### S25 — Faction-unique weapons (×4) ⚡ 🟠

**Цель.** 4 faction-exclusive weapons (1 per фракция), доступны только после quest faction-strategic-capture:
- Military: `BunkerRifle` (после Bunker capture)
- Engineers: `TechnoBeamShotgun` (после Technopark — старый pre-collapse coilgun)
- Partisans: `GhostCityKnife` (после GhostCity — нож контрабандистов)
- Farmers: `FarmersHarvestScythe` (после Island-Farm)

**Канон.** Лор-расширение (требует ADR-029).

**Файлы / handlers.** 4 craft-completion handler'а в `Craft/WorkbenchStandard/Weapons/Faction/`.

**Миграция.** `2026-06-12-100000_AddFactionExclusiveWeapons.php`.

**Image-assets.** 4 weapons × LEXICON `loot.epic-weapon` mode V4 × 2 iter = $0.32. Subtotal: **$0.32**.

**Validation-card mini.** 🟠 — faction-exclusive content (требует ADR-029 для fairness audit).

**Smoke plan.**
1. testbot: завершить Bunker quest → unlock `BunkerRifle` рецепт.
2. Crafted → больший урон чем T3 generic crossbow (ниша).
3. Без quest completion — рецепт hidden.

**Tests.** `FactionWeaponLockTest::testRecipeHiddenWithoutQuest()` × 4.

**Прод-тег.** `v0.51.204`.

**Зависимости.** S16, S21, S22, S23, S24.

**Анонс.** Большой эндгейм-анонс: «Каждая фракция получила своё уникальное оружие. Только тех, кто захватил свой стратегический объект».

---

## §9 Фаза 6 (S26–S30): Polish + ROADMAP-vNext

### S26 — Defensive structures (walls / traps / towers) 🟠

> **🎯 Решение user'а (2026-05-17):** конкретные числа damage cap — на моё усмотрение, **все balanced parameters вынесены в админку через `GameSettings` (S5 framework)**. Default-ы зашиты как safe baseline, админ может live-tune.

**Цель.** Закрыть P4 (PvP) голос — defensive base structures: `WoodenWall` (деревянная стена, base damage reduction), `BarbedFence` (колючая ограда, slow-down + minor damage), `WatchTower` (наблюдательная вышка, alert + range).

**Канон-ссылка.** GAME_DESCRIPTION.md § «🏗️ Строительство баз» — нет такого раздела; **лор-расширение** (требует ADR-030).

**Текущая боль.** PvP-хищники могут просто прийти и забрать. Сейчас нет defensive infrastructure.

**Что меняем.**
- 3 новых типа building (walls/fence/tower). Не отдельные `building_type`, а сущность `defensive_structure`.
- При PvP-нападении на базу — структуры активируются.
- **Default mechanics (live-tunable через `GameSettings`):**

| Структура | Параметр (GameSettings key) | Default | Тип |
|---|---|---|---|
| WoodenWall | `defense.wall.damage_reduction_percent` | 15 | int (0-50) |
| WoodenWall | `defense.wall.hp` | 200 | int |
| WoodenWall | `defense.wall.daily_tax` | 200 | int |
| BarbedFence | `defense.fence.attacker_damage_per_round` | 3 | int (0-20) |
| BarbedFence | `defense.fence.attacker_slowdown_percent` | 10 | int (0-50) |
| BarbedFence | `defense.fence.hp` | 80 | int |
| BarbedFence | `defense.fence.daily_tax` | 350 | int |
| WatchTower | `defense.tower.alert_range_cells` | 5 | int (1-15) |
| WatchTower | `defense.tower.defender_initiative_bonus_percent` | 8 | int (0-30) |
| WatchTower | `defense.tower.hp` | 300 | int |
| WatchTower | `defense.tower.daily_tax` | 700 | int |
| Global cap | `defense.total_damage_reduction_max_percent` | 40 | int (0-70) — комбинация всех структур не превышает |

- Decay: defensive structures имеют `current_durability` (от S4) — каждое отбитое нападение тратит 10 HP стены/башни; сломанная структура НЕ активна до repair (S5).

**Файлы / handlers.**
- `app/Controllers/Telegram/Commands/Actions/Camp/BuildDefensiveStructureAction.php` (3 варианта через `building_type=defensive_structure`).
- `app/Services/Pvp/DefenseStructureService.php` — calc reduction/initiative/alerts.
- `app/Services/Pvp/AttackPlayerAction.php` — integration с defense service (мутирует damage round-by-round).

**Миграция.** `2026-06-13-100000_AddDefensiveStructures.php` + `2026-06-13-110000_SeedDefenseGameSettings.php`.

**Image-assets.** 3 структуры × $0.04 × 2 итерации = $0.24. Subtotal: **$0.24**.

**Validation-card mini.** 🟠 — балансовое — boosts defender (П4 неоднозначно: за / против; mitigation = админ может крутить через GameSettings). См. полную карточку §11 S26.

**Smoke / Tests.**
- PvP damage formula integration test — defender с walls берёт 15% меньше урона.
- Admin smoke: меняет `defense.wall.damage_reduction_percent` через UI → следующий бой использует новое значение → revert.
- Decay smoke: после 10 нападений wall HP = 100 → ещё 10 → HP = 0 → следующее нападение игнорирует wall (сломана).

**Прод-тег.** `v0.51.205`.

**Зависимости.** S2 (resources), S3 (level up infrastructure), **S5 (GameSettings framework)**, S4 (durability), S5 (repair).

**Анонс.** Да, большой — «Защита базы: стены, колючка, башня. Параметры тюнятся админом, баланс будем шлифовать с фидбэком».

---

### S27 — Craft queue UI visualization 🟡

**Цель.** Закрыть техдолг craft-queue (v0.51.129) — UI визуализация: «у тебя в очереди X крафтов, ближайший через Y минут».

**Канон.** Уже реализована queue в коде; пока без UI.

**Что меняем.**
- View `app/Views/telegram/craft/queue.php`.
- Action-handler `ShowCraftQueueAction`.

**Files.** Standard.

**Image-assets.** Нет (UI-сообщение, без hero-картинки).

**Smoke / Tests.** HTTP smoke `curl /telegram/craft/queue` (если есть веб-vue), Telegram smoke.

**Прод-тег.** `v0.51.206`.

**Зависимости.** Нет.

**Анонс.** Да — «Очередь крафта видно».

---

### S28 — Seasonal craft rotation 🟠

> **🎯 Решение user'а (2026-05-17):** cadence на моё усмотрение, **вынесено в админку через `GameSettings`**. Default: **4 сезона × 21 день = 84-дневный цикл**. Это даёт игрокам полноценное «погружение» в каждый сезон (3 недели — обычная engagement-неделя ×3, не торопит), и совпадает с естественным восприятием времени года. Альтернатива 3×14 = 42 дня была бы слишком FOMO-ёмкой для casuals.

**Цель.** Ввести **сезонные рецепты** — каждый сезон unlock'ает 5 эксклюзивных рецептов на 21 день. Дать ощущение «события» и причину захода в игру; mitigate FOMO через **«сезон возвращается каждый год»** контракт.

**Канон.** Не указано — расширение (ADR-031).

**Что меняем.**
- `Config\SeasonalCrafts` config — 4 сезона × 5 рецептов = 20 общих сезонных рецептов.
- 4 сезона: **Зима выживания** (теплоизоляция, копчёное мясо, дровокол), **Весеннее пробуждение** (семена-наборы, чай из первой травы, удочка нового типа), **Летняя жара** (солнечная экипировка, охлаждающие напитки, лёгкая броня), **Осенняя жатва** (консервы, заготовки, бочки).
- `EventService::checkSeasonalTransition()` — daily cron в 00:00 проверяет где мы в 84-дневном цикле.
- **GameSettings keys (live-tunable):**

| Ключ | Default | Описание |
|---|---|---|
| `seasonal.season_count` | 4 | Количество сезонов в году |
| `seasonal.season_duration_days` | 21 | Длительность одного сезона в днях |
| `seasonal.cycle_anchor_date` | `2026-06-01` | Дата начала первого «Лета» (anchor для math) |
| `seasonal.enabled` | true | Глобальный killswitch на случай ребаланса |
| `seasonal.recipe_unlock_count_per_season` | 5 | Сколько рецептов unlock'ается за сезон |

- Сезонные крафты НЕ становятся unlearned — крафт остаётся доступен пока активен сезон, но **уже изготовленные предметы НЕ исчезают**. Возврат в следующем году — снова доступен.

**Image-assets.** 20 сезонных крафтов × $0.04 = $0.80 (но генерим по 5 на сезон, per-session по мере запуска).
- Pilot первой сезонной партии (зима) — 5 × $0.04 × 1 итерация = **$0.20**.
- Остальные 15 — постепенно в последующих сессиях (vNext).

**Validation-card.** 🟠 — FOMO concern (П2 голос: bad). Mitigations:
1. Контракт «сезон возвращается каждый год» — анонс в первом релизе.
2. Длинная длительность (21 день) — успевают и казуалы.
3. Live-tunable через GameSettings — если фидбэк негативный, админ может выключить (`seasonal.enabled = false`) или растянуть до 30+ дней.
4. Уже изготовленные предметы НЕ удаляются.

**Прод-тег.** `v0.51.207`.

**Зависимости.** S5 (GameSettings), S16 (T3 могут использовать сезонные ресурсы).

**Анонс.** Да — «Сезонные крафты: первый сезон — Зима выживания. 4 сезона × 21 день. Не успел — вернётся в следующем году».

---

### S29 — Content-pass: lying descriptions cleanup 🟢

**Цель.** Audit всех user-facing строк (`crafted_items.description`, `events.description`, `buildings.completion_text`) на «lying descriptions». Idempotent UPDATE миграция исправляет.

**Канон-ссылка.** `GAME_DESCRIPTION.md` § расхождения; `mmorpg-vault/feedback_content_pass_workflow.md`.

**Что меняем.**
- Grep всех user-facing строк, сравнить с tech-writing нотами и lore.
- Каждый найденный «врёт» — UPDATE base description + inline edit в коде.

**Миграция.** `2026-06-16-100000_ContentPassFixLyingDescriptions.php` — idempotent UPDATE.

**Image-assets.** Нет.

**Smoke / Tests.**
1. Прогон по чек-листу всех recipe descriptions.
2. Все события — описание соответствует фактической механике.

**Tech-writing.** Все затронутые ноты обновить + `feedback_content_pass_workflow.md` дополнить.

**Прод-тег.** `v0.51.208`.

**Зависимости.** S4, S5 (durability+repair описаны в каноне).

**Анонс.** Не нужен (полировка).

---

### S30 — Admin tools + ROADMAP-vNext 🟢

> **🎯 Решение user'а (2026-05-17):** vNext приоритеты выставлять **в логической и верной последовательности учитывая стек, lore, сеттинг, архитектуру и зависимости сессий**. Не свободный выбор — алгоритм.

**Цель.** Закрыть фазу: admin craft-tree view дополнительная фича, tech-writing back-fill, написать ROADMAP-CRAFT-vNext.md следуя приоритезирующему алгоритму.

**Канон.** ADR-009 (constitutional tech-writing).

**Что меняем.**
- `/admin/craft-tree` — уже live с v0.51.180; расширение: индикаторы tier (T1/T2/T3), фильтр по сезону, экспорт в CSV.
- Прогон всех `mmorpg-vault/tech-writing/` нот через `last_reviewed` — обновить устаревшие за 30 сессий.
- **`ROADMAP-CRAFT-vNext.md`** (новый файл) — следующие 30 сессий, синтезированные через тот же team-lead workflow (research-agents + synth), но с учётом обратной связи от S1–S29.

**🧭 Алгоритм приоритезации vNext (обязательный):**

Каждую кандидатную тему ранжировать по 5 осям и брать только top-30:

1. **Lore-debt** (+1..+5): закрывает ли тема канон/code mismatch (см. таблицу в `GAME_DESCRIPTION.md`)? Чем больше открытых tail'ов закрывается, тем выше.
2. **Архитектурная зависимость** (+1..+5): unlock'ает ли тема несколько следующих тем? (e.g., S5 GameSettings unlock'ает S26+S28+S10). Foundation-темы наверх.
3. **Stack-fit** (-3..+3): использует ли тема существующий CI4 / PSR / Longman pattern, или требует новой внешней зависимости? Сложные интеграции — вниз (если не критичны).
4. **Setting-coherence** (-5..+5): насколько строго укладывается в постапок «Найденная фотоплёнка» сеттинг? Чем «легче» вписывается, тем выше; magic/sleek sci-fi/fantasy — отрицательно.
5. **Player impact** (+1..+5): сколько из 10 портретов получает выгоду? Кто из контрактных (П2 оффлайн, П3 лор, П9 anti-P2W) **не пострадает**?

**Кандидатные направления для vNext (для синтеза, не предписано):**
- **Farming/agriculture deepening** (P5/P10 win, lore-coherent — Greenhouse уже есть): seed-cycle, crop rotation, harvest scheduling.
- **Cooking/food crafts** (P1/P10): рецепты на campfire, сохранение продуктов, бонусы от приготовленной еды.
- **Trade routes / NPC caravans** (P7/P8): динамическая торговля между Settlement'ами (если settlement-система появится).
- **Lore quest-chain T3** (P3/P4): мульти-этапные квесты, ведущие к unique Tier 4 крафтам.
- **Robotics depth** (P5/P8): робот-апгрейды T3, специализированные роботы (researcher/medic/scout).
- **Crafted item insurance / repair shops** (P2/P8): NPC-сервисы.
- **Workshop specialization / branching** (P5): дать игроку выбрать ветку специализации — оружейник / медик / инженер.
- **Faction-cooperative crafts** (P6): крафты, требующие contribution от 2+ игроков одной фракции.
- **Strategic-objects content** (P4/P6): полные quest-цепочки внутри Bunker/Technopark/GhostCity/Island-Farm (S21-S24 ставит фундамент, vNext раскрывает контент).
- **Map-driven gather rebalance** (P7/P8): rare biome-spots from S10 переосмыслить с user-фидбэка.
- **Crafting economy dashboard** (admin): графики цен, оборот, инфляция, fraction-distribution.
- **Tier 4 Unique crafts** (P3/P5/P6): faction-specific Tier 4 (1 рецепт на фракцию, требует endgame-объект).

vNext будет иметь те же 6 фаз × 5 сессий структуру, тот же контракт 17 секций.

**Image-assets.** Нет (тех-сессия).

**Smoke.** HTTP smoke `curl /admin/craft-tree`. Прогон `composer test` + `phpstan` на всех 30 коммитах.

**Tech-writing.** Все ноты `last_reviewed = today`. Создать `mmorpg-vault/inbox/2026-MM-DD-roadmap-v1-retrospective.md` — что пошло как ожидалось, что нет.

**Прод-тег.** `v0.51.209`.

**Зависимости.** Все S1–S29.

**Анонс.** «Roadmap-CRAFT v1 закрыт. Версия v2 — см. ROADMAP-CRAFT-vNext.md».

---

## §10 Image generation budget

> **🎯 Решение user'а (2026-05-17):** генерация **per-session**, не batch'ем. На каждой сессии где есть image-asset — генерируется во время сессии и сразу используется для smoke-теста + визуального анализа. Это даёт быструю обратную связь (увидел брак → перегенерировал) и не разбухает PR'ы.
>
> **Алгоритм per-session:**
> 1. В начале сессии — `php spark images:generate --keys=&lt;keys-этой-сессии&gt;` (генерит только нужные ключи).
> 2. Визуальный обзор + grep на текст в alt (`feedback_image_prompt_tuning`).
> 3. Если брак (любой текст, sleek sci-fi, fantasy) → перегенерация с уточнением scene-tail.
> 4. Подтверждённые → `git add public/uploads/telegram/...` + `git commit` вместе с code-изменениями.
> 5. Status в `ImageRegistry::$images` обновить `pending → generated → approved`.

### 10.1 Сводная таблица

| Сессия | Картинок | Стоимость | Cumulative |
|---|---|---|---|
| S1 | 0 | $0.00 | $0.00 |
| S2 | 0 | $0.00 | $0.00 |
| S3 | 0 | $0.00 | $0.00 |
| S4 | 1 | $0.04 | $0.04 |
| S5 | 2 | $0.08 | $0.12 |
| S6 | 5 | $0.20 | $0.32 |
| S7 | 0 | $0.00 | $0.32 |
| S8 | 0 | $0.00 | $0.32 |
| S9 | 0 | $0.00 | $0.32 |
| S10 | 4 | $0.16 | $0.48 |
| S11 | 2 (×2 iter) | $0.16 | $0.64 |
| S12 | 2 (×2 iter) | $0.16 | $0.80 |
| S13 | 4 (×2 iter) | $0.32 | $1.12 |
| S14 | 6 (4 buildings ×2 + 4 robots ×1) | $0.32 | $1.44 |
| S15 | 2 (×2 iter) | $0.16 | $1.60 |
| S16 | 1 (×3 iter) | $0.12 | $1.72 |
| S17 | 5 (×2 iter) | $0.40 | $2.12 |
| S18 | 4 (×2 iter) | $0.32 | $2.44 |
| S19 | 3 (×2 iter) | $0.24 | $2.68 |
| S20 | 3 (×2 iter) | $0.24 | $2.92 |
| S21 | 2 (×3+2 iter) | $0.20 | $3.12 |
| S22 | 2 (×3+2 iter) | $0.20 | $3.32 |
| S23 | 2 (×3+2 iter) | $0.20 | $3.52 |
| S24 | 2 (×3+2 iter) | $0.20 | $3.72 |
| S25 | 4 (×2 iter) | $0.32 | $4.04 |
| S26 | 3 (×2 iter) | $0.24 | $4.28 |
| S27 | 0 | $0.00 | $4.28 |
| S28 | 5 (×1 iter) | $0.20 | $4.48 |
| S29 | 0 | $0.00 | $4.48 |
| S30 | 0 | $0.00 | $4.48 |
| **Резерв** | 50% buffer | **$2.24** | **$6.72** |

**Итог: ~$5–7** (с резервом на перегенерации после фейл-картинок от gpt-image-2).

### 10.2 LEXICON additions

В `app/Config/ImageRegistry.php::$lexicon` нужно добавить:

```php
// — Уровни зданий (S11–S15) —
'building.workshop.l2'    => '...',  // см. S11 image-assets
'building.workshop.l3'    => '...',  // см. S11
'building.blast-furnace.l2' => '...', // S12
'building.blast-furnace.l3' => '...', // S12
'building.laboratory.l2'  => '...',   // S13
'building.laboratory.l3'  => '...',   // S13
'building.greenhouse.l2'  => '...',   // S13
'building.greenhouse.l3'  => '...',   // S13
'building.robotics-workshop.l2' => '...', // S14
'building.robotics-workshop.l3' => '...', // S14
'building.teleport-center.l2'   => '...', // S15
'building.teleport-center.l3'   => '...', // S15

// — Роботы T2/T3 (S14) —
'robot.explorer.t2'       => '...',
'robot.explorer.t3'       => '...',
'robot.gatherer.t2'       => '...',
'robot.gatherer.t3'       => '...',

// — Ресурсы (S6, S10) —
'resource.ironstone'      => '...',  // S6
'resource.oil'            => '...',  // S6
'resource.sulfur'         => '...',  // S6
'resource.rare-metals'    => '...',  // S6
'resource.coal'           => '...',  // S6
'resource.fuel-rods'      => '...',  // S10
'resource.pre-collapse-electronics' => '...', // S10
'resource.industrial-plastic' => '...', // S10
'resource.medical-compound' => '...', // S10

// — Стратегические объекты (S21–S24) —
'world.bunker'            => '...',  // S21
'world.bunker.discovered' => '...',  // S21
'world.technopark'        => '...',  // S22
'world.technopark.discovered' => '...', // S22
'world.ghost-city'        => '...',  // S23
'world.ghost-city.discovered' => '...', // S23
'world.island-farm'       => '...',  // S24
'world.island-farm.discovered' => '...', // S24

// — Tier 3 крафт (S16–S20) —
'craft.professional-workbench' => '...', // S16
'craft.master-crossbow'   => '...', // S17 (5 weapons)
'craft.heavy-rebar'       => '...', // S17
'craft.pipe-shotgun'      => '...', // S17
'craft.steel-machete'     => '...', // S17
'craft.scrap-rifle'       => '...', // S17
'craft.plated-armor'      => '...', // S18 (4 armor)
'craft.kevlar-patchwork'  => '...', // S18
'craft.tactical-vest'     => '...', // S18
'craft.hardened-helmet'   => '...', // S18
'craft.synthetic-medicine'=> '...', // S19 (3 medical)
'craft.emergency-transfusion' => '...', // S19
'craft.surgical-kit'      => '...', // S19
'craft.combination-tool'  => '...', // S20 (3 utility)
'craft.portable-forge'    => '...', // S20
'craft.advanced-fishing-net' => '...', // S20

// — Faction-exclusive weapons (S25) —
'craft.bunker-rifle'      => '...',
'craft.techno-beam-shotgun' => '...',
'craft.ghost-city-knife'  => '...',
'craft.farmers-scythe'    => '...',

// — Defensive structures (S26) —
'building.wooden-wall'    => '...',
'building.barbed-fence'   => '...',
'building.watch-tower'    => '...',

// — Repair / durability (S4, S5) —
'loot.tool-broken'        => '...',  // S4
// (S5 reuses existing loot.tool)

// — Сезонные крафты (S28) — placeholders, специфика — по сезону
'craft.seasonal.winter-1' => '...',
'craft.seasonal.winter-2' => '...',
'craft.seasonal.winter-3' => '...',
'craft.seasonal.winter-4' => '...',
'craft.seasonal.winter-5' => '...',
```

**Итого новых LEXICON-записей:** 44.

### 10.3 Hard rules (reminder)

- ZERO TEXT EVER на любой картинке (V2-стиль активирует «выскоблено все, что провоцирует нейронку рисовать буквы»).
- NO real flags, NO fantasy magic, NO sleek sci-fi.
- Все артефакты плёнки на фоне, никогда не на главном объекте.
- Карта мира — НЕ генерируем (GD canvas, фейл-инцидент 2026-05-13).
- Иконки/малые preview — тест на читаемость в Telegram preview (~256px).

---

## §11 Validation cards (полные, для каждой сессии)

> Здесь только полная карточка для **первой сессии каждой фазы** (S1, S6, S11, S16, S21, S26) — в качестве шаблона. Для остальных сессий см. mini-card в §4–§9. Если потребуется полная — копи-пасты шаблон из §2.5 и заполни по mini-card.

### §11.1 Validation Card — S1 (Cleanup legacy Build*Construction)

**Идея:** Удалить ~11 legacy `Build*Construction.php` action-handlers после подтверждения покрытия `GenericBuildingAction`.

**Тип:** refactor
**Подсистемы:** building
**Источник:** ROADMAP-CRAFT.md §4 S1

#### Ворота 0 — Формулировка
- Проблема: legacy дубль 4187 LOC, путает grep, дублирует logic.
- Что меняем: удалить файлы + legacy callback routes.

#### Ворота 1 — Канон
- GAME_DESCRIPTION conflict? **Нет** — контракт зданий не меняется.
- Постапок? Да.
- Тексты правдивы? Да.

#### Ворота 2 — 10-персон
| П | Голос | Комментарий |
|---|---|---|
| П1 | 0 | Не видит |
| П2 | 0 | Не видит |
| П3 | 0 | Не видит |
| П4 | 0 | Не видит |
| П5 | + | Меньше шума |
| П6 | 0 | Не видит |
| П7 | 0 | Не видит |
| П8 | 0 | Не видит |
| П9 | 0 | Не видит |
| П10 | 0 | Не видит |
- Предательства? Нет.
- Вердикт: ✅

#### Ворота 3 — Баланс
- Экономика: ok
- Прогрессия: ok
- Бой: ok
- Тайм-гейты: ok
- Крафт/базы: ok (поведение неизменно)
- Фракции/эндгейм: ok
- Оффлайн: ok

#### Ворота 4 — Техно
- Миграции: нет
- Тесты: existing зелёные
- phpstan: baseline не регрессит (LOC −4187 → возможно −20–50 ignored issues)
- Идемпотентность: N/A
- Tech-writing: `GenericBuildingAction.md` last_reviewed update

#### Ворота 5 — Smoke
- Тип → Telegram smoke (на 12 зданий)
- Шаги: 12 раз нажать «Построить» в Telegram, проверить callback ответ; для одного — довести до completion.

#### Ворота 6 — Релиз
- Stepwise: 1 шаг
- Commit message: «refactor(buildings): F2.1 cleanup — удалены 11 legacy Build*Construction.php (-4187 LOC, generic уже покрывает все 12)»
- Доки: tech-writing нота update.
- ADR: не нужен.
- hot.md / daily: да.
- Анонс: нет.

### ИТОГ: 🟡 — рефактор 0 behavior change, прозрачный smoke-план.

---

### §11.2 Validation Card — S6 (Missing resources)

**Идея:** Добавить 5 ресурсов (Ironstone, Oil, Sulfur, RareMetals, Coal) в `resources` таблицу — закрытие lore tail #4.

**Тип:** content + balance
**Подсистемы:** resources, crafting, building
**Источник:** ROADMAP-CRAFT.md §5 S6

#### Ворота 0 — Формулировка
- Проблема: рецепты ссылаются на не-существующие ресурсы → 🔴 lying.
- Что меняем: миграция вставляет 5 ресурсов + biome→resource mapping.

#### Ворота 1 — Канон
- GAME_DESCRIPTION conflict? Нет — § «Расхождения» уже фиксирует это как open tail.
- Постапок? Да (всё salvaged).
- Тексты правдивы? **Да** после миграции.

#### Ворота 2 — 10-персон
| П | Голос | Комментарий |
|---|---|---|
| П1 | + | Больше mining gameplay |
| П2 | 0 | Не ломает оффлайн |
| П3 | ++ | Канон теперь не врёт |
| П4 | + | Больше PvP-целей за ресурсами |
| П5 | ++ | Полная цепочка крафта работает |
| П6 | + | Endgame резон |
| П7 | + | 5 новых ачив-целей |
| П8 | + | Новые ценники |
| П9 | + | Прозрачные diff-rates по биомам |
| П10 | 0 | Roby опционально объясняет |
- Предательства? Нет.
- Вердикт: ✅

#### Ворота 3 — Баланс
- Экономика: новые ресурсы → новые ценники; S9 cron корректирует.
- Прогрессия: Arsenal (lvl 15) теперь достижим.
- Бой: ok.
- Тайм-гейты: ok (gather в [10min, 12h] коридоре).
- Крафт: новые рецепты в Arsenal — есть.
- Фракции: будет влиять на Engineers через RareMetals; ok.
- Оффлайн: ok.

#### Ворота 4 — Техно
- Миграция: новая, idempotent UPSERT.
- Тесты: 3 новых.
- phpstan: ok.
- Идемпотентность: yes.
- Tech-writing: `mmorpg-vault/tech-writing/db/resources.md` + `lore/world/Биомы.md`.

#### Ворота 5 — Smoke
- DB-row smoke: после migrate — gather на каждом из 4 биомов → выпадает каждый из 5 ресурсов с нужной вероятностью.
- Idempotent test: rerun migrate, 0 changes.

#### Ворота 6 — Релиз
- Stepwise: 1 step (миграция атомарна).
- Commit message: «content(resources): +5 канон-ресурсов (Ironstone/Oil/Sulfur/RareMetals/Coal), биом-маппинг (закрывает lore tail #4)».
- Доки: GAME_DESCRIPTION таблица расхождений — отметить #4 как ✅; lore/world/Биомы.md — обновить.
- ADR: ADR-025-Missing-canonical-resources.md (новый).
- hot.md / daily: да.
- Анонс: да — «Канон догнал реальность: Железная руда, Сырая нефть, Сера, Редкие металлы, Уголь добываются».

### ИТОГ: 🟠 — закрытие канон-расхождения с балансовым влиянием, ADR обязателен.

---

### §11.3 Validation Card — S11 (Workshop L2/L3)

**Идея:** Активировать L2 и L3 апгрейды Workshop — +10%/+25% скорости общего крафта.

**Тип:** content (apgrade contract)
**Подсистемы:** building, crafting
**Источник:** ROADMAP-CRAFT.md §6 S11

#### Ворота 2 — 10-персон
| П | Голос | Комментарий |
|---|---|---|
| П1 | + | Прогрессия видимая |
| П2 | + | Долгие задачи дружелюбны оффлайну |
| П3 | + | Канон обещал улучшение |
| П4 | 0 | Не влияет на PvP |
| П5 | ++ | Глубина прогрессии |
| П6 | + | Endgame +200 очков за апгрейд |
| П7 | + | Видимый новый contentbar |
| П8 | + | Sink ресурсов = инфл-контроль |
| П9 | + | Прозрачный multiplier |
| П10 | 0 | Уровень 1 — пока не видит |
- Предательств: нет.
- Вердикт: ✅

#### Ворота 3 — Баланс
- Краф-время Bandage L1=15min → L2=13.5min → L3=11.25min. Сбалансировано.
- Sink ресурсов на апгрейд = sink для late-game игроков; П8 одобряет.

### ИТОГ: 🟡

---

### §11.4 Validation Card — S16 (T3 Professional Workbench, ADR-026)

**Идея:** Tier 3 верстак — расширение канона за пределы 2-уровневой системы.

**Тип:** content (canon extension)
**Подсистемы:** crafting

#### Ворота 1 — Канон
- GAME_DESCRIPTION conflict? **Частично** — канон описывает 2-tier систему, T3 — расширение. **ADR-026 обоснует**.
- Постапок? Да (heavy hand-built workbench).
- Тексты правдивы? Да.

#### Ворота 2 — 10-персон
| П | Голос |
|---|---|
| П1 | ++ depth |
| П2 | 0 high-level, оффлайн ok |
| П3 | + canon extended consciously |
| П4 | + T3 weapons → PvP relevance |
| П5 | ++ deep progression unlocked |
| П6 | + endgame relevance |
| П7 | ++ new tier to chase |
| П8 | + new sink + premium prices |
| П9 | + transparent multiplier |
| П10 | 0 too high level |
- Предательств: нет.

### ИТОГ: 🟠 — расширение канона требует ADR-026 + анонс.

---

### §11.5 Validation Card — S21 (Bunker + ADR-027)

**Идея:** Сделать Bunker реальным открываемым объектом мира.

**Тип:** content (endgame infrastructure)
**Подсистемы:** world, endgame

#### Ворота 2
| П | Голос |
|---|---|
| П1 | ++ exploration urgency |
| П2 | 0 long-term goal, no FOMO |
| П3 | ++ канон обещал, реализуем |
| П4 | + цель захвата |
| П5 | + tied to T3 |
| П6 | ++ faction race becomes real |
| П7 | ++ achievement target |
| П8 | + rare resource source |
| П9 | + transparent quest reward |
| П10 | 0 high-level only |
- Предательств: нет.

### ИТОГ: 🟠 — endgame mechanic (ADR-027) + анонс.

---

### §11.6 Validation Card — S26 (Defensive structures)

**Идея:** Ввести стены/колючку/башню для defending бази.

#### Ворота 2
| П | Голос |
|---|---|
| П1 | + риск/реворд балансирован |
| П2 | + лучшая защита оффлайна |
| П3 | + канон обещает Военных |
| П4 | **−/+** Меняет PvP экономику — нужен расчёт |
| П5 | ++ новые цепочки |
| П6 | + клановая защита |
| П7 | + 3 новых сооружения |
| П8 | + sink |
| П9 | + прозрачные множители |
| П10 | 0 high-level |
- П4 голос смешан — нужен балансовый чек (ADR-030 включит математику).

### ИТОГ: 🟠 — затрагивает PvP-экономику, требует ADR-030.

---

### §11.7 Краткие mini-cards для S2–S5, S7–S10, S12–S15, S17–S20, S22–S25, S27–S30

Уже описаны в §4–§9 (секция «Validation-card mini» каждой сессии). Они достаточны для классификации; полные карточки только при необходимости — для 🟠 сессий ADR покроет.

---

## §12 Финальные замечания

### 12.1 Что НЕ вошло в roadmap (и почему)

- **5-я фракция.** 🔴 — ломает 1:1 mapping faction↔scenario. Возможно в ROADMAP-vNext с full ADR.
- **Permadeath режим.** 🟠 — opt-in only, не дефолт. Может появиться в S30+ vNext.
- **Crafted items как currency / market for crafted goods.** 🟠 — балансовый риск (П8 inflation). Vault — потенциальный S31+.
- **Glow / particles / sleek sci-fi visuals.** 🔴 — нарушает image style. Все картинки строго в каноне «Найденная фотоплёнка».
- **Magic-системы крафта (зелья из канона D&D и т.п.).** 🔴 — ломает постапок-сеттинг.

### 12.2 Decisions Log (locked by user 2026-05-17)

Все 7 открытых вопросов из v1.0 закрыты. Каждое решение применено в соответствующих сессиях.

| # | Вопрос | Решение | Влияние |
|---|---|---|---|
| 1 | S5 Repair cost | **50% default**, **вынесено в админку** через новый `GameSettings` framework (ключ `repair.cost_fraction`). | S5 расширена: добавлена **Часть A** — `GameSettingsService` + admin UI `/admin/game-settings` + таблица `game_settings`. Категория повышена 🟡→🟠, ADR-024 обязателен. |
| 2 | S10 Rare resources sources | Не только events — **+ rare biome-spots**. Биом важен. | S10 расширена: новый `RareNodeService` + cooldown-таблица + biome-specific spawn chances (Volcanic/Caves/Tropical/Mountains). Tunable через GameSettings. |
| 3 | S16 T3 Workbench level | **L20** (вместо L16). | Гэп L13-L20 теперь закрывается прогрессией Фазы 3 (building upgrades), а L20 даёт чистый gate перед T3. GameSettings ключ `tier3.workbench.character_level_required = 20`. |
| 4 | S26 Defensive structures damage cap | Default-ы на моё усмотрение, **всё tunable через `GameSettings`**. | S26 расширена: 12 ключей в GameSettings (`defense.wall.*`, `defense.fence.*`, `defense.tower.*`, `defense.total_damage_reduction_max_percent`). Default-ы зашиты как safe baseline. |
| 5 | S28 Сезонность | **4 сезона × 21 день** default, **tunable через `GameSettings`**. | S28 переписана: 4 сезона (Зима/Весна/Лето/Осень) × 21 день = 84-дневный цикл. Контракт «сезон возвращается каждый год», уже изготовленные не удаляются. Killswitch `seasonal.enabled`. |
| 6 | Image-генерации стратегия | **Per-session** по необходимости (для реального smoke во время сессии). | §10 обновлена: budget остаётся $5-7, но генерация распределена по сессиям, не batch. Это даёт быструю обратную связь и шанс перегенерировать с фидбэком. |
| 7 | ROADMAP-vNext приоритеты | Не свободный выбор — **алгоритм приоритезации по 5 осям** (lore-debt / архитектурная зависимость / stack-fit / setting-coherence / player impact). | S30 расширена с явным алгоритмом + 12 кандидатных направлений. Финальный отбор top-30 в результате прогона алгоритма поверх итогов S1-S29. |

### 12.2-bis Constitutional addition: GameSettings framework (S5)

Решение #1 каскадно повлияло на S10, S16, S26, S28: **все балансные параметры теперь живут в `GameSettings`**, не в `Config\GameBalance`. Это даёт:

- **Live tuning** — админ меняет на проде без redeploy.
- **Audit trail** — `updated_by` + `updated_at` пишутся для каждого изменения.
- **Per-key validation** — min/max bounds защищают от экстремальных значений.
- **Fallback cascade** — если ключа нет в БД, используется default из `GameSettings::get($key, $default)` вторым параметром.
- **Cache 60s** — производительность не страдает (admin меняет редко).

**ADR-024 «Game Settings live-tunable framework»** становится **обязательным выходом S5**. Все последующие сессии используют один и тот же паттерн регистрации балансных параметров.

### 12.3 Алгоритм приоритезации задач (для S30 vNext + текущей работы)

Каждая кандидатная тема ранжируется по 5 осям, score = сумма:

1. **Lore-debt** (+1..+5) — закрывает канон-код mismatch?
2. **Архитектурная зависимость** (+1..+5) — unlock'ает другие темы?
3. **Stack-fit** (-3..+3) — использует существующий CI4/Longman pattern?
4. **Setting-coherence** (-5..+5) — постапок «Найденная фотоплёнка»?
5. **Player impact** (+1..+5) — сколько портретов выигрывает?

Top-N по score → roadmap. Foundation-задачи (S5 GameSettings) автоматически наверх, потому что rate-2 maximal.

### 12.3 Mермейд-диаграмма последовательности фаз (опционально)

```
gantt
    title 6 фаз × 5 сессий
    dateFormat  YYYY-MM-DD
    axisFormat  %Y-%m-%d
    section Phase 1 — Foundation
    S1 :2026-05-18, 1d
    S2 :2026-05-19, 1d
    S3 :2026-05-20, 1d
    S4 :2026-05-21, 1d
    S5 :2026-05-22, 1d
    section Phase 2 — Resources
    S6  :2026-05-23, 1d
    S7  :2026-05-24, 1d
    S8  :2026-05-25, 1d
    S9  :2026-05-26, 1d
    S10 :2026-05-27, 1d
    section Phase 3 — Building Upgrades
    S11 :2026-05-28, 1d
    S12 :2026-05-29, 1d
    S13 :2026-05-30, 1d
    S14 :2026-05-31, 1d
    S15 :2026-06-01, 1d
    section Phase 4 — T3 Craft
    S16 :2026-06-02, 1d
    S17 :2026-06-03, 1d
    S18 :2026-06-04, 1d
    S19 :2026-06-05, 1d
    S20 :2026-06-06, 1d
    section Phase 5 — Endgame Faction
    S21 :2026-06-08, 1d
    S22 :2026-06-09, 1d
    S23 :2026-06-10, 1d
    S24 :2026-06-11, 1d
    S25 :2026-06-12, 1d
    section Phase 6 — Polish + vNext
    S26 :2026-06-13, 1d
    S27 :2026-06-14, 1d
    S28 :2026-06-15, 1d
    S29 :2026-06-16, 1d
    S30 :2026-06-17, 1d
```

(Калькуляция в днях для AI-pacing — НЕ для людей. Календарь — только timestamp.)

---

**Конец ROADMAP-CRAFT.md v1.0**
