# Relic event ignores character level — prod evidence (ask 3)

**Source:** read-only SELECTs on prod `wildworld.fun`, run 2026-11-28 by the main session. No writes.

## Which resources the event can grant

Event `RevealingHiddenCameras` (`app/Config/WorldEvents.php:552-567`) picks from `resources.keyword = 'RevealingHiddenCameras'`:

| id | level_required | biome_id | rarity | sell price |
|---|---|---|---|---|
| 69 | 55 | 7 | 1 | 1425 |
| 70 | 75 | 7 | 1 | 1900 |
| 71 | 100 | 7 | 1 | 4750 |

## Who holds them

| resource | holders | units held | holders below level_required |
|---|---|---|---|
| 69 (lvl 55) | 12 | 6282 | 3 |
| 70 (lvl 75) | 12 | 6052 | 6 |
| 71 (lvl 100) | 10 | 4310 | 4 |

Under-level rows: 13 units on 6 characters, 1 unit per row.

| character | level | resource | qty | updated_at |
|---|---|---|---|---|
| 645 | 4 | 69 | 1 | 2026-06-26 22:42:03 |
| 645 | 4 | 70 | 1 | 2026-06-26 22:42:03 |
| 645 | 4 | 71 | 1 | 2026-06-26 22:42:03 |
| 705 | 2 | 70 | 1 | 2026-06-26 22:42:03 |
| 1027 | 35 | 70 | 1 | 2026-08-03 12:10:07 |
| 1027 | 35 | 71 | 1 | 2026-11-07 17:03:07 |
| 1039 | 51 | 70 | 1 | 2026-11-26 22:13:10 |
| 1061 | 9 | 69 | 1 | 2026-06-26 22:42:03 |
| 1061 | 9 | 70 | 1 | 2026-06-26 22:42:03 |
| 1061 | 9 | 71 | 1 | 2026-06-26 22:42:03 |
| 1145 | 45 | 69 | 1 | 2026-11-07 17:03:10 |
| 1145 | 45 | 71 | 1 | 2026-08-07 21:02:10 |
| 1145 | 45 | 70 | 1 | 2026-08-07 21:02:10 |

Value at sell price: 3 × 1425 + 6 × 1900 + 4 × 4750 = 34 675 gold.

## Reading

- The bug was reachable: before S1 nothing in the grant path checked level, so any character gathering in caves
  during the event could get 69/70/71.
- 7 of the 13 rows share `updated_at = 2026-06-26 22:42:03` across 3 characters (645, 705, 1061): one bulk write,
  not the event.
- The other 6 rows (Aug–Nov, characters 1027, 1039, 1145) may come from the event, boss 703 loot or a bank purchase;
  the data cannot tell which. How many under-level units the event itself granted is therefore not provable.

## Decision on the proceeds

Owner, 2026-11-28 (brief `## Answers`): «Approve, leave as is (Recommended)». The 13 units stay with their holders;
a deliberate decision, not a deferral. They are not taken back later without new evidence.
