---
story: w2-n5-deeds-04
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
> 2. Из веба можно начать доступный квест и выбрать ветку цепочки. Повтор формы или двойной тап — в вебе и в боте — не создаёт второй записи `quest_steps`: проверка и вставка идут в ядре под блокировкой строки персонажа.

## Implementation notes
- `QuestStartService::start()` пускает bespoke-квесты из `LEGACY_STARTS` (Explore30Cells, Explore300Cells, ExploreAllBiomes, FirstAidkitBasic — у каждого своя кнопка старта в боте) при выполненном предусловии цепочки, без killswitch ADR-088; уровень, фракция и «уже начат» — как у всех. Прочие bespoke — «нельзя начать вручную», как в боте.
- Тесты: `QuestStartServiceTest::testLegacyBespokeQuestStartsThroughTheCoreOthersStayManualOnly`; веб — старт Explore30Cells из «Доступных» пишет одну строку `quest_steps`.

## Findings
1. app/Services/Quest/QuestStartService.php:64 [ask 2] the web start (`op=quest_start`) must start every quest that the «📜 Доступные» list offers with «▶️ Начать квест», including the bespoke quests without `objective_type` (Explore30Cells, ExploreAllBiomes, Explore300Cells, FirstAidkitBasic, all `status=active` in the live data and started in the bot by exact routes), instead of refusing them with «Этот квест нельзя начать вручную.»; `QuestOverviewService::classifyFrom` (app/Services/Quest/QuestOverviewService.php:101-108) classifies them as available, `WebNativeScreenService::questCard` (app/Services/Web/WebNativeScreenService.php:1119) marks them `available`, native_tasks.php:196 renders the start button, and `start()` rejects them because `isExtendedStartableRoot` is false for a null `objective_type` - repro: seed a quest row `objective_type=NULL, prerequisite_quest=NULL, status='active', min_level=1` and POST `/play/view` `view=tasks&op=quest_start&id=<id>&intent_id=x`: the alert is «Этот квест нельзя начать вручную.» and no `quest_steps` row is written. The first two quests a new player sees are these, so this is the web start path they hit first

## Files
- app/Services/Quest/QuestStartService.php
- app/Services/Quest/QuestChainService.php
- app/Services/Quest/DailyTaskService.php
- app/Controllers/Telegram/Commands/Actions/Quest/GenericQuestStartAction.php
- app/Controllers/Telegram/Commands/Actions/Quest/QuestBranchChooseAction.php
- app/Controllers/Telegram/Commands/Actions/Quest/QuestStartExplore30Cells.php
- app/Controllers/Telegram/Commands/Actions/Quest/QuestStartExplore300Cells.php
- app/Controllers/Telegram/Commands/Actions/Quest/QuestStartExploreAllBiomes.php
- app/Controllers/Telegram/Commands/Actions/Quest/QuestStartFirstAidkitBasic.php
- tests/database/QuestStartServiceTest.php
- tests/database/QuestChainServiceTest.php
- tests/database/DailyTaskServiceTest.php
- phpstan-baseline.neon
- app/Services/Tasks/TasksSurfaceService.php
- app/Services/Quest/QuestListService.php
- app/Services/Events/EventsModelService.php
- app/Controllers/Telegram/Commands/Actions/TasksHubAction.php
- app/Controllers/Telegram/Commands/Actions/Quest/ActiveQuests.php
- app/Controllers/Telegram/Commands/Actions/Quest/AvailableQuests.php
- app/Controllers/Telegram/Commands/Actions/Quest/CompletedQuests.php
- app/Controllers/Telegram/Commands/Actions/EventAction.php
- tests/database/DeedsBotParityTest.php
- tests/database/QuestListServiceTest.php
- tests/database/EventsModelServiceTest.php
- app/Services/Web/WebNativeScreenService.php
- app/Controllers/Play.php
- app/Views/site/_play/native_tasks.php
- app/Views/site/_play/dock.php
- public/ui-kit.html
- tests/database/PlayViewControllerTest.php
- tests/unit/Views/PlayViewsTest.php

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
