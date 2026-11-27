# H6.3: post-apocalyptic names for three artifacts + English-in-brackets class (plan)
**Brief:** [brief.md](brief.md) · **Branch:** `vulyk/h6-relics-1127` · **Approved:** «Одобряю (Рекомендую)», 2026-11-27

## Goal
Rename the three fantasy relics (resources 69/70/71) to the roadmap's post-apocalyptic names and rewrite their
descriptions, in place by id, so no holder loses anything, and update `collection_slots` 4/5/6 (they match
resources by name string) in the same migration so the «Сокровища глубин» collection keeps working. Remove the
English-in-brackets weapon names (ids 3, 10), the hardcoded «(Pipe Gun)» and task 56's text. Sync canon, site and vault.

## Assumptions
- Prod recon 2026-11-27 (SELECT only): holders 69 → 12 chars / 6229 units, 70 → 12 / 5990, 71 → 10 / 4258, linked by
  `character_resources.id_resources`; slots 4/5/6 in collection 2 have 10 donations (`character_collection_slots`
  keys by slot id, so completion survives). Weapons with brackets on prod: 3 «(Pipe Gun)», 10 «(Firebomb)»; 6
  «Обрез (усиленный)» is Russian and stays. Holdings of characters_weapons 3/10: 11 rows.
- `collection_slots.resource_name` and `resources.name` have different collations (general_ci vs unicode_ci); the
  migration updates by literal old value, never by a join.
- The display name is `resources.name` / `weapons.name` straight from the DB; no Config/LEXICON/ImageRegistry key.
  `dragon_heart.png` is an orphan not in ImageRegistry — left alone.
- The migration is a data UPDATE `WHERE name = <old>` (idempotent; down() restores old values). Prefix after
  `2027-01-02-100000` → `2027-01-03-100000`.
- `app/Data/site_canon/*.html` is applied only by a manual `php spark` command, not on deploy; the migration
  therefore also updates `site_posts` content (by slug) so the site changes on deploy.
- Historic migrations that name the relics (`PopulateResourceIcons`, `S10SeedRareEventGameSettings` label,
  `SeedBuyLevelGateGameSettings` text) are not edited; the live `game_settings` description row that says
  «Корону подземного короля» is updated by the new migration.
- Not part of this work: the unusually large holdings (thousands of units of «once in a lifetime» items) — noted
  for the owner, not touched; the `/guide` lore section (ROADMAP: «позже»); `first()` without orderBy in
  `getResourceForCraft`.
- No new table or column → `WipeManifest` untouched. No balance numbers touched.
- Verdicts: `/guide` — no (lore section is later per ROADMAP; no mechanic changes). Tips — no: a rename adds no
  mechanic; no existing tip names these items (recon: 0 matches).
- Goes live: merge to develop does nothing by itself; push to develop deploys to preprod and runs the migration;
  prod gets it only by a tag, each step on the owner's word.

## Stories
- [x] S1 Relics renamed in place, collection slots follow - asks: 1, 1a, 2, 3, 5
  - files: `app/Database/Migrations/2027-01-03-100000_H6RenameRelicsAndBracketWeapons.php` (relic part),
    `tests/database/H6RenameRelicsMigrationTest.php`, `tests/database/LootTableServiceTest.php` (fixture name),
    `GAME_DESCRIPTION.md:556-557`, `app/Data/site_canon/syre-biom-peshhery-…html`
  - test first: on a DB with old rows + holders + slots + a donation, running the migration (twice) leaves holder
    rows/quantities identical, sets new names/descriptions, slot names equal new `resources.name`, and
    `CollectionService` sees the held quantity for a holder; fails before because the migration does not exist.
  - check: `vendor/bin/phpunit --no-coverage --no-progress tests/database/H6RenameRelicsMigrationTest.php tests/database/LootTableServiceTest.php` and `php -l` of the migration
- [x] S2 English-in-brackets weapons class - asks: 4, 5
  - files: same migration (weapons 3, 10; tasks 56; game_settings / site_posts text rows),
    `app/Controllers/Telegram/Commands/Actions/…/WeaponsCraft2Select.php:23`, the migration test
  - test first: after the migration no `weapons.name` matches `\([A-Za-z]` and task 56 has no «Pipe Gun»; a unit
    check that WeaponsCraft2Select text has no Latin in brackets; fails before on the current rows/hardcode.
  - check: `vendor/bin/phpunit --no-coverage --no-progress tests/database/H6RenameRelicsMigrationTest.php tests/database/StrategicLootWeaponsTest.php`
- [x] S3 Canon, vault and tech-writing sync - asks: 1, 6
  - files: `C:/Projects/mmorpg-vault/lore/craft/Редкие-ресурсы.md`, `tech-writing/services/CollectionService.md`,
    `tech-writing/services/LootTableService.md`, `ROADMAP.md:257` (mark (3) done after ship is ship's job — here only
    canon text), `LORE_AND_GAMEPLAY_ROADMAP.md` G1.1 marked done
  - test first: none: documentation only
  - check: `git grep -F "Сердце Дракона" -- app GAME_DESCRIPTION.md` returns only historic migrations and comments
  - outside: vault `tech-writing/handlers/craft/WeaponsCraft2Select.md` (new) and `tech-writing/db/collection_slots.md`
    (new, + row in `tech-writing/db/index.md`) — tech-writing rule for the controller and the migration touched in S1/S2.
  - G1.1 marked done for the relic reskin only: its last point (trophies tied to collections and Strongholds) is not
    this work and stays open; the «сияние» point is H6.2's, not re-marked.

## Test command
`vendor/bin/phpunit --no-coverage --no-progress tests/database/LootTableServiceTest.php tests/database/StrategicLootWeaponsTest.php` (today: OK, 10 tests, 47 assertions, 1 PHPUnit deprecation)

## Descoped
(none)
