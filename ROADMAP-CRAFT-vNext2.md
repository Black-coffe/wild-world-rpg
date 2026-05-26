# ROADMAP-CRAFT-vNext2.md — следующие 30 сессий (v3)

> Синтезирован в **V30** (2026-05-26) — финальная сессия ROADMAP-CRAFT vNext (V1–V29 shipped, прод-теги v0.51.213 → v0.51.265).
> Приоритезация — через тот же **5-осевой алгоритм** (см. v1 §S30 + vNext §0).
> Полные 17-секционные карточки каждой сессии пишутся в начале соответствующей фазы; здесь — **приоритезированный outline** (методология + ранжирование + 6×5 структура).

---

## Журнал сессий vNext2

| # | Сессия | Статус | Тег | Дата |
|---|---|---|---|---|
| W1 | (см. Фаза 1) | ⏳ pending | — | — |
| … | … | … | … | … |

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
