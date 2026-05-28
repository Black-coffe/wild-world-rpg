# ROADMAP-CRAFT-vNext2.md — следующие 30 сессий (v3)

> Синтезирован в **V30** (2026-05-26) — финальная сессия ROADMAP-CRAFT vNext (V1–V29 shipped, прод-теги v0.51.213 → v0.51.265).
> Приоритезация — через тот же **5-осевой алгоритм** (см. v1 §S30 + vNext §0).
> Полные 17-секционные карточки каждой сессии пишутся в начале соответствующей фазы; здесь — **приоритезированный outline** (методология + ранжирование + 6×5 структура).

---

## Журнал сессий vNext2

| # | Сессия | Статус | Тег | Дата |
|---|---|---|---|---|
| W1 | **Drone-recon foundation (audit+ADR+seed)** | ✅ SHIPPED prod (Tier-1/2/3 PASS) | v0.51.268 | 2026-05-26 |
| W2 | **Drone-recon build** (DroneService + RecceDroneAction + DroneRechargeCron + UI + 9 tests) | ✅ SHIPPED prod (Tier-1/2/3 PASS) | v0.51.268 (+ art v0.51.269) | 2026-05-26 |
| W3a | **Weight-cap + base_storage foundation** (ADR-059, default OFF на ship) | ✅ SHIPPED prod (Tier-1/2 PASS) | v0.51.270 | 2026-05-26 |
| W3b | **Cargo drone build** (ADR-060, lock-button discoverability + atomic delivery + retrieve UI) | ✅ SHIPPED prod (Tier-1 + Tier-2 + Tier-3 partial PASS) | v0.51.274 | 2026-05-27 |
| W4 | **Repair drone build** (ADR-063, gold-only batch ремонтник, gate RoboticsWorkshop L3, V19 overlay distinct-value) | ✅ SHIPPED prod (Tier-1 + Tier-3 partial PASS) | v0.51.284 | 2026-05-27 |
| W5 | **Combat drone + Caravan drone-offer integration** (ADR-064, 🏁 закрытие Фазы 1 Drone-family) | ✅ SHIPPED prod (Tier-1 PASS + Tier-3 partial PASS — bug catch + hotfix) | v0.51.287 | 2026-05-27 |
| W6 | **Onboarding redux #11 — audit + scope** (ADR-065, hybrid: Robi 4→7 + «Что нового» catalog) | ✅ SHIPPED docs-only (без кода, реализация в W7) | — | 2026-05-27 |
| W7a | **Onboarding redux — Robi extension 4→7 шагов** (ADR-065 implement Part 1: шаги 5/7 «Подсказки и советы» + 6/7 «Этапы прокачки» + 7/7 closer; killswitch GameSettings) | ✅ SHIPPED prod (Tier-1 + Tier-3 PASS + 2 Tier-3 hotfix'а: PNG→JPG + caption 1068→605) | v0.51.288 | 2026-05-27 |
| W7b | **Onboarding redux — каталог «📚 Что нового»** (ADR-065 implement Part 2: WhatsNewCatalog/Topic + 6 тем + JSON seen-tracking + Перс button + Step7 promo; forced-show отложен) | ✅ SHIPPED prod (Tier-1 961/961 + Tier-3 cold-smoke PASS) + art-tail v0.51.290 | v0.51.289 | 2026-05-28 |
| W8 | **Inventory polish** (re-scope: «Склад базы» в хаб rule #4 + сортировка ресурсов/склада; weight отброшен — нет колонки + ADR-059 конфликт; +фикс 2 латентных багов) | ✅ SHIPPED prod (Tier-1 970/970 + Tier-3 cold-smoke PASS) | v0.51.291 | 2026-05-28 |
| W9 | **Achievement system foundation** (ADR-066: cron-poll state-driven award engine, 2 таблицы + 7 starter + killswitch OFF dormant; W10 активирует + UI) | ✅ SHIPPED prod dormant (Tier-1 981/981 + Tier-3 cold-smoke PASS) | v0.51.292 | 2026-05-28 |
| W10 | **Achievement T1 set + UI** (+14 medals = 21 total + экран «🏅 Достижения» + Перс button; killswitch остаётся OFF — активация в конце ROADMAP) 🏁 закрытие Фазы 2 | ✅ SHIPPED prod dormant (Tier-1 984/984 + Tier-3 cold-smoke PASS) | v0.51.294 | 2026-05-28 |
| … | … | … | … | … |

> **W1 SHIPPED 2026-05-26 (code-level):** ADR-058 (Drone-recon foundation, 6 резолюций open
> questions) + 6 GameSettings `drone.scout.*` (rich rationale ADR-024) + crafted_items
> `DroneScout` + tasks `craftDroneScout` + recipe (gate Workshop L1, gold=8000) + LEXICON
> `drone.scout` + ImageRegistry row (status='pending'). Tier-1 ✅ 898/898 + phpstan L9.
>
> **W2 SHIPPED 2026-05-26 (code-level):** `DroneService` (pure GameSettings-reader, 8 методов) +
> `DroneScoutCraftedListAction` (callback `droneScoutList`, charge-bar UI) + `RecceDroneAction`
> (callback `recceDrone_<log_id>` через CallbackPrefixDispatcher, full launch chain + PvP-zone
> block anti-snooping) + `DroneRechargeCron` (everyMinute on_base recharge) +
> MoveCharacterToDirectionAction patch (кнопка «🚁 Дрон» в move-keyboard) + 9 unit-тестов
> (DroneServiceTest, 22 assertions). Tier-1 ✅ **907/907 + phpstan L9 NO ERRORS**. PHPStan
> fix-цикл: 5 ошибок (4× cast.int mixed + 1× BiomeEntity vs is_array) поправил через
> `$raw = $row[key] ?? null; is_numeric($raw) ? (int) $raw : 0` паттерн.
>
> **Deploy SHIPPED 2026-05-26:** GA восстановилась в течение того же дня. W1+W2 ушли на прод
> bundle'ом `v0.51.268` (commit `0d4c0cd`, deploy 13:02 UTC, 2m12s ✅); image-tail `drone_scout`
> сгенерирован и закоммичен как `v0.51.269` (commit `c030640`, deploy 18:17 UTC, 2m6s ✅).
>
> **Tier-2 PASS 2026-05-26:** `/admin/game-settings` рендерит все 6 `drone.scout.*` ключей
> (категория `resources`) с collapse-блоком rationale/effect/above/below, корректными
> recommended/hard границами, кнопками «Сохранить» / «Сбросить к default». Screenshot
> `.tmp/w1w2-tier2-game-settings.png`.
>
> **Tier-3 PASS 2026-05-26:** real-Telegram chain на testbot (char 491, tg_user 25).
> (A) Кнопка «🚁 Дрон» в move-keyboard появляется при наличии скрафченного дрона. (B)
> `droneScoutList` рендерит charge-bar UI (▰▰▰▰▰▰▰▰▰▰ 100/100) + 3 кнопки (Запустить #N /
> Карта / База). (C) Callback `recceDrone_531` через `CallbackPrefixDispatcher` → запуск.
> (D) `ExploredCellsModel::revealAround(radius=10)` → 441 клеток зоны, 424 новых записаны;
> SQL verify: `crafted_items_log.id=531 durability_count 100→0`, `+427 explored_cells` за
> 5 мин (424 drone + 3 move). Caption media-off-friendly (числа+биомы в тексте, не картинке).
> Deferred: (E) recharge cron — unit-тесты покрывают, естественная игра закроет; (F)
> PvP-zone block — `map.pvp` колонки нет, механизм отложу до W4/W5.

> **W5 SHIPPED preprod 2026-05-27 (commit `b0d1059`, GA Deploy run `26533176168`):**
> 🏁 **Закрытие Drone-family Фазы 1 (5/5).** Combined-сессия (user-pick через
> AskUserQuestion 2026-05-27): Combat drone (defensive time-window, +12% инициативы
> защитнику на 30 мин, cap 25% combined с WatchTower; activation by-request, drains
> battery; recharge 6 ч on_base — premium-est tier) + Caravan drone-offer integration
> (per-type chance/markup, закрытие W1 dead promise `drone.scout.caravan_offer_chance`
> — теперь все 4 типа дронов могут продаваться готовыми у NPC-каравaнов за gold с
> премиум-наценкой 3×). [[mmorpg-vault/decisions/ADR-064-Combat-drone-and-caravan-drone-offer]]
> — 12 резолюций, 5-осевая Σ=13, 7 ворот ✅, 10-портретный чек (8/10 profit, 2 нейтрал,
> 0 negative). **5 миграций** (drone.combat.* 7 ключей rich rationale ADR-024 + crafted_items
> DroneCombat type='drones' durability=100 gate RoboticsWorkshop L4 + ALTER characters
> ADD combat_drone_active_until DATETIME NULL lazy-expiry pattern + ALTER caravans ADD
> offer_type/drone_type/gold_price backward-compat 'resource' default + drone.<type>.caravan_offer_chance × 3
> + caravan_markup_multiplier × 4). **+4 NEW handlers** (CombatDroneListAction charge-bar +
> active/charging status, CombatDroneActivateAction atomic tx + double-activation guard,
> DroneCombatCraftInfoAction preview-чек-лист, CaravanBuyDroneAction atomic INSERT crafted_items_log
> full battery) + **6 PATCH** (DroneService +9 combat* + 2 caravan helpers,
> DefenseStructureService integration with DroneService + lazy combat_drone_active_until
> check / **NO new mt_rand → RNG-fence safe**, DroneRechargeCron +4-й type DroneCombat,
> CaravanService +droneOfferCatalog/computeDroneOfferGold/isDroneOfferType/droneTypeFromOffer,
> SpawnCaravanCron +rollDroneOffer перед resource fallback, CaravanLookAction +branch drone-offer
> карточки, CharacterService +status-строка «🛡 Боевой дрон активен X мин» + lock-aware кнопка
> endgame row, StandardCraftingAction +🛡 в droneRow accumulator-pack, CallbackRoutes +combatDroneList/
> droneCombat, CallbackPrefixDispatcher +combatDroneActivate_/caravanBuyDrone_).
> **+21 unit-тестов** (931→952, 8026 assertions). **Tier-1 ✅ phpstan L9 NO ERRORS на 659 файлах.**
> **Дальше:** Tier-3 cold-smoke на testbot после deploy (Combat activation flow + Caravan
> force drone-offer + atomic buy) → cherry-pick на master + prod tag → art-tail
> (`php spark images:generate --missing` для `drone_combat.jpg`) отдельным тегом.
>
> **W3 AUDIT+ADR DONE 2026-05-26:** Audit-first выявил drift — ROADMAP §2 line 100
> описывает Cargo drone через «переноска ресурса из дальней клетки на базу», но
> `GatherResultPersister:74` пишет gather напрямую в `character_resources` (нет «ресурса
> на клетке, ждущего pickup»), а base_storage / weight-cap не существуют. User-выбранная
> интерпретация (2026-05-26): **Option A — Weight-cap relief** через `character.weight_capacity`
> + новая таблица `base_storage` + cargo drone delivers carried → base_storage.
> [[mmorpg-vault/decisions/ADR-059-Cargo-drone-weight-cap-foundation]] — 7 резолюций,
> 5-осевая Σ=14, 7 ворот ✅, 10-портретный чек (6 чистый профит, 1 нейтрал, 3 mitigated
> через `default=9999` effectively off на ship). **Split: W3a (foundation) → W3b (drone),**
> sequential.
>
> **W3a SHIPPED prod 2026-05-26 (`v0.51.270`):** 3 миграции (`characters.weight_capacity int
> default 9999` + NEW `base_storage` table + 3 GameSettings `inventory.weight_cap.*` с rich
> rationale ADR-024) + `WeightCapacityService` (pure GameSettings-reader, 7 методов: isEnabled/
> l1Base/perLevel/computeCapacity/getCurrentLoad/getRemainingCapacity/canAdd) +
> `BaseStorageModel` (findByCharacter/findEntry/deliver idempotent merge) +
> `CharacterModel::$allowedFields` += `weight_capacity` (memory
> feedback_ci4_alter_column_needs_allowedfields). Tier-1 ✅ **907/907 + phpstan L9 NO ERRORS
> (641 файлов)**. Tier-2 ✅ admin/game-settings рендерит 3 новых ключа
> (`inventory.weight_cap.*`) с recommended/hard bounds + «Сбросить». Preprod deploy
> commit `1768b8a` 1m56s ✅; prod tag `v0.51.270` (GA run `26472897473`, 2m57s) ✅;
> миграции применились на проде (verified via SSH SQL: 3 keys + column + table). **`enabled=false`
> default → 0 регрессии: gather проходит без cap-check, существующие игроки не видят изменений.**
> W3b (cargo drone uses W3a primitives) = следующая сессия.
>
> **W7a SHIPPED prod 2026-05-27 (`v0.51.288`):** Старт Фазы 2 vNext2 (Onboarding & Achievement),
> implement Part 1 ADR-065 hybrid. Split W7→W7a (Robi extension сейчас) + W7b (Catalog «Что
> нового» отдельно с фото-art-tail). Сделано: 1 migration `2026-06-02-100000_W7aSeedOnboardingRobiExtendedGameSettings`
> (1 killswitch `onboarding.robi_extended.enabled` default=true, category=world, rich
> rationale ADR-024) + 3 NEW handlers `GetTrainingStart5/6/7Action` (Шаг 5/7 «Подсказки и
> советы» = объяснение `/tips` + Совет дня 10:00 + opt-out через ⚙️ Настройки; Шаг 6/7
> «Этапы прокачки» = lvl 5 Специализация → lvl 10 фракция → Robotics L1-L4 каскад дронов →
> защита базы → ремонт/полис/караваны; Шаг 7/7 «Готов идти» closer + CTA) + 4 PATCH
> existing (Step1/2/3/4Action: dynamic «N/4» vs «N/7» через `gsBool('onboarding.robi_extended.enabled', true)`;
> Step4 — conditional nextStep=`startAdventure` legacy vs `getTrainedStart5` extended + кнопка
> label change «🛣 К приключениям!» vs «💡 Продолжить обучение») + CallbackRoutes +3 exact
> (`getTrainedStart5/6/7`). **0 новых тестов** (handler-trivial copy-of-pattern; Tier-3
> cold-smoke на чистом тест-чаре покрывает chain end-to-end). **Tier-1 ✅ 952/952 PASS +
> 8026 assertions + phpstan L9 NO ERRORS на 662 файлах + php -l все 8 файлов.** PHPStan
> fix-цикл: type narrowing для `$lastAction['action_status']` (`$lastAction` = `array|object|null`
> из `first()`) через `is_array($lastAction) && ($lastAction['action_status'] ?? null) ===
> 'Completed'`; убрать redeclare of parent's `$characterModel` property. Photos: W7a
> переиспользует existing assets: Step5 `final-step-image.jpg`, Step6
> `bioms-for-game-tips.jpg` (placeholder JPG после hotfix PNG→JPG), Step7
> `ready-for-adventure.jpg`. Dedicated LEXICON-asset'ы придут в W7b. Media-off safe:
> caption полноценный, картинки = enhancement. **Tier-3 cold-smoke на testbot (char 491,
> MCP Chrome + Telegram Web + bot API curl smoke-trigger):** chain 1/7 → 2/7 → ... → 7/7
> → startAdventure все переходы видны через edit-in-place ✅; caption нумерация dynamic
> «N/7» ✅; Step4 conditional кнопка «💡 Продолжить обучение» ✅; новые шаги 5/6/7
> рендерятся корректно ✅. **2 hotfix'а внутри Tier-3 цикла:** (1) Step6 PNG → JPG
> (editMessageMedia edge case с GD-PNG asset через `encodeFile()`), (2) Step6 caption
> 1068 → 605 chars (Telegram 1024 photo-caption limit). Memory урок:
> [[../claude-memory/feedback_telegram_photo_caption_1024_limit]] — `mb_strlen` обязателен
> перед commit, fallback `sendPhotoOrText` НЕ спасает за лимитом. ADR-065 закрытие Part 1
> в рамках hybrid-плана; Part 2 (catalog «📚 Что нового» + JSON column + topic seed-table
> + Перс button + returning-cooldown + 5-6 LEXICON photos) = W7b. **🏁 W7a/30, Фаза 2 — 1/5.**
>
> **W7b SHIPPED prod 2026-05-28 (`v0.51.289`):** Implement Part 2 ADR-065 — каталог
> «📚 Что нового». Сделано: **3 миграции** (`2026-06-02-110000` ALTER characters ADD
> `whats_new_seen TEXT` JSON-трекинг прочитанного + CharacterModel allowedFields;
> `_120000` killswitch `onboarding.whats_new.enabled` rich rationale ADR-024;
> `_130000` NEW table `whats_new_topics` utf8mb4 + seed 6 тем crafting/robots/defense/
> drones/factions/economy, каждый caption ≤1024) + **WhatsNewService** (killswitch +
> allTopics + topicByKey + seenKeys/markSeen JSON + imageRelOrFallback) + **WhatsNewTopicModel**
> + **2 NEW handlers** (WhatsNewCatalogAction `whatsNewCatalog` — список 6 тем с маркером 🆕
> непрочитанных + edit-in-place; WhatsNewTopicAction `whatsNew_<key>` prefix-route — рендер
> темы + markSeen + back) + **3 PATCH** (GetTrainingStart7Action +промо-кнопка каталога при
> killswitch on; CharacterService +кнопка «📚 Что нового» в Перс ВСЕГДА видна правило #4;
> CallbackRoutes +exact `whatsNewCatalog` +prefix `whatsNew`) + ImageRegistry (LEXICON
> `onboarding.catalog` + 6 `whatsnew.*` + 7 rows status=pending). **+9 unit** (WhatsNewServiceTest,
> вкл. permanent-гард 1024-лимита: token-scan строк миграции-сида). **forced-show вернувшимся
> отложен** (не трогаем /start; `returning_cooldown_days` не сеяли → без BUILT-BUT-DEAD).
> **Tier-1 ✅ 961/961 PASS (+9), 8054 assertions, phpstan L9 NO ERRORS, php -l все файлы.**
> PHPStan fix-цикл (3): return-type `list<array<int|string,mixed>>` под model-keys + убрать
> redundant `array_values` на list. **Tier-3 cold-smoke на testbot (char 491, MCP Chrome +
> Telegram Web, БЕЗ предзнаний):** «Перс» → кнопка «📚 Что нового» видна ✅ → каталог (фото +
> 6 тем + 🆕) ✅ → тема «Дроны» (фото + полный caption, edit-in-place) ✅ → «К каталогу» →
> «Дроны» теперь «•» (seen), остальные 🆕 ✅ → SQL verify `characters.whats_new_seen=["drones"]`
> ✅; Step7/7 promo-кнопка «📚 Что нового» + текст present ✅. **Art-tail SHIPPED (`v0.51.290`):**
> 7 картинок (`onboarding.catalog` + 6 `whatsnew.*`, gpt-image-2 V4, 118-244 KB, 0-text verified
> multimodal-Read, HTTP 200 prod); handler сохраняет is_file-fallback как защиту. **Doc:**
> [[mmorpg-vault/decisions/ADR-065-Onboarding-redux-hybrid]] W7b SHIPPED секция +
> [[mmorpg-vault/tech-writing/services/WhatsNewService]] + handlers/onboarding/index +
> ROADMAP §0. **🏁 W7b/30 + art-tail SHIPPED, Фаза 2 Onboarding & Achievement — 2/5.** Дальше:
> пауза ИЛИ W8 Inventory revamp UI (следующая сессия Фазы 2).
>
> **W8 SHIPPED prod 2026-05-28 (`v0.51.291`):** **Re-scope (user-pick AskUserQuestion):**
> литеральное «weight-cap для outfits» отброшено — audit-first вскрыл, что (а) weight-cap
> OFF на проде, (б) ADR-059 Q4 исключил crafted/outfits из веса, (в) у `resources` ВООБЩЕ
> нет колонки `weight`. Честное reuse-only ядро: discoverability + сортировка. **Сделано
> (1 service + 3 PATCH + 0 миграций):** NEW `InventorySortService` (pure stateless: sortRows
> rarity/name/qty/value + normalizeMode allowlist, +10 unit) + `InventoryAction` (кнопка
> «📦 Склад базы» в хаб — rule #4, раньше base_storage достижим только из move-keyboard/cargo)
> + `ResourcesGatheredAction` (сортировка через callback-param `resourcesGathered_sort_<mode>`
> + sort-toggle row; убран вес) + `BaseStorageListAction` (сортировка `baseStorageList_sort_<mode>`:
> recent/name/qty + sort-toggle row). **Без новых routes** (sort-callback резолвится в
> существующий exact-route по первому сегменту explode('_')). **🐛 Попутно пофикшены 2
> латентных бага** в `BaseStorageListAction` (W3b, экран был малодостижим → не всплывало):
> (1) `loadEnrichedEntries` селектил несуществующий `resources.weight` → SQL-исключение при
> любом открытии; (2) `isOnBase()` селектил несуществующий `claimed_cells.cell_number` →
> SQL-исключение при открытии с данными (заменён на канонический `BaseCheckService`).
> **Tier-1 ✅ 970/970 PASS, 8068 assertions, phpstan L9 NO ERRORS, php -l все файлы.** **Tier-3
> cold-smoke на testbot (char 491, MCP Chrome + Telegram Web, БЕЗ предзнаний):** Перс →
> Инвентарь → кнопка «📦 Склад базы» видна ✅ → «Добытые ресурсы» (sort-toggle Редкость/
> Название/Кол-во/Стоимость, qty-сорт verified descending `[4568,544,435,357,324]`, без веса) ✅
> → «Склад базы» (3 seeded rows, Итого 55 шт, off-base hint, isOnBase без краша) ✅ → stash sort
> «Кол-во» reorder descending ✅. Seeded rows подчищены. **Doc:**
> [[mmorpg-vault/tech-writing/services/InventorySortService]] (+2 латентных бага задокументированы) +
> hot.md + daily + ROADMAP §0. **🏁 W8/30, Фаза 2 — 3/5.** Дальше: W9 Achievement system
> foundation ИЛИ W10 Achievement T1 set ИЛИ пауза.
>
> **W9 SHIPPED prod dormant 2026-05-28 (`v0.51.292` + fix `v0.51.293`):** Achievement system
> foundation. 🟠-сессия — [[mmorpg-vault/decisions/ADR-066-Achievement-system-foundation|ADR-066]]
> (7 резолюций + 7 ворот + 5-осевая Σ=14 + 10-портретный 7/3/0) ДО кода. **Механизм = cron-poll
> state-driven** (user-pick AskUserQuestion) — 0 правок gameplay-хэндлеров (нулевой риск
> сломать боёвку/крафт), точная копия `QuestObjectiveHandler`. **Сделано (3 migration + 1 service
> + 1 cron + 2 model):** `achievements` (utf8mb4) + seed 7 starter (по 1 на criteria_type:
> char_level/explored_cells/craft_total/has_base/has_faction/quests_completed/gold_total) +
> `character_achievements` (UNIQUE char+ach = идемпотентность) + 2 GameSettings
> (`achievement.enabled` default **OFF** dormant + `achievement.max_awards_per_tick`=25 анти-шторм,
> rich rationale ADR-024). `AchievementService` (killswitch + definitions + idempotent award +
> set-based `qualifyingCharacterIds` per criteria_type). `AchievementCheckCron` (HandlerKey
> `achievement_check`, everyMinute: per-achievement выдача + per-player **батч**-уведомление +
> per-tick **cap**). Tasks.php +1. **+11 unit** (AchievementServiceTest). **Tier-1 ✅ 981/981
> PASS (+11), 8085 assertions, phpstan L9 NO ERRORS, php -l все файлы.** PHPStan fix-цикл:
> `handle(array $task = [])` сигнатура vs BaseTaskHandler (PHP fatal, php -l не ловит) +
> offset-on-mixed (character через builder array) + key-type. **Tier-3 cold-smoke на testbot
> (killswitch ON через SQL → `php spark tasks:run`):** char 491 получил 6/7 достижений
> (quest_novice НЕ выдан — <3 квестов, критерий корректно дискриминирует) ✅; **per-player
> батч-уведомление** «🏅 Новые достижения!» одним сообщением (6 в списке) ✅; **idempotent**
> re-run → 0 новых, без дубля ✅; cap соблюдён (awarded=10 to 2 chars < 25) ✅. **Урок:**
> GameSettings 60s-кэш → killswitch-флип виден через ≤60s / `cache:clear` (важно для активации
> W10). testbot восстановлен dormant+clean. **🐛 Hotfix `v0.51.293`** (user-репорт скриншотом):
> убрана redundant inline-кнопка «👤 Персонаж» из уведомления — «Перс» уже зафиксирован в
> постоянной reply-клавиатуре (StartCommand:40). Новое правило memory
> [[mmorpg-vault/claude-memory/feedback_no_duplicate_persistent_keyboard_buttons]]. **Killswitch
> OFF на prod → 0 player-эффекта до W10** (зеркало W3a). **Doc:** ADR-066 + decisions/index +
> [[mmorpg-vault/tech-writing/services/AchievementService]] + tasks/achievements cron-нота +
> hot.md + daily + ROADMAP §0. **🏁 W9/30, Фаза 2 — 4/5.** Дальше: **W10 Achievement T1 set**
> (20+ medals + UI «🏅 Достижения» в карточке перса + активация killswitch + анонс) — закрывает
> Фазу 2. ИЛИ пауза.
>
> **W10 SHIPPED prod dormant 2026-05-28 (`v0.51.294`) — 🏁 ЗАКРЫТИЕ ФАЗЫ 2 (Onboarding &
> Achievement, 5/5).** Контент-пасс T1 + UI поверх W9 foundation. **Сделано (1 seed + 1 NEW
> handler + 3 PATCH):** seed +14 достижений (W9 7 → **21 total**): тиры char_level 10/25/50,
> quests 10/30/50, explored_cells 100/500/1000, craft_total 10/50/200, gold_total 50k/500k.
> `AchievementService::currentValue(charId, criteria_type)` — прогресс X/Y для locked.
> NEW `AchievementsAction` (callback `achievements`): группировка по 5 категориям (Прогресс/
> Разведка/Крафт/Экономика/Фракции), открытые «✅ {icon} title (+points)» / закрытые «🔒 {icon}
> title — X/Y», сводка «Открыто N/всего · Очки P». edit-in-place, **БЕЗ inline-дублей**
> постоянной reply-клавиатуры (правило feedback_no_duplicate_persistent_keyboard_buttons).
> `CharacterService` +кнопка «🏅 Достижения» в Перс (gated isEnabled, запакована с «📚 Что
> нового» по 2 в строку). `CallbackRoutes` +exact `achievements`. **+3 unit** (currentValue).
> **Tier-1 ✅ 984/984 PASS (+3), 8090 assertions, phpstan L9 NO ERRORS, php -l все файлы.**
> **Tier-3 cold-smoke на testbot (killswitch ON via SQL + cache:clear → tasks:run):** 21 medals
> засеяны ✅; char 491 → 14/21 unlocked; Перс card «🏅 Достижения» (запакована с «📚 Что нового») ✅;
> экран рендерит сводку «14/21 · Очки 410» + 5 категорий + открытые (+points) + закрытые с реальным
> прогрессом (Первопроходец 470/500, Магнат 84083/500000, Искатель 1/3) ✅. testbot восстановлен
> dormant+clean. **Активация (user-pick AskUserQuestion): ship dormant** — killswitch остаётся OFF,
> кнопка/выдача невидимы; активация в конце ROADMAP вместе с консолидированным анонсом
> (`feedback_announce_after_roadmap_done` — не выкатываем mass per-player уведомления 342 игрокам
> мид-роудмеп). **Doc:** [[mmorpg-vault/decisions/ADR-066-Achievement-system-foundation]] W10
> секция + [[mmorpg-vault/tech-writing/handlers/achievements/AchievementsAction]] +
> AchievementService нота + hot.md + daily + ROADMAP §0. **🏁 W10/30, Фаза 2 Onboarding &
> Achievement ЗАКРЫТА (5/5: W6 audit + W7a/W7b onboarding + W8 inventory + W9/W10 achievements).**
> Дальше: 🌳 Фаза 3 — Quest T2 & Caravan-V2 (W11 Quest T2 foundation) ИЛИ пауза.
>
> Префикс `W` (W1..W30) — чтобы tracker уникально различал vNext / vNext / vNext2. Журнал заполняется по мере follow-through (как v1 §0 и vNext-журнал).

---

## §0. Методология приоритезации (5 осей — без изменений от v1/vNext)

Каждая кандидатная тема оценена по 5 осям; итог = сумма. Top-кандидаты группируются в фазы по зависимости (foundation-темы — раньше).

1. **Lore-debt** (+1..+5) — закрывает ли канон/code mismatch.
2. **Архитектурная зависимость** (+1..+5) — unlock'ает ли несколько следующих тем.
3. **Stack-fit** (−3..+3) — реюз CI4/PSR/Longman vs новая внешняя зависимость.
4. **Setting-coherence** (−5..+5) — укладывается ли в постапок «Найденная фотоплёнка».
5. **Player impact** (+1..+5) — сколько из 10 портретов выигрывает; контрактные (П2/П3/П9) не страдают.

### Входные данные: открытые tail'ы из vNext (V1–V29)

См. подробно `mmorpg-vault/inbox/2026-05-26-roadmap-vnext-retrospective.md`. Кратко:

- **🚁 Drone** — user-инициатива 2026-05-26 (см. vNext §2.7 Inbox); полная карточка + 5-осевая оценка Σ=14 уже зафиксирована.
- **PvP-серия (RNG-fence)** — никакая vNext-сессия не трогала боёвку; нужна с fixture-rebuild + детерминированные эффекты.
- **Tutorial / Onboarding #11 redux** — после vNext новички не видят 80% механик (25 ADR-features добавлено за период).
- **Quest T2 — branching / multi-step** — V11-V13 заложили linear; net-new infra для player-choice.
- **Achievement / Medal system** — engagement+retention tool.
- **Caravan / Trade routes V2** — V25 minimal, расширение очевидно.
- **Inventory revamp** — stash UI, sorting, weight-cap для outfits.
- **In-game economy reports для игрока** — V21 dashboard только admin.
- **Localization en-US** — GitHub-аудитория, Showcase-PR friendly.
- **Crafted item modifiers / зачарование** — новая ось depth.
- **Player housing customisation** — декор базы.

---

## §1. Ранжирование кандидатов (5-осевой прогон)

| # | Тема | Lore | Arch | Stack | Setting | Player | Σ | Фаза |
|---|---|---:|---:|---:|---:|---:|---:|---|
| 1 | **🚁 Drone-family** (recon + cargo + repair + combat варианты) | 0 | +4 | +2 | +5 | +4 | **15** | 1 |
| 2 | **PvP rebalance & arenas** (фикс RNG-fence + дуэли + PvP-ладдер + faction-warfare) | +1 | +4 | +2 | +3 | +5 | **15** | 4 |
| 3 | **Tutorial / Onboarding #11 redux** (показать 25 vNext-фич новичкам) | +2 | +2 | +3 | +4 | +4 | **15** | 2 |
| 4 | **Quest T2 — branching / multi-step** (player-choice → разные T4-награды) | +2 | +4 | +2 | +4 | +3 | **15** | 3 |
| 5 | **Achievement / Medal system** (engagement + retention) | +1 | +3 | +2 | +3 | +5 | **14** | 2 |
| 6 | **Caravan / Trade routes V2** (multi-resource offer + специализация + faction-aligned) | +1 | +2 | +2 | +4 | +4 | **13** | 3 |
| 7 | **Inventory revamp** (stash UI, sorting, weight-cap) | 0 | +3 | +2 | +3 | +4 | **12** | 2 |
| 8 | **Player housing customisation** (декор базы, косметика) | 0 | +2 | +2 | +4 | +4 | **12** | 5 |
| 9 | **In-game economy reports для игрока** (per-char dashboard slice) | 0 | +2 | +3 | +3 | +3 | **11** | 5 |
| 10 | **Crafted item modifiers / зачарование** (новая ось depth) | +1 | +3 | +1 | +3 | +3 | **11** | 4 |
| 11 | **Localization en-US** (GitHub-аудитория) | +1 | +2 | +2 | +2 | +3 | **10** | 6 |
| 12 | **Fishing as new gather** (новая ось добычи, water-biome) | +2 | +1 | +1 | +4 | +2 | **10** | 5 |
| 13 | **Sound / notification redesign** (Telegram-side tuning) | 0 | +1 | +2 | +2 | +3 | **8** | 6 |
| 14 | **Festival events** (player-driven seasonal) | +1 | +1 | +1 | +3 | +2 | **8** | 5 |

**Отсечка:** все 14 проходят в 30 сессий (с разбивкой каждой темы на 2–4 сессии — drone 4 / PvP 5 / tutorial 2 / quest-T2 3 / achievements 2 / caravan-V2 2 / inventory 2 / housing 2 / economy-player 2 / modifiers 2 / localization 1 / fishing 1 / sound 1 / festival 1).

**Исключено:** Permadeath league (🟠 player-hostile, breaks П2/П3 контракт); 5-я фракция (🔴 ломает 1:1 mapping); paid-only монетизация (🔴 P2W).

---

## §2. Структура vNext2: 6 фаз × 5 сессий = 30

### 🚁 Фаза 1 — Drone family (зафиксирована user'ом 2026-05-26)
Высокий импульс, foundation для нескольких follow-up scout-фич. Все 5 сессий пишутся как overlay-слой (default OFF, killswitch global).
- **W1** — **Drone-recon foundation**: `drone.*` GameSettings, `characters.drone_battery_charge` (lazy charge-on-base), `RecceDroneAction` (запуск с клетки, scan радиус GameSettings), `DroneScoutCompletionHandler` (открывает клетки через ExploredCellsModel). ADR foundation + 7 ворот + 10-портретный чек. Image-tail.
- **W2** — **Cargo drone**: ручная переноска ресурса из дальней клетки на базу (gold-sink альтернатива — поход обходится дешевле, дрон — быстрее, batt-расход). ADR.
- **W3** — **Repair drone**: автоматическое восстановление durability одного робота на базе (overlay V19 ремонт через player-инициируемый дрон). ADR.
- **W4** — **Combat drone (defensive)**: дрон над базой = +X% инициативы защитнику аналогично WatchTower (V28), но активируется по запросу + расход battery. RNG-fence safe (детерминир.). ADR.
- **W5** — **Caravan offer integration** (V25 connect): редкий шанс получить готовый дрон через CaravanService (chance-based; альтернативно — рецепт-чертёж как редкий товар). 🏁 закрытие Фазы 1.

### 🎓 Фаза 2 — Onboarding & Achievement (engagement)
Player-retention foundation. Сессии 6-8 — onboarding redux #11, 9-10 — achievements.
- **W6** — **Onboarding redux #11 — audit + scope**: что добавилось за vNext (25 ADR-фич), что новичок видит / не видит. ADR-decision: extend Роби-онбординга ИЛИ опц. курс «Что нового». Без кода.
- **W7** — **Onboarding redux — implement** (TBD по W6 decision). Image-tail если новые сцены.
- **W8** — **Inventory revamp UI** (stash sorting, output-type табы, weight-cap для outfits). Reuse-only Action'ы.
- **W9** — **Achievement system foundation**: `achievements`, `character_achievements`, `AchievementService` (event-driven hook: first_craft, first_pvp_win, first_caravan_buy, etc.). ADR.
- **W10** — **Achievement T1 set**: 20+ medals (контент-пасс) + UI отображения в карточке перса. 🏁 закрытие Фазы 2.

### 🌳 Фаза 3 — Quest T2 & Caravan-V2 (content-depth)
- **W11** — **Quest T2 foundation**: branching engine (player-choice → разные next-quest). ADR. Reuse `quests.objective_*` (V12).
- **W12** — **Quest T2 — faction-specific branching** (1 цепочка на каждую фракцию = 4 ветви). Image-tail если нужны новые scene.
- **W13** — **Quest T2 — story arc** (multi-step master-quest закрывающий главу 1 «остров»). Эндгейм-капстон.
- **W14** — **Caravan V2 — multi-resource offer** (несколько товаров в одном offer'е + bargain/torg-механика).
- **W15** — **Caravan V2 — faction-aligned**: каравaны имеют faction-affinity (skidka для faction-member; стрельба для враг-faction). 🏁 закрытие Фазы 3.

### ⚔ Фаза 4 — PvP depth & Crafted modifiers
Высокий риск (RNG-fence). Каждая сессия — split на audit/ADR + build (как S26 в v1).
- **W16** — **PvP audit + fixture rebuild plan**: что в боёвке детерминировано, что зависит от mt_rand, какие фикстуры. ADR audit-decision. Без кода.
- **W17** — **PvP duels** (вне основного PvP, opt-in, fairness via stat-equalize или level-bracketing). 🟠 ADR.
- **W18** — **PvP ladder / ranking** (per-faction + global, refresh weekly, broadcast top).
- **W19** — **Crafted item modifiers foundation**: `modifiers` table + UI «Зачарование» (gold + redkij ресурс → +5% к одному стату на конкретном предмете). 🟠 ADR.
- **W20** — **Modifier T1 set** + balance pass. 🏁 закрытие Фазы 4.

### 🏠 Фаза 5 — Housing, fishing, economy-player
- **W21** — **Player housing customisation**: декор базы (косметика, не баланс) — флаги, цвета, баннеры. 0 механики.
- **W22** — **Housing W2** — interior items (стулья / костёр-кастомизация / pet placement).
- **W23** — **Fishing as new gather**: water-biome клетки получают opt-in fishing-task + новый ресурс «Рыба» + 3 рецепта-блюда. 🟠 ADR.
- **W24** — **In-game economy reports для игрока**: per-character slice V21 dashboard (личная инфляция, профит за месяц, top-spendings).
- **W25** — **Economy reports W2** — comparison vs faction-median (anonymized). 🏁 закрытие Фазы 5.

### 🌍 Фаза 6 — Localization, polish, vNext3
- **W26** — **Localization en-US — scope + i18n foundation** (CI4 Language helper, language-key extract из user-facing strings).
- **W27** — **Localization en-US — implement core** (Telegram-side: handlers + actions + сообщения).
- **W28** — **Sound / notification redesign** (Telegram-side: silent threshold, batching, throttle).
- **W29** — **Tech-writing closer vNext2** (zero-drift contract ADR-009 + missing-нот sweep).
- **W30** — **Retrospective v3 + ROADMAP-CRAFT-vNext3.md** (тот же 5-осевой алгоритм поверх W1–W29). 🏁

---

## §3. Контракт (как v1 / vNext)

- Каждая сессия: **audit-first** (SSH-прод + grep + Glob ДО follow-through — ~30% сессий v1+vNext имели drift; норма, не баг).
- 🟠-сессии → ADR + validation-card (7 ворот) ДО кода.
- Balance-параметры → **GameSettings** (constitutional ADR-024), не магические числа.
- Tech-writing нота синхронно (ADR-009). Картинки — стиль «Найденная фотоплёнка» (ADR-022).
- Smoke-tiers: composer test + phpstan L9 → testbot e2e → prod + verify (HTTP + Tier-3 real-Telegram если UX-видимое).
- **Анонсы игрокам — батчем в конце** (после W30), не по ходу (`feedback_announce_after_roadmap_done`).
- Throwaway smoke-команды — **scp → run → delete**, не через git/CI (L9-gate; урок S26b).
- PvP-related сессии (W16-W18) — RNG-fence обязателен, fixture-rebuild через `?param=null`-default.

## §4. Источники

- vNext итоги: `mmorpg-vault/reference/archive-roadmaps/ROADMAP-CRAFT-vNext.md` §0 (V1–V29 журнал — закрыт V30, перемещён в архив), retrospective `mmorpg-vault/inbox/2026-05-26-roadmap-vnext-retrospective.md`.
- v1 итоги: `mmorpg-vault/reference/archive-roadmaps/ROADMAP-CRAFT-v1.md` §0 (S1–S29, архив), retrospective `mmorpg-vault/inbox/2026-05-20-roadmap-v1-retrospective.md`.
- Алгоритм: общий 5-осевой (v1 §S30, vNext §0). Валидация: `GAME_RULES_AND_VALIDATION_FRAMEWORK.md` (7 ворот, 10 портретов).
- Drone-идея full-card: `mmorpg-vault/reference/archive-roadmaps/ROADMAP-CRAFT-vNext.md §2.7 Inbox` (зафиксирована user'ом 2026-05-26 на финише vNext).
