<!-- seat: review · model: claude-opus-5-5 · round: 1 · head: 17da958a · pack: 762348ceedbe · attempt: 1 · recorded: 2026-11-02T09:31:53Z · verdict: BLOCK -->
VERDICT: BLOCK
MODEL: claude-opus-5-5
SUITE: ok 535s

## Critical
None.
## Major
1. app/Views/site/_play/native_base.php:862 [ask 3] each bridge button on the web building card (🌱 Грядки `plantSeedMenu`, 🤖 Роботы `AllRobots`, 🔧 Ремонт `repairBuilding_<id>`, 🏗 Развитие базы `baseDevelopment` with no `_b<id>`, 📖 Путь новичка `guide_base`) must be accepted by the `/play` bridge and reach the bot message that carries it; today only `teleportBeacon` is routable: `WebNativeScreenService::bridge()` (app/Services/Web/WebNativeScreenService.php:1749-1758) finds no route for the other five (they are not in `BASE_BRIDGE_EXACT`/`BASE_BRIDGE_PATTERN` :216-220, `BRIDGE_ROUTES`, the "Я" sheet callbacks or the map actions), throws `InvalidArgumentException`, and `Play::view` answers 400 "rejected" (app/Controllers/Play.php:270-273, same path as `testBridgeRejects…admin_give_gold` in tests/database/PlayViewControllerTest.php:360-363); PlayViewsTest only asserts the buttons render, never that a POST of them is accepted - repro: `php -r '$p="/^((hangar|campDecor|baseDevelopment)_b\d+|building_\d+_[A-Za-z]+_b\d+|genericBuildInfo_[A-Za-z]+(_b\d+)?)$/"; foreach(["plantSeedMenu","AllRobots","repairBuilding_52","baseDevelopment","guide_base"] as $c) echo $c," ",preg_match($p,$c),"\n";'` prints 0 for all five, then POST `play/view` with `op=bridge&data=AllRobots` returns 400.
## Minor
- app/Services/Bases/BuildingCardService.php:254 a defensive building with no `name_ru` falls back to the raw key (`WoodenWall`) as the visible name; the old copy did the same, so this is not a regression.
- docs/specs/w2-n4b-cards/plan.md asks 6 and 7 (guide/tips verdicts, live preprod pass for bot and web) are still open: the plan assigns them to the Queen after the merge.
