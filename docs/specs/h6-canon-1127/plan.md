# H6.2 — canon and lore match the code (plan)
**Brief:** [brief.md](brief.md) · **Branch:** `vulyk/h6-canon-1127` · **Approved:** «Одобряю (Рекомендую)», 2026-11-27
**Shipped:** 2026-11-27, 40663f6a

## Goal
`GAME_DESCRIPTION.md` and `mmorpg-vault/lore/` were last synced at v0.51.148. Bring them in line with the code and with
prod's switches as of 27.11. Five wrong claims are corrected. Seven shipped systems get short sections, each saying
whether it is on or off on prod. Branching quests are described as «готовится». One live tip that repeats the T4
claim is fixed. The three renames (H6.3) are not done here.

## Assumptions
- Prod switches are those in brief `## Answers` (SELECT 27.11). Code defaults are never used as live state.
- Code truths come from recon, as follows:
  - **Tiers:** there is no T4. Per ADR-130 there are 2 workbenches and 3 craft levels. The endgame craft is gated by
    the *first* capture quest and `ProfessionalWorkbench` (`CraftRecipes.php:1861…2107`).
  - **Food order:** food is eaten in descending `rarity` number (`FoodAndWaterConsumptionHandler.php:148`). In the DB a
    higher number means more common, so common food goes first.
  - **Faction PvP modifier:** there is none in live PvP (`PvpFormulaService`, `PvpDamageCalculator`). The admin
    simulator `PvPController.php:193` compares with `'Militia'` while prod faction names are Russian, so the modifier
    is 1.0.
  - **Northern Lights:** gives one random boost per player (`WorldEvents.php:392`, `AttributeBoostEffect.php:44`).
    The numbers live in `Config\WorldEvents` and are not quoted.
  - **`:746` list:** Старая ферма→Фермеры is missing, and IslandHeart gives no faction points (`EndgameScoring.php:61`).
- New sections describe what the player does, with no tuning numbers. Their sources are the ADRs:
  - ADR-137 Узлы
  - ADR-101 поселения
  - ADR-133 Оракул
  - ADR-135 подать
  - ADR-174 транспорт (the existing `:47-52` paragraph is expanded)
  - ADR-146 рефералы
  - ADR-072 рейтинг PvP
- **Placement** (the existing headings are kept):
  - поселения: after «Исследование мира»
  - Узлы, рейтинг, подать: by «Боевая система»
  - Оракул: in «Развлечения»
  - рефералы: by «Достижения»
  - W11/W12: in «Система квестов»
- The sync stamp (`GAME_DESCRIPTION.md:771`) becomes «на v0.51.718, 2026-11-27». It is v0.51.718 if H6.1 is tagged first.
  If not, it uses the current tag.
- Not part of this work:
  - the H6.3 renames
  - `/guide` lore section
  - the `GameSettings` text at `W13SeedIslandHeartSpawnGameSettings.php:36` that promises +500. That one goes to the
    ROADMAP as a tail for D3-ON.
- **Goes live:** canon and lore are docs, so merging changes nothing for players. The tip migration (S3) applies on
  preprod at push and on prod at tag, each on the owner's word.

## Stories
- [x] S1 GAME_DESCRIPTION.md: five corrections + seven sections + W11/W12 «готовится» + stamp - asks: 1, 2, 3, 5, 6, 7
  - files: `GAME_DESCRIPTION.md`
  - test first: none: prose only. Check by grep: no «T4», no «мистич», no «Фракционные модификаторы» as a live claim;
    «Старая ферма» in the `:746` list; each of the 7 systems has a heading with its on/off state
  - check: `grep -nE "T4|мистич|Приоритет по редкости" GAME_DESCRIPTION.md` returns only lines stating the corrected truth;
    `php scripts/defects-bot-lexicon-check.php` is not relevant (not player text)
- [x] S2 Lore notes - asks: 4, 5
  - files:
    - fix: `mmorpg-vault/lore/food/Питание-и-выживание.md:18`, `lore/factions/Фракции.md:42`, `lore/combat/PvP.md:112`,
      `lore/events/Мировые-события.md:28`, `lore/endgame/Endgame-scenarios.md` (strategic objects lines)
    - add: new notes for the 7 systems (or link existing ADR from a note), plus `lore/index.md` links
  - test first: none: docs only
  - check: `grep -rnE "мистич|сначала потребляются \*\*редкие" C:/Projects/mmorpg-vault/lore` is empty; `lore/index.md` links each new note
- [x] S3 Tip 47 without T4 - asks: 8
  - files: `app/Database/Migrations/2027-01-02-100000_FixEndgameCraftTipNoT4.php` (prefix after the latest, unique)
  - test first: a unit test that the migration's new title and text contain no «T4», no digits, and name the
    Профессиональный верстак. It fails before the class exists. Idempotent: `UPDATE … WHERE title_en='EndgameCraftT4'`
    with the final values
  - check: the unit test + `php -l` on the migration; after deploy `SELECT title_ru, content FROM game_tips WHERE title_en='EndgameCraftT4'` on preprod

## Test command
`vendor/bin/phpunit --no-coverage --no-progress <file>`; `git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`

## Descoped
(none)
