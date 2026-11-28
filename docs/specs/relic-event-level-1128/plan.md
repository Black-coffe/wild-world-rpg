# Relic event ignores character level — hotfix (plan)
**Brief:** [brief.md](brief.md) · **Branch:** `vulyk/relic-event-level-1128` · **Approved:** no

## Goal
World events of kind `rare_resource_grant` pick a random resource by keyword/biome/rarity with no level check
(`app/Services/Events/IntentApplier.php:167-186`), so `RevealingHiddenCameras` can hand relic 71 (level 100) to a
level-2 character; the other S10 events (`PreCollapseVaultOpening` lvl 20, `IndustrialDumpFind` lvl 15, …) share
the gap. Apply gather's own rule — `resources.level_required <= characters.level`
(`app/Services/Player/Gather/GatherCellResourceQuery.php:59-61`) — at both ends: the effect skips the grant when no
candidate fits the character (so the event summary never says «+N» for nothing), and `IntentApplier` picks only from
fitting candidates.

## Assumptions
- Prod facts (SELECT, 2026-11-28): the event matches exactly relics 69/70/71 (levels 55/75/100, biome 7). Characters
  below the required level hold 13 units on 6 characters (645, 705, 1027, 1039, 1061, 1145; 1 unit per row). Seven of
  them share `updated_at = 2026-06-26 22:42:03` across three characters — a bulk write, not the event; the rest
  (Aug–Nov) may be event, boss 703 or bank purchase — source not provable from data. Value at sell price ≈ 33k gold.
- The proceeds are left as they are (ask 3: «left as is unless told»); the approval question offers the choice.
- The candidate query is shared by the effect and `IntentApplier` (one helper), so the two checks cannot drift.
- Level comes from the character row already in hand (`EventDispatcher.php:228,389-394` loads the full row); no new
  query for the level, no new GameSettings key.
- Not part of this work: robot gathering's missing level filter (`CompleteRobotGatheringHandler.php:232-236`, goes to
  the option A spec), boss loot, gather's `FIND_IN_SET` vs exact `biome_id` difference.
- Guide verdict: нет — backend fix, no new mechanic or screen. Tips verdict: нет — nothing new for a player to learn;
  the relic tip/texts are in the option A spec.
- Goes live: merge to `develop` does nothing by itself; push to `develop` deploys to preprod, a tag deploys to prod —
  each on the owner's word.

## Stories
- [ ] S1 Event grant respects the resource's required level - asks: 1, 2, 4
  - files: `app/Services/Events/IntentApplier.php`, `app/Services/Events/Effects/RareResourceGrantEffect.php`, a shared
    candidate query (e.g. method on `app/Models/ResourceModel.php`), `tests/database/IntentApplierRelativeDeltasTest.php`
    (+ `ensureColumns` gets `resources.level_required`, `characters.level`), `tests/unit/Services/Events/Effects/RareResourceGrantEffectTest.php`
  - test first: level-2 character, candidates at levels 1 and 100 → only the level-1 one is ever granted; only a
    level-100 candidate → nothing granted and the effect returns skipped (no `resource_grant_intents`); level-100
    character → grant as before. Fails today because the query has no level filter.
  - check: `vendor/bin/phpunit --no-coverage --no-progress tests/database/IntentApplierRelativeDeltasTest.php tests/unit/Services/Events` and `vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
- [ ] S2 Docs and prod evidence - asks: 3, 5
  - files: `C:/Projects/mmorpg-vault/tech-writing/services/IntentApplier.md` (level rule, `last_reviewed`),
    `C:/Projects/mmorpg-vault/tech-writing/services/RareResourceGrantEffect.md` if it exists, `docs/specs/relic-event-level-1128/evidence.md` (the prod SELECT results above)
  - test first: none: documentation only
  - check: `git -C C:/Projects/mmorpg-vault diff --stat` shows the notes changed

## Test command
`vendor/bin/phpunit --no-coverage --no-progress tests/database/IntentApplierRelativeDeltasTest.php tests/unit/Services/Events`

## Descoped
(none)
