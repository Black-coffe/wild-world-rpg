---
story: w2-n5-deeds-02
spec: w2-n5-deeds
status: todo
returned:
tier: 2
worker: worker-code
model: sonnet
wave: 2
blocked_by: [w2-n5-deeds-01]
---

# Модели хаба, списков квестов и событий; бот рисует из них прежние экраны

## Goal
Ядро отдаёт данные «Дела» без `chat_id`: `TasksSurfaceService::model()` (активные задачи с `ends_at`, сводка квестов, ежедневки), `QuestListService` (активные / доступные с замками / завершённые) и `EventsModelService` (активные + 3 прошедших, `touched`). Хаб, три списка и «События» бота рисуются из них с прежними текстами и кнопками и находят персонажа через `BaseAction`.

## Requirements
> 3. Данные хаба, списков квестов и событий отдают нейтральные сервисы без `chat_id`; бот рисует из них те же тексты и кнопки, что до изменения (паритет тестом), и находит персонажа не по `chat_id`.
> 4. Флаги (`navigation.tasks_hub.enabled`, `quests.daily.enabled`, `quests.extended_enabled`, `quests.faction_quests_enabled`, `quests.branching_enabled`) действуют одинаково в боте и в вебе; выключенный раздел в вебе показан строкой-замком с объяснением.

## Files
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

## Non-goals
- Не менять тексты, кнопки и фото экранов бота.
- Не делать веб — story 03.
- «Квестоманию» (`QuestsInfo`) не трогать.

## Map slice
memory/map/quests-events-npc.md · memory/map/website.md (нативные экраны /play)

## Acceptance criteria
- [ ] Снимок бота до/после для хаба, трёх списков и «Событий» совпадает байт в байт (тексты и `reply_markup`).
- [ ] Модели не содержат `chat_id` и Markdown; флаги `quests.extended_enabled`/`faction`/`branching`/`tasks_hub`/`daily` дают те же разделы, что бот.
- [ ] Списки находят персонажа виртуального чата моста (web-only id) — тест на виртуальном id.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes

## Findings
