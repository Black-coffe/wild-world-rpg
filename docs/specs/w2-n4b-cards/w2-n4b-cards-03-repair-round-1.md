---
story: w2-n4b-cards-03
status: done
returned: DONE
worker: worker-code
model: opus
wave: 3
blocked_by: []
red: required
red_reason: кнопка действия здания — мостом
red_verified_at: 2026-11-02T09:33:44Z 4799b478296084e16c55538bfa768b7bb05d3a9ca22293e47a4808be942d3d0c
---

# Repair round 1

## Goal
Make the asks and findings council round 1 left RED pass, and change nothing else.

## Requirements
> 3. Грядки, Роботы, Маяки, Ремонт, Развитие базы, Путь новичка — видимые кнопки моста, ни одна не пропадает.

## Findings
1. app/Views/site/_play/native_base.php:862 [ask 3] each bridge button on the web building card (🌱 Грядки `plantSeedMenu`, 🤖 Роботы `AllRobots`, 🔧 Ремонт `repairBuilding_<id>`, 🏗 Развитие базы `baseDevelopment` with no `_b<id>`, 📖 Путь новичка `guide_base`) must be accepted by the `/play` bridge and reach the bot message that carries it; today only `teleportBeacon` is routable: `WebNativeScreenService::bridge()` (app/Services/Web/WebNativeScreenService.php:1749-1758) finds no route for the other five (they are not in `BASE_BRIDGE_EXACT`/`BASE_BRIDGE_PATTERN` :216-220, `BRIDGE_ROUTES`, the "Я" sheet callbacks or the map actions), throws `InvalidArgumentException`, and `Play::view` answers 400 "rejected" (app/Controllers/Play.php:270-273, same path as `testBridgeRejects…admin_give_gold` in tests/database/PlayViewControllerTest.php:360-363); PlayViewsTest only asserts the buttons render, never that a POST of them is accepted - repro: `php -r '$p="/^((hangar|campDecor|baseDevelopment)_b\d+|building_\d+_[A-Za-z]+_b\d+|genericBuildInfo_[A-Za-z]+(_b\d+)?)$/"; foreach(["plantSeedMenu","AllRobots","repairBuilding_52","baseDevelopment","guide_base"] as $c) echo $c," ",preg_match($p,$c),"\n";'` prints 0 for all five, then POST `play/view` with `op=bridge&data=AllRobots` returns 400.

## Files
- app/Services/Bases/BuildingCardService.php
- app/Controllers/Telegram/Commands/Actions/Camp/BuildingHandlerAction.php
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/ArsenalHandler.php
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/BlastFurnaceHandler.php
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/CommunicationTowerHandler.php
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/DefensiveBuildingHandler.php
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/GreenhouseHandler.php
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/GymHandler.php
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/HandPumpHandler.php
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/LaboratoryHandler.php
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/LeanToHandler.php
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/RoboticsWorkshopHandler.php
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/SolarStationHandler.php
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/TeleportationCenterHandler.php
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/WarehouseHandler.php
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/WorkshopHandler.php
- app/Services/Navigation/NavigationMapService.php
- tests/unit/Camp/BuildingCardParityTest.php
- tests/unit/Camp/BuildingCardSnapshots.json
- tests/unit/Camp/BuildingHandlerCoverageTest.php
- tests/unit/Camp/BuildingCardBaseChoiceTest.php
- tests/unit/Camp/BuildingCardBaseScopeTest.php
- tests/unit/BuildingHandlerNoHardcodedIdTest.php
- tests/unit/Display/ConfigImageMapsFilesExistTest.php
- tests/unit/Camp/DetailedBaseInfoBareConstructionTest.php
- phpstan-baseline.neon
- app/Services/Web/WebNativeScreenService.php
- app/Services/Bases/BaseScreenService.php
- app/Views/site/_play/native_base.php
- public/assets/css/wildworld-ui.css
- public/ui-kit.html
- tests/unit/Views/PlayViewsTest.php
- tests/database/PlayViewControllerTest.php

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Camp/BuildingCardParityTest.php && vendor/bin/phpunit --no-coverage --no-progress tests/unit/Camp/BuildingHandlerCoverageTest.php && vendor/bin/phpunit --no-coverage --no-progress tests/unit/Camp/BuildingCardBaseScopeTest.php && vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Views/PlayViewsTest.php && vendor/bin/phpunit --no-coverage --no-progress tests/database/PlayViewControllerTest.php && vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Red receipt
- command: vendor/bin/phpunit --no-coverage --no-progress tests/unit/Views/PlayViewsTest.php && vendor/bin/phpunit --no-coverage --no-progress tests/database/PlayViewControllerTest.php && vendor/bin/phpstan analyse --memory-limit=512M --no-progress
- exit: 1
- tests: 4799b478296084e16c55538bfa768b7bb05d3a9ca22293e47a4808be942d3d0c (tests/database/PlayViewControllerTest.php, tests/unit/Camp/BuildingCardBaseScopeTest.php, tests/unit/Camp/BuildingCardParityTest.php, tests/unit/Camp/BuildingHandlerCoverageTest.php, tests/unit/Views/PlayViewsTest.php)
- impl: 4da80138283dd9d9b3afb1596163b94be8d603e3b22c14be0ad34cf9fc3990a9
- stamped: 2026-11-02T09:33:44Z

```
    </div>\n
</div>\n\r
\n
<!-- DEBUG-VIEW ENDED 102 APPPATH\Views\site\_play\native_base.php -->\r\n
' matches PCRE pattern "~name="op" value="bridge">.*?name="data" value="building_7_Greenhouse_b9>plantSeedMenu"><button class="play-kb-btn" type="submit">🌱 Грядки~su".

C:\laragon\www\mmorpg\tests\unit\Views\PlayViewsTest.php:593

[37;41mFAILURES![0m
[37;41mTests: 33[0m[37;41m, Assertions: 273[0m[37;41m, Failures: 1[0m[37;41m, PHPUnit Deprecations: 1[0m[37;41m.[0m
```
