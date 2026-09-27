---
story: w2-n4-base-04
status: todo
returned:
worker: worker-code
model: opus
wave: 4
blocked_by: []
---

# Repair round 1

## Goal
Make the asks and findings council round 1 left RED pass, and change nothing else.

## Requirements
> 4. Атомарность: ресурсы/предметы/золото — условной записью; лимиты и «уже строится» — под блокировкой; двойное нажатие / два клиента не дают вторую стройку.
> 5. Мульти-база: обзор, «Строить», карточка, апгрейд, «назад» несут номер базы, ядро перепроверяет его в обоих клиентах.

## Findings
1. app/Services/Buildings/BuildOrderService.php:302 [ask 4] start() must check «уже строится» under the character-row lock: it has to refuse a second build of the same building at the same base while one is in_work, so a bot double-tap or a second client cannot create a second task when the backpack holds materials for two (today only the base slot limit and the conditional material write stand between them, and no such check exists) - repro: in `tests/database/BuildOrderServiceTest.php::testSecondStartOnStockForOneIsRefusedWithoutPartialDeduction`, double the fixture stock; the second `start(1, null, 'Workshop')` returns ok and `taskCount()` is 2.
2. app/Services/Player/BuildingUpgrade/BuildingUpgradeMessageFormatter.php:122 [ask 5] when the base is known, the upgrade confirmation's back button «❌ Отмена» must carry `_b<id>`; it still sends bare `Base`, so a multi-base player lands on the picker instead of the same base. Story 02 logged this as a Finding, and plan.md `## Descoped` has no line for it.

## Files
- app/Services/Bases/BaseScreenService.php
- app/Services/BaseService.php
- app/Services/Bases/BaseServiceMessageFormatter.php
- app/Services/Bases/BaseBuildingsList.php
- app/Controllers/Telegram/Commands/Actions/Camp/DetailedBaseInfoAction.php
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/ShowBaseInfoAction.php
- tests/database/BaseScreenServiceTest.php
- tests/unit/Camp/BaseScreenBotParityTest.php
- phpstan-baseline.neon
- app/Services/Buildings/BuildOrderService.php
- app/Services/Buildings/BuildingUpgradeService.php
- app/Services/Player/BuildingUpgrade/BuildingUpgradeApplier.php
- app/Services/Player/BuildingUpgrade/BuildingUpgradeValidator.php
- app/Controllers/Telegram/Commands/Actions/Camp/BuildListAction.php
- app/Controllers/Telegram/Commands/Actions/Camp/GenericBuildingInfoAction.php
- app/Controllers/Telegram/Commands/Actions/Camp/GenericBuildingAction.php
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/UpgradeBuildingAction.php
- tests/database/BuildOrderServiceTest.php
- tests/database/BuildingUpgradeServiceTest.php
- tests/unit/Camp/BuildBotParityTest.php
- app/Services/Web/WebNativeScreenService.php
- app/Controllers/Play.php
- app/Services/Player/CharacterSheetService.php
- app/Views/site/_play/native_base.php
- app/Views/site/_play/native_craft.php
- app/Views/site/_play/dock.php
- app/Views/site/_play/hud.php
- app/Views/site/_layout/meta.php
- app/Services/Craft/CraftOrderService.php
- app/Controllers/Telegram/Commands/Actions/Craft/Cooking/CampfireCookingSelect.php
- public/assets/css/wildworld-ui.css
- public/assets/js/wildworld-play.js
- public/ui-kit.html
- tests/database/PlayViewControllerTest.php

## Verification
`vendor/bin/phpunit --no-coverage --no-progress && vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
