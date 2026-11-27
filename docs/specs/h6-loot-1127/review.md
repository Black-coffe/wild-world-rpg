# H6.1 review

## Round 1 - 2026-11-27 - head 559e0cbf2d06845619f733f24a212010f803d3cd

```
VERDICT: PASS
TESTS: vendor/bin/phpunit --no-coverage --no-progress tests/unit/Database/H6FixStrategicLootKeysTest.php tests/unit/TaskHandlers/StrategicLootResourceNamesTest.php tests/database/StrategicLootWeaponsTest.php -> pass, 17 tests / 58 assertions
       vendor/bin/phpunit --no-coverage --no-progress tests/database/LootTableServiceTest.php tests/database/StrategicLootHandlerEntityContractTest.php tests/unit/Endgame/StrategicObjectWiringTest.php -> pass, 11 tests / 32 assertions
       vendor/bin/phpstan analyse --memory-limit=512M --no-progress -> pass, no errors
       php -l app/Database/Migrations/2027-01-01-100000_H6FixStrategicLootKeys.php -> pass

## Asks
1. delivered: read-only SELECT on the local DB (migration applied, contents identical to the unit test's expected output) gives 0 unresolved keys across all five objects; prod not checked, migration not deployed. app/Database/Migrations/2027-01-01-100000_H6FixStrategicLootKeys.php:36
2. delivered: each of the three sections appends to the found list only after a successful grant; a miss is skipped and logs ERROR with object, section and key. app/TaskHandlers/Objects/StrategicLootHandler.php:148, :160, :174, :188
3. delivered: Bunker `weapons` section goes through `WeaponGrantService`, extracted from the PvE grant, which now delegates to it (durability from `weapons.durability`, `equipped` 0); message prints `weapons.name` with count. app/Services/Inventory/WeaponGrantService.php:57, app/Services/PVE/LootTableService.php:143, app/TaskHandlers/Objects/StrategicLootHandler.php:240
4. delivered: transform idempotent (second-run test passes), `Crystals` update guarded by `name_en IS NULL`; Wiring is one crafted entry max(20, 40) = 40, the two IslandHeart keys moved to `crafted_items`, `BasicMedKit` → `FirstAidKit`. app/Database/Migrations/2027-01-01-100000_H6FixStrategicLootKeys.php:36-43, :141-171
5. delivered: no compensation code; the migration docblock records the owner's decision and the reason (no log of what was given). app/Database/Migrations/2027-01-01-100000_H6FixStrategicLootKeys.php:27
6. delivered: resources, items and weapons listed by Russian name with count; an unresolved key can no longer reach the text; a caption over 1024 falls back to text in `MediaSender`. app/TaskHandlers/Objects/StrategicLootHandler.php:214-254
7. delivered: `/guide`, tip and WipeManifest verdicts recorded; handler, weapon-grant service and `db/world_objects` notes carry `last_reviewed: 2026-11-27`. mmorpg-vault/tech-writing/handlers/StrategicLootHandler.md:145-151, services/WeaponGrantService.md, db/world_objects.md:42-74

## Findings
- [minor] [ask 3 / ask 6] Local DB `weapons.name` for PipeGun is `Трубчатый пистолет (Pipe Gun)`, so the Bunker message reads `— *Трубчатый пистолет (Pipe Gun)*: N шт.` (StrategicLootHandler.php:245); the test seeds the clean name `Трубчатый пистолет` (tests/database/StrategicLootWeaponsTest.php:57) and misses this. It is the weapon's own display name (the craft menu shows the same string), not a loot key, so the ask holds. Smallest fix if the owner wants it gone: strip the parenthesised English from the name in data, in its own change; test: seed the weapon with the real name, assert the caption has no Latin letters.
```
