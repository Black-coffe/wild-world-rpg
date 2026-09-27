---
story: w2-n4-base-04
status: done
returned: DONE
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

## Implementation notes
- Finding 1: `BuildOrderService::start()` — гейт «уже строится» под той же блокировкой строки персонажа, что и лимит базы: задача этой постройки (`task_id`) в `in_work`/`queued` с `task_settings.base_cell` этой клетки → отказ `already_building` (код в `action_log`: `BUILD_<Key>` / `already_building`), откат без списания. JSON разбирается в PHP (у старых строк бывает не-JSON). Тесты: двойное нажатие при запасе на две стройки — одна задача, одно списание; второй клиент, закоммитивший ту же стройку до нашей блокировки, — отказ без списания.
- Finding 2: «❌ Отмена» подтверждения апгрейда несёт `Base_b<id>`, когда база известна. Правка в `app/Services/Player/BuildingUpgrade/BuildingUpgradeMessageFormatter.php` — файла нет в списке этой story, но finding указывает именно на него (кнопку рисует он). Паритет бота: ожидание суффиксных сценариев дополнено `Base` → `Base_b<id>`.
- Minor из отчёта (гейт переезда в вебе, уровень в `confirm_upgrade_building_<id>`, эффект в карточке апгрейда веба, asks 7/10) не трогались — repair меняет только RED.

