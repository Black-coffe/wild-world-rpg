# Review (sudden-death-1125)

## Round 1 - 2026-11-25 - head 8095e6aed550bd6055ecf0b36a3089d0f8720f26

```
VERDICT: BLOCK
TESTS: vendor/bin/phpunit --no-coverage --no-progress, all 10 parts from CLAUDE.md run in sequence -> pass, 5327 tests, 0 failures, 0 errors, 10 skipped (unit1 1965, unit2 1644, db1 302, db2 232, db3 243, db4 250, db5 156, db8 91, db6 256, db7 188); vendor/bin/phpstan analyse --memory-limit=512M --no-progress -> pass, no errors; php -l on the two new migrations -> pass

## Asks
1. delivered: events and hunger stop at `health.passive_floor` (code clamp and `hard_min` 1.0), below the floor nothing changes; poison untouched. C:\laragon\www\mmorpg\app\Services\Events\IntentApplier.php:106-113, C:\laragon\www\mmorpg\app\TaskHandlers\FoodAndWaterConsumptionHandler.php:262
2. delivered: march and single steps keep the 0.01 floor and do not use the passive floor. C:\laragon\www\mmorpg\app\TaskHandlers\MarchingTaskHandler.php:260, C:\laragon\www\mmorpg\tests\database\MoveServiceTest.php:428
3. delivered: interrupt pays one roll × elapsed/total on a conditional `in_work → interrupted` (Worker's claim excludes a second payment), text-only message names the loot; manual cancel unchanged; gate and menu hint sit in the action shared by bot and `/play`. One event is missed by the gate, see the minor. C:\laragon\www\mmorpg\app\Services\Events\GatherFloorInterrupt.php:59, C:\laragon\www\mmorpg\app\Controllers\Telegram\Commands\Actions\GatherAction.php:174
4. delivered: start notice gives the per-hit range and says the own base shelters, caption kept within 1024. C:\laragon\www\mmorpg\app\Services\Events\EventMessageFormatter.php:66
5. delivered: one message when a hit crosses `health.passive_warn_threshold`, sent from the damage path; hunger always sends its own message. C:\laragon\www\mmorpg\app\Services\Events\PassiveHealthWarning.php:74, C:\laragon\www\mmorpg\app\Services\Events\EventDispatcher.php:243
6. delivered: preview shows danger cells with their surcharge, HP left, and a risk line before «Выступить» in bot and `/play`. C:\laragon\www\mmorpg\app\Services\World\MarchService.php:152, C:\laragon\www\mmorpg\app\Controllers\Telegram\Commands\Actions\MarchAction.php:95, C:\laragon\www\mmorpg\app\Views\site\_play\native_map.php:228
7. partly: events and march are named with HP and minutes; hunger is never named with HP or time. C:\laragon\www\mmorpg\app\Services\Player\Death\DeathMessageBuilder.php:98-118
8. delivered: three keys seeded with rationale / effect / above / below and soft and hard bounds; no new balance number in code. C:\laragon\www\mmorpg\app\Database\Migrations\2026-12-26-100000_SeedPassiveFloorSettings.php
9. delivered: ADR-200 and 16 tech-writing notes are committed in the vault, one per touched service/handler; `/guide` verdict (yes, section `worldevents`) and tip verdict (yes, category «события») are stated with reasons. C:\Projects\mmorpg-vault\decisions\ADR-200-Passive-damage-does-not-kill.md, C:\Projects\mmorpg-vault\tech-writing\services\GuideCatalog.md, C:\laragon\www\mmorpg\app\Database\Migrations\2026-12-27-100000_SeedEventsWoundNotKillTip.php

## Findings
- [major] [ask 7] The death message never names hunger with its damage and time, C:\laragon\www\mmorpg\app\Services\Player\Death\DeathMessageBuilder.php:113-115. `causeBlock()` takes only event hits and a march; hunger appears only as the fallback guess «обычно это голод (кончились еда или вода) или шаг в опасный биом», with no HP and no time, and only when the 2-hour window holds no event and no march. The hunger drop is not recorded anywhere the builder can read (`FoodAndWaterConsumptionHandler::subtractHealth`, C:\laragon\www\mmorpg\app\TaskHandlers\FoodAndWaterConsumptionHandler.php:248). When hunger halves HP and an event or march finishes the job, the listed damage does not add up to the lost health. The fallback also names a cause that ask 1 made impossible on its own: hunger now stops at 1.0. Smallest fix: store the applied hunger drop with its time where the builder can read it, add a part «голод: ❤️N, M мин назад» to `causeBlock()`, and drop the hunger guess from the fallback when no such record exists. Test: `causeBlock([], null, 0.3, $now)` extended with a hunger hit of 25 HP 90 minutes ago contains «голод», «❤️25» and «90 мин»; without a hunger record the text does not contain «голод».
- [minor] [ask 3] The gather gate misses «Миражи», C:\laragon\www\mmorpg\app\Services\Events\ActiveDamageEventLookup.php:113. `harmsHealth()` returns false for any kind other than `damage_health`, but `MirageOases` (`task_extend`) takes 1–10 HP through `side_effects.health_loss_range`. Its start notice says «Ранит здоровье… добыча прервётся» and it does interrupt a gather at the floor, yet a player below `health.event_gather_min` can start a new gather there, so the interrupt message's «новая добыча в биоме этого события не начнётся» is false for this event. Smallest fix: treat `side_effects.health_loss_range` as health harm in `harmsHealth()`, as `EventMessageFormatter::healthHitRange()` already does. Test: `ActiveDamageEventLookup::harmsHealth((new \Config\WorldEvents())->get('MirageOases'), null, null)` is true (false now).
```

Ask 3 was changed after `plan(sudden-death-1125): approved` (commit ad80ccdc), and the owner's answer «Доля по времени (Рекомендую)» was added under `## Answers` in the same commit, so that change is not a finding.

## Round 2 - 2026-11-25 - head 50b55b5f74ab5aa42cc1892aa14923e9d8200a82

```
VERDICT: BLOCK
TESTS: vendor/bin/phpunit --no-coverage --no-progress (10 parts per CLAUDE.md) -> pass, 5330 tests, 0 failures, 10 skipped; vendor/bin/phpstan analyse --memory-limit=512M --no-progress -> pass, no errors

## Asks
1. delivered: events and hunger stop at `health.passive_floor`, C:\laragon\www\mmorpg\app\Services\Player\PassiveHealthFloor.php:52, C:\laragon\www\mmorpg\app\Services\Events\IntentApplier.php:113, C:\laragon\www\mmorpg\app\TaskHandlers\FoodAndWaterConsumptionHandler.php:265
2. delivered: the march and danger-step paths do not use the passive floor, C:\laragon\www\mmorpg\app\TaskHandlers\MarchingTaskHandler.php (not touched since round 1)
3. delivered: interrupt with time-share loot, C:\laragon\www\mmorpg\app\Services\Events\GatherFloorInterrupt.php:59; the gather gate now also covers «Миражи», C:\laragon\www\mmorpg\app\Services\Events\ActiveDamageEventLookup.php:120
4. delivered: the start notice states the health damage range and base safety, C:\laragon\www\mmorpg\app\Services\Events\EventMessageFormatter.php:95
5. delivered: one warning on crossing the threshold, C:\laragon\www\mmorpg\app\Services\Events\PassiveHealthWarning.php:74
6. delivered: the preview shows the danger surcharge, HP left and the risk line, C:\laragon\www\mmorpg\app\Controllers\Telegram\Commands\Actions\MarchAction.php:97, C:\laragon\www\mmorpg\app\Views\site\_play\native_map.php
7. delivered: the cause names events, march and hunger with HP and time, C:\laragon\www\mmorpg\app\Services\Player\Death\DeathMessageBuilder.php:108-133
8. delivered: three keys with rationale, effect and bounds, C:\laragon\www\mmorpg\app\Database\Migrations\2026-12-26-100000_SeedPassiveFloorSettings.php:29-59; the fix round adds no balance number (`HUNGER_WINDOW_MINUTES` is a display window)
9. delivered: ADR-200 is in the vault; the notes for `DeathMessageBuilder`, `FoodAndWaterConsumptionHandler` and `ActiveDamageEventLookup` carry the round-1 changes (vault commit a87d0cc)

## Findings
- [major] [correctness] The F1 fix writes the hunger drop into `action_log`, which three readers treat as player activity, C:\laragon\www\mmorpg\app\TaskHandlers\FoodAndWaterConsumptionHandler.php:329. Every starving character under level 20 gets a `hunger_health_loss` row each day at 21:33, indefinitely: regen of 0.05 HP/min lifts them back above the floor before the next run. None of the three readers filters by `action_name`:
  - `TributeService::actionTimestamps()`, C:\laragon\www\mmorpg\app\Services\PVE\TributeService.php:813. The anti-collusion gate flags a pair only when `concurrentActions === 0` (line 252). Two accounts of one operator that both go hungry get rows in the same cron run, so the gate returns `colluding=false, reason=ok`. Keeping both accounts without food defeats the gate.
  - `PlayerDetectionService::fetchLastActiveMap()`, C:\laragon\www\mmorpg\app\Services\Player\PlayerDetectionService.php:456. A starving abandoned character is never marked abandoned and sorts to the top of the detection list as recently active.
  - `RelocateAbandonedCharacters::findCandidates()`, C:\laragon\www\mmorpg\app\Commands\RelocateAbandonedCharacters.php:179. The same characters stop being candidates.

  Smallest fix: exclude `FoodAndWaterConsumptionHandler::HEALTH_LOSS_ACTION` in these three queries, or store the drop somewhere no activity reader looks. Test: after `subtractHealth` drops HP for two characters with no other `action_log` rows, `fetchLastActiveMap([$a, $b])` has no timestamp for either, and `TributeService::countConcurrent(actionTimestamps($a), actionTimestamps($b), 120)` is 0. Now it returns both timestamps and 1. Reviewer proof, fails at head: C:\Users\Andrei\AppData\Local\Temp\claude\5cb5bf1455536ffe\7c573cde-09a6-46a3-b40c-f289be732c86\scratchpad\proof\HungerRowReadAsActivityProofTest.php

## Past findings (round 2 and later)
F1: fixed - `causeBlock()` adds «голод (не хватило еды или воды): ❤️N, M мин назад» from the recorded drop, and the fallback no longer guesses hunger (C:\laragon\www\mmorpg\app\Services\Player\Death\DeathMessageBuilder.php:120-129); the requested assertions pass in C:\laragon\www\mmorpg\tests\unit\Services\Player\Death\DeathMessageBuilderTest.php. The place chosen for the record causes the new major above.
F2: fixed - `harmsHealth()` is true for `task_extend` with `side_effects.health_loss_range` (C:\laragon\www\mmorpg\app\Services\Events\ActiveDamageEventLookup.php:115-121); `testMirageSideEffectHarmsHealth` passes, and PolarNight stays false.
```

Owner: one more round - "Ещё раунд: хранить вне action_log (Рекомендую)", 2026-11-25

## Round 3 - 2026-11-25 - head 5d56d0633a94c2d1f4be86e733b2f614caaf35a4

```
VERDICT: PASS
TESTS: vendor/bin/phpunit --no-coverage --no-progress <all 10 suite parts, one after another> -> pass, 5333 tests, 0 failures, 0 errors (10 skipped); vendor/bin/phpstan analyse --memory-limit=512M --no-progress -> pass, no errors; php -l on the new migration -> pass

## Asks
1. delivered: events and hunger stop at the `health.passive_floor` floor, C:\laragon\www\mmorpg\app\Services\Player\PassiveHealthFloor.php:52, C:\laragon\www\mmorpg\app\TaskHandlers\FoodAndWaterConsumptionHandler.php:257
2. delivered: march and steps into dangerous biomes keep their lethal path, C:\laragon\www\mmorpg\app\Services\World\MarchService.php:91
3. delivered: gather interrupted at the floor with time-proportional loot, and the low-HP gather gate in both clients, C:\laragon\www\mmorpg\app\Services\Events\GatherFloorInterrupt.php:59, C:\laragon\www\mmorpg\app\Controllers\Telegram\Commands\Actions\GatherAction.php:566
4. delivered: the event start notice states the damage and that the own base is safe, C:\laragon\www\mmorpg\app\Services\Events\EventMessageFormatter.php
5. delivered: one warning when a hit crosses the threshold, C:\laragon\www\mmorpg\app\Services\Events\PassiveHealthWarning.php:51
6. delivered: the march preview carries the danger surcharge, HP left and the roulette risk line, C:\laragon\www\mmorpg\app\Services\World\MarchService.php:148
7. delivered: the death message names event, march and hunger with HP and time; hunger is now read from the character row, C:\laragon\www\mmorpg\app\Services\Player\Death\DeathMessageBuilder.php:204
8. delivered: the three new keys are seeded with texts and bounds, C:\laragon\www\mmorpg\app\Database\Migrations\2026-12-26-100000_SeedPassiveFloorSettings.php:29
9. delivered: ADR-200 exists, and the notes for the handler, the death message builder and the character model describe the new columns, C:\Projects\mmorpg-vault\decisions\ADR-200-Passive-damage-does-not-kill.md, C:\Projects\mmorpg-vault\tech-writing\tasks\FoodAndWaterConsumptionHandler.md:51

## Findings
(none)

## Past findings (round 2 and later)
F1: fixed - the hunger drop is now stored in `characters.hunger_hp_lost` / `hunger_hp_lost_at` and no longer in `action_log`, C:\laragon\www\mmorpg\app\TaskHandlers\FoodAndWaterConsumptionHandler.php:320. The round 2 proof test now passes at head: no `action_log` rows, an empty `fetchLastActiveMap`, and 0 concurrent actions (C:\Users\Andrei\AppData\Local\Temp\claude\5cb5bf1455536ffe\7c573cde-09a6-46a3-b40c-f289be732c86\scratchpad\proof\HungerRowReadAsActivityProofTest.php). The branch's own test asserts the same, C:\laragon\www\mmorpg\tests\database\HungerPassiveFloorTest.php.
```

Asks 1–6 and 8 were not re-reviewed this round: their code is untouched since round 2, and the lines above are anchors, not a fresh read.

Owner: one more round - «Да, на базе не блокировать (Рекомендую)» (option text: «ещё раунд ревью и передеплой preprod перед тегом»), 2026-11-25
