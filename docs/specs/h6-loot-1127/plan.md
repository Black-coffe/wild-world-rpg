# H6.1 — strategic-object loot gives what it says (plan)
**Brief:** [brief.md](brief.md) · **Branch:** `vulyk/h6-loot-1127` · **Approved:** «Одобряю (Рекомендую)», 2026-11-27

## Goal
Strategic objects hand out loot from `world_objects.contents`. Several keys resolve to nothing on prod. Resource
misses are announced without being given, and crafted-item misses (including two weapons) disappear without a
word. This spec makes the handler honest: it grants each entry, lists only what it granted, and logs every miss.
It adds a weapons section that reuses the PvE weapon grant, and fixes the seed data with an idempotent migration so
that every key resolves.

## Assumptions
- `ResourceModel::addOrIncreaseResource` returns `false` on an unknown `name_en` (`ResourceModel.php:164-169`). The
  sibling `RuinLootService.php:173` already checks it.
- Weapon grant: `LootTableService::grantWeapon` (`app/Services/PVE/LootTableService.php:139`, protected) upserts
  `characters_weapons` (+1 qty, full durability, not equipped). It is moved into a small shared public helper with the
  same behaviour, and PvE keeps calling it, so PvE behaviour does not change. Bunker weapons give `rand(1, max)`
  units, the same rule as every other entry. The max values (PipeGun 2, MetalSpear 3) are existing seed data, not
  new code numbers.
- Prod facts (SELECT 2026-11-27): weapons PipeGun id 3, MetalSpear id 1; crafted `FirstAidKit` id 2 «Аптечка базовая»;
  crafted `wiring` id 84, `metalFragments` id 72, `electronicComponents` id 83 (the collation is case-insensitive);
  resource id 30 «Кристаллы» has `name_en` NULL, and no row has `name_en='Crystals'`.
- `Crystals` is fixed by setting `resources.name_en='Crystals'` on the «Кристаллы» row (`WHERE name='Кристаллы' AND
  name_en IS NULL`), because the lookup is by `name_en`. Technopark `Wiring`: the resource entry is dropped and the
  crafted entry keeps `max(20, 40) = 40`.
- IslandHeart has no live spawns (no map rows; it is part of D3-ON). It is fixed now, so it is correct before it is
  turned on. Its dispatch path (missing from `ObjectDiscoveryService`'s map) is not part of this work.
- No compensation (owner, 2026-11-27). No GameSettings keys are added: loot amounts are seed data and are not changed.
- Wording elsewhere (H6.2 canon, H6.3 renames) is not part of this work.
- Goes live: a merge into `develop` does nothing by itself. Push to `develop` deploys to preprod and `post-deploy.sh`
  applies the migration there. A tag deploys to prod. Each step is taken only on the owner's word.

## Stories
- [ ] S1 Handler grants honestly + weapons section - asks: 2, 3, 6
  - files: `app/TaskHandlers/Objects/StrategicLootHandler.php`, `app/Services/PVE/LootTableService.php`, new shared
    weapon-grant helper (e.g. `app/Services/Inventory/WeaponGrantService.php`),
    `tests/unit/TaskHandlers/StrategicLootResourceNamesTest.php`, a new database test for the weapon section
  - test first: (a) an unknown resource key is NOT in the message and logs an error. This fails today: the existing
    test `testUnknownResourceKeyStaysRawWithoutFatal` expects `— *NoSuchResourceXyz*: 4 шт.` and is rewritten. (b) A
    `weapons: {PipeGun: 2}` entry adds `characters_weapons` rows and lists «Трубчатый пистолет…: N шт.». This fails
    today because the section is ignored. (c) PvE `LootTableService` weapon drop still works after the move (an
    existing test, or a new one if none).
  - check: `vendor/bin/phpunit --no-coverage --no-progress tests/unit/TaskHandlers/StrategicLootResourceNamesTest.php` + the new DB test file
- [ ] S2 Idempotent seed fix migration - asks: 1, 4
  - files: `app/Database/Migrations/2026-11-27-*_H6FixStrategicLootKeys.php`, a unit test of its pure transform
  - test first: the pure function `fixContents(array): array` applied to the five prod-shaped contents gives the
    expected result, and applying it twice gives the same result. It fails before the function exists.
  - check: the unit test + `php -l` on the migration + `php spark migrate` locally; later on preprod, the resolve
    SELECT from the brief returns 0 NULL rows
- [ ] S3 Docs and verdicts - asks: 5, 7
  - files: `mmorpg-vault/tech-writing/handlers/StrategicLootHandler.md`, the note for the weapon-grant helper (new) and
    `services/LootTableService.md` if it exists, `tech-writing/db/world_objects.md`; ROADMAP H6 line (1) wording fixed
  - test first: none: docs only
  - check: `git diff --stat` shows the notes; `last_reviewed: 2026-11-27`

## Test command
`vendor/bin/phpunit --no-coverage --no-progress <file>` per story; before ship the touched suites (unit2 + db parts
holding the new tests) and `vendor/bin/phpstan analyse --memory-limit=512M --no-progress`. Last run today:
`StrategicLootResourceNamesTest` — `OK, but there were issues! Tests: 2, Assertions: 13, PHPUnit Deprecations: 1`.

## Descoped
(none)
