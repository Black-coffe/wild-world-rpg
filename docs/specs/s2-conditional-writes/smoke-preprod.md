# s2-conditional-writes: Tier-3 smoke on preprod-testbot

**Date:** 2026-10-29, 12:05–12:08 EET · **Stand:** `testbot.wildworld.fun`, release `releases/2026-10-29-100146`
(develop `15956220`) · **Method:** a synthetic `callback_query` POSTed to `https://testbot.wildworld.fun/telegram/webhook`
with the `X-Telegram-Bot-API-Secret-Token` header. curl ran on the server itself (`~/tmp/s2post.sh`), the payload went in
with `--data-binary @file`, and `message_id` was the dummy number 999999. Every outcome is judged by DB deltas: a firehose
`status=ok` only proves the update was routed.

**Release check:** md5 of `PlantCropActionStart.php`, `UsePharmacyAction.php`, `TeleportUseAction.php`,
`TeleportItemConsumer.php` and `NpcInteractionService.php` on the server matches local `15956220`.

**Test character (checked with a SELECT, not taken from memory):** `telegram_users.id=25` → `telegram_id=6995661239`
(aviad_echo) → `characters.id=491`, base `claimed_cells.id=168` at cell 328454.

## Results

| # | Screen | Callback | Before | After | Verdict |
|---|---|---|---|---|---|
| 1a | Planting (F4), happy path | `plantSeedStart_berries` | BerrySeeds `cr.id=3496` qty **1**; plantCrop tasks **0** | qty **0**; task `11532` `in_work`, `{"crop":"berries","rotation":false}`, end +30 min; firehose 957 `ok` | GREEN |
| 1b | Planting (F4), 0 seeds | `plantSeedStart_berries` (2nd) | qty **0**; tasks **1** | qty **0**; tasks **1** (no new task); firehose 958 `rejected` `PLANT_berries: no_seed`; action_log 1880 `REJECTED no_seed` | GREEN |
| 2a | Pharmacy (F14), with a dose | `usePharmacy_Stimulator` | health **16.25**; Stimulator `cil.id=540` qty **3** | health **41.25** (+25); qty **2**; firehose 959 `ok` | GREEN |
| 2b | Pharmacy (F14), no dose | `usePharmacy_TonicElixir` | health **41.25**; TonicElixir rows **0** | health **41.25**; rows **0**; firehose 960 `ok` (the refusal text goes to chat) | GREEN |
| 3a | Portable teleport (ask 12) | `TeleportUse_Portable` | cell **171918**/biome 1 (moved there); `cil.id=574` charges **99** | cell **328454**/biome 6 (base); charges **98**; firehose 961 `ok` | GREEN |
| 3b | Teleport backpack (ask 12), happy path | `TeleportUse_Backpack` | backpack `cil.id=627` (seeded) charges **100**, `custom_setting NULL`; cell **171918** | cell **328454**; charges **99**; `{"lastUsedAt":"2026-10-29 12:06:44"}`; firehose 962 `ok` | GREEN |
| 3c | Teleport backpack, cooldown refusal | `TeleportUse_Backpack` (2nd) | cell **171918** (moved back); charges **99** | cell **171918** (not moved); charges **99**; firehose 963 `ok` | GREEN |
| 4a | NPC «Драться» (F8), happy path | `npcAct_kill_815683` | spawn 815683 (ScrapScavenger, seeded) `alive`; gold **1122786**; str **19.17**; npc_kills **200**; Σresources **7274**; health 100 (raised for the fight) | spawn **removed**; gold **1123004** (+218); str **19.22**; npc_kills **201**; Σresources **7277**; health 67.28; log `Атака: … NPC ID=815683` once; firehose 964 `ok` | GREEN |
| 4b | NPC, 2nd identical tap | `npcAct_kill_815683` (2nd) | as after 4a | gold/str/npc_kills/Σresources/health **unchanged**; no `Атака` line in the log; firehose 965 `ok` | GREEN (text: see note) |
| 4c | NPC, in-lock refusal (spawn row present, `dead`) | `npcAct_kill_815684` | spawn 815684 (seeded) `status='dead'`; gold 1123004; npc_kills 201 | spawn untouched; gold/npc_kills/Σresources **unchanged**; **no** `Атака` line, so `fightUnlocked` → `aliveSpawn()=null` → `GONE_LINE`; firehose 966 `ok` | GREEN (text: see note) |
| 5a | Base storage «🎒 Забрать всё» | `baseStorageList_all` | `base_storage` row 218 (seeded) Sand ×**7**; Sand `cr.id=3548` **571**; character at base (328454) | storage **empty** (row deleted); Sand **578** (+7); firehose 967 `ok` | GREEN |
| 5b | «Забрать всё», 2nd identical tap | `baseStorageList_all` (2nd) | storage empty; Sand **578** | Sand **578** (no second issue); firehose 968 `ok` | GREEN |

**Server log** (`writable/logs/log-2026-10-29.log`) after all the POSTs: **0** `ERROR`/`CRITICAL`. There are two
`WARNING [DEPRECATED] Creation of dynamic property BattleCharacter::$armorBonus / $attackSpeedBonus` lines from
`Services/PVE/EquipmentService.php:113`, written during the 4a fight. That file is not in the spec's diff: its last
commit is `c92f5418` (E21). The warning is old and unrelated to S2.

**Note on texts (4b/4c):** nothing records outgoing bot text on the server, so the texts were **not observed** in chat.
They were inferred from the code and the log:
- 4b: the 815683 spawn row is already deleted. `NpcActionChoiceAction` therefore stops at its own pre-check
  `find($spawnId)` → `alert('Незнакомец ушёл.')` and never reaches `fight()`. **«NPC уже исчез.»** does not appear on a
  sequential second tap. That is a correct refusal, just with different wording.
- 4c: here the spawn row exists with `status='dead'`, so the code goes through the lock into `fightUnlocked`. It refuses
  there with no fight (no `Атака` line) and returns `GONE_LINE = 'NPC уже исчез.'`, which goes to chat via `finish()`.
  This is the path ask 2 meant.

## Queries and mutations (testbot only, `~/tq.sh` against `testbotwildworld`)

Baseline snapshot before the changes: `characters` 491: cell 328454/biome 6, health 16.25, tired 100.00, gold 1122786,
exp 65.00, str 19.17, npc_kills 200. BerrySeeds `cr.3496`=1. Stimulator `cil.540` qty 3/dur 1. Portable `cil.574`
qty 1/dur 99. Buildings: Arsenal 139, BlastFurnace 140 (no Greenhouse). `base_storage` empty. No spawns on 328454.
No `in_work` tasks. `player_action_log` MAX 956, `action_log` MAX 1879.

Seeding / setup (each step had a snapshot SELECT before it):
1. `INSERT character_buildings` Greenhouse (id 173, building_id 5, cell 328454): without it the planting gate refuses.
2. `UPDATE characters SET cell_number=171918, biome_id=1` before 3a, 3b and 3c, so the teleport has somewhere to go.
3. `INSERT crafted_items_log` TeleportBackpack (id 627, item 86, qty 1, dur 100).
4. `UPDATE characters SET health=100` before 4a, so the fight is won and the reward is visible.
5. `INSERT npc_spawns` 815683 (npc 5 ScrapScavenger, `alive`) and 815684 (npc 5, `dead`) at cell 328454 (328,453).
6. `INSERT base_storage` 218 (Sand ×7).

Restored after the smoke: task 11532 and Greenhouse 173 deleted, BerrySeeds=1, Stimulator qty=3, portable dur=99,
backpack 627 deleted, spawn 815684 deleted (815683 was removed by the fight), Sand=571, character
cell 328454/biome 6, health 16.25, tired 100.00, gold 1122786, str 19.17, npc_kills 200. **Not reverted (cheap to keep,
harmless on testbot):** the three resource units from the 4a loot (Σ 7274→7277), the NPC relationship change
(`NpcRelationService::registerAction`) and the battle-log rows of the fight.

Verdict: GREEN
