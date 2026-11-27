# Relic economy: why veterans hold thousands of «rare» relics (report)
**Brief:** [brief.md](brief.md) · **Date:** 2026-11-27 · prod read with SELECT only; nothing changed on prod or testbot.

## Short answer
The relics (69 «Изотопное ядро „Дракон-4“», 70 «Архивный носитель Предтеч», 71 «Релейный венец Глубины») are not a
rare drop in the code. They are ordinary cave gather resources behind a level gate (55/75/100), and every gather
credits **every** eligible resource with a fixed quantity. A level-100 character on a 12-hour cave gather gets
~144 of each, ≈ **1.16M gold** at bank price — about **5×** the bank value of everything else the cave gives in the
same gather. Canon, the collection and the site call them «находят раз в жизни». No exploit is involved: the big
holders are exactly the characters above the gates.

## 1. How relics are obtained

**Gather (the main source).**
- Candidates: every resource with the cell's biome and `level_required <= level`
  (`app/Services/Player/Gather/GatherCellResourceQuery.php:59-61`); no filter on type, keyword or rarity.
- No pick: `app/TaskHandlers/GatherTaskHandler.php:457-614` credits each candidate. Per 10 minutes
  `getBaseQuantityByRarity()` — rarity 1 → 2 units (`GatherFormulaService.php:83-97`), then ×rand(0.8–1.2), event and
  health/tiredness factors (≤1.25). Cells have no stock; `initial_quantity` is not read here.
- Longest gather 720 min (`GatherAction.php:152`) → 72 blocks → **144 units of each relic**.
- The rarity→quantity table is hardcoded; no `GameSettings` key can lower relic yield alone (ADR-024 gap).
- Design assumed the opposite: `S10AddRareResources.php:13-15` «ресурсы НЕ через `GatherTaskHandler`».

Prod 2026-11-27, holders by level (rows `character_resources`, quantity > 0):

| char | level | 69 (gate 55) | 70 (gate 75) | 71 (gate 100) |
|---|---|---|---|---|
| 1107 | 288 | 3 907 | 3 893 | 2 163 |
| 1104 | 225 | 1 474 | 1 350 | 1 429 |
| 1115 | 132 | 558 | 537 | 555 |
| 1280 | 59 | 172 | — | — |
| 644 | 651 | 111 | 136 | 105 |
| others (12 chars, levels 2–167) | | 1 each | 1 each | 1 each |

1280 (level 59) holds only the one relic it can gather — the level gate is the source. Units of 1 below the gates
come from the event.

Cave income of one 12-hour gather at level 100 (prod `sell_price` × 144 or the rarity quantity × 72):

| resource | units | gold |
|---|---|---|
| 71 Релейный венец | 144 | 684 000 |
| 70 Архивный носитель | 144 | 273 600 |
| 69 Изотопное ядро | 144 | 205 200 |
| everything else in caves (24 resources) | | ≈ 230 000 |

**Other sources (minor).**
- Event `RevealingHiddenCameras` (`app/Config/WorldEvents.php:552-567`): 1–3 units, 10% per tick while gathering in
  caves. `IntentApplier.php:167-186` does not check level, so a level-2 character can get relic 71 this way.
- Boss 703 loot: relic 69 ×1–2 at 100%, relic 71 ×1 at 15% (`SeedBossLootTables.php:43,45`).
- Robot gathering has no `level_required` filter (`CompleteRobotGatheringHandler.php:231-236`); whether robots yield
  relics on prod is **not confirmed**.
- Bank purchase: allowed from the gate level (`ResourceTradeService.php:345-359`, `economy.shop.buy_level_gate_enabled`),
  infinite stock (`ResourcesBankModel.php:56-67`); buy price ≥ 1.05 × base.

## 2. What relics are for
- Craft: none (`app/Config/CraftRecipes.php` names no relic).
- Collection «Сокровища глубин»: ×1 of each, once per character (`Adr119SeedCollectionsContent.php:52-65`).
- Bank sale: dynamic price base × min(1, ratio) × 0.95, floor 0.35 × base (`ResourceBankUpdateHandler.php:108-137`),
  no daily sell cap. Today 1 425 / 1 900 / 4 750.

So the only sink of any size is selling to the bank: relics are a gold faucet.

## 3. Gold already paid for relics
Source: `action_log` `SELL_RESOURCE` rows in the current format «Продажа: {name} ×{qty}; +{gold} золота» (since
2026-08-24, `ResourceTradeService.php:270-276`), matched by the old names (rows predate the rename).

| | gold |
|---|---|
| all relic single sales since 2026-08-24 | 186 402 337 940 |
| of it, char 798 on 2026-09-25 (bank pump, F6) | 186 367 892 075 |
| **honest relic sales** | **≈ 18.4M** (1107 — 12.26M, 644 — 6.16M, 1104 — 36k) |

- The 798 rows are the bank pump closed by `hotfix-bank-pump-f6` (v0.51.691, 2026-10-08); its gold was taken back
  (`docs/specs/hotfix-bank-pump-f6/rollback.md:26-33`). Not a live hole: today buy ≥ 1.05 × base > sell ≤ 0.95 × base.
- Not counted: bulk sales (they list at most 5 items, «и ещё K» — one prod row includes «Сердце Дракона ×227» in a
  1.07M total) and the legacy format 2026-06-09…08-24 (no per-resource split). Before 2026-06-09 sales were probably
  not logged (not confirmed).
- Stock still held: 6 229 / 5 990 / 4 258 units ≈ **40.5M gold** at today's sell price. For scale, all gold in the
  world after the F6 rollback was 197M (`rollback.md:33`); today's richest character holds 105M (char 1115).

## 4. Same pattern elsewhere
The four S10 «event-only» resources are gatherable the same way (rarity 1, no type filter): ТВЭЛы (volcanoes, 18),
Доколлапсная электроника (caves, 20), Промышленный пластик (tropics, 15), Медицинское сырьё (mountains, 18) —
texts say «Кустарной замены не существует» (`S10AddRareResources.php:36-80`). Their prices are low (электроника 285),
so the gold effect is small (electronics: 41k per 12h), but the canon is equally wrong. Belt resources (Пепел
Предтеч, Кристалл Разлома) are behind `world.belt_resources.enabled` = off and are not farmable now.

## 5. Canon against code
- Says rare/event: `Adr119SeedCollectionsContent.php:54` «То, что находят раз в жизни»; ADR-119:28-29,48
  «редчайшие event-находки»; `lore/craft/Редкие-ресурсы.md:30` (source = event); site cave page «самая престижная
  и труднодоступная находка».
- Says keep in gather: ADR-198 (proposed 2026-11-01) keeps `biome_id`/`level_required`, value = bank and collection,
  rejects removal «держателей мало из-за порога уровня».

## 6. Options (recommended first)

**A (recommended) — honest canon + an admin lever, no change in income today.**
Rewrite the relic texts (collection, lore, site, `GAME_DESCRIPTION.md`) to «тяжёлая добыча глубоких пещер для
ветеранов»; add `GameSettings` key for relic gather yield (e.g. `gather.relic_yield_multiplier`, default 1.0 = as now,
full rationale/above/below, hard bounds), so the owner can tune it from the admin panel later. Existing stock untouched.
- Why: ADR-198 already chose «keep in gather»; at WAU ≈19 the 3–4 active veterans are the core audience and a sudden
  ~80% cut of their cave income is a retention risk; the yield becomes tunable as ADR-024 requires. 🟡, ADR-198 is
  accepted with an amendment.
- Minus: the faucet keeps running at ≈1.16M per 12h for each level-100 gatherer until someone turns the lever.

**B — cut the yield now (same key, default below 1.0, e.g. 0.1–0.25), stock kept.**
Relics become genuinely scarce in gather; canon «редкая находка» becomes true. Needs an ADR amendment and an
announcement to holders. Minus: veterans lose most of their cave income overnight; the 40.5M in stock still sells.

**C — event-only (exclude relics from gather), stock kept.**
Matches the original design and S10 comment. Needs ADR (reverses ADR-198) and a type/keyword filter in the gather
query, which also catches the S10 resources. Minus: the hardest cut for veterans; boss 703 and the event remain the
only sources; the stock is still sellable.

**D — lower the bank base price, drop untouched.**
Cuts both the faucet and the value of stock (the 40.5M shrinks with it). Minus: hits held stock retroactively;
prices live in `resources` rows, not `GameSettings`.

Any option also needs: the level check in `IntentApplier` (event gives relic 71 to level 2), and the S10 texts.

## Not read or not confirmed
- Whether robot workshops yield relics on prod; tool rows bonusing relic names; whether well-fed / weight cap are on.
- Gather counts per character (`character_tasks` keeps only recent rows; 1107 has 4) — yield is computed from code,
  not measured from logs.
- Sales before 2026-06-09 and relic units inside bulk-sale rows.
- Current total world gold (not queried this time).
