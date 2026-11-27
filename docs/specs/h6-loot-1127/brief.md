# H6.1 — strategic-object loot puts the resource name instead of its id (brief)
**Date:** 2026-11-27 · **Owner:** Andrei

## Request (verbatim)
> H6 тремя спеками (Рекомендую)

(Owner's pick on 2026-11-27 for the question «Открытых спек нет. Что берём?»; the option read: «(1) баг лута
стратобъектов (английское имя вместо id, Wiring у Технопарка), (2) сверка канона и лора с кодом,
(3) переименование трёх артефактов у 10–12 держателей своей миграцией и с проверкой на живых данных». This
spec is part (1).)

Source item, `ROADMAP.md:253` (roadmap text, not the owner's words):
> **H6** · Tier 1–2 · Канон-синк, узко и по порядку (`audit-wave1-1126`): (1) баг лута стратобъектов —
> `StrategicLootHandler.php:137` кладёт английское имя ресурса вместо id, у Технопарка `Wiring` в `resources`
> (`AddStrategicObjects.php`);

## Answers
- 2026-11-27 Recon + prod SELECT: the ROADMAP wording is off. Line 137 passes `name_en` and the model maps it to an id.
  The real defect: a key with no match is not granted, but the message still lists it. Broken on prod: resources
  Technopark `Wiring` (crafted item), `Crystals` (resource id 30 has `name_en` NULL), IslandHeart `metalFragments`,
  `electronicComponents` (crafted items); crafted_items Bunker `PipeGun`, `MetalSpear` (weapons), `BasicMedKit` in
  Bunker/IslandFarm/IslandHeart (real item `FirstAidKit`); these are skipped silently.
- 2026-11-27 Owner, weapons in Bunker: «Выдавать оружие сейчас (Рекомендую)» (option text: reuse the ready
  `grantWeapon` from PvE loot, full durability, not equipped).
- 2026-11-27 Owner, past loot: «Без компенсации (Рекомендую)» (no log exists; note the fix and the reason).

## Asks
1. Every loot entry of every strategic object (Bunker, Technopark, GhostCity, IslandFarm, IslandHeart) resolves to a
   real resource, crafted item or weapon on prod data: a SELECT after the migration returns 0 unresolved keys.
2. The loot message lists only what was actually added to the player's stock; an entry that could not be granted is
   not shown and writes an ERROR log line naming the object and key.
3. Bunker gives PipeGun and MetalSpear as weapons (full durability, not equipped), reusing the existing PvE weapon
   grant, and the message lists them by their Russian name with count.
4. The data fix is a migration that is safe to run twice; Technopark's `Wiring` becomes the crafted item (not summed
   twice), `Crystals` gives resource «Кристаллы», IslandHeart's two keys become crafted items, `BasicMedKit` becomes
   `FirstAidKit`.
5. No compensation for past loot; the fix notes say so and why (no log of what was given).
6. The loot message reads in media-off: Russian names and counts, no English keys.
7. Verdicts recorded: `/guide` — no (no new mechanic, a fix); tip — no (players need no new knowledge); WipeManifest —
   no new table/column; tech-writing notes updated for `StrategicLootHandler`, the service holding the weapon grant,
   and `db/world_objects`.
