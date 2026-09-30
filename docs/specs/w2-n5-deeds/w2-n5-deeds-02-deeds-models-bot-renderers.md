---
story: w2-n5-deeds-02
spec: w2-n5-deeds
status: done
returned: DONE
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
- phpstan-baseline.neon

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
- Паритет снят честно: `DeedsBotParityTest` сначала прогнан на старых handler'ах (изменения в `git stash`) —
  хаб, три списка и «События» совпали со снимком; затем на новом коде — зелёный. На старом коде красный только
  тест виртуального отправителя: старые списки искали персонажа по `chat_id` и не видели своего (это и чинится).
- Снимок показал, что `MediaSender` склеивает одиночные ряды кнопок (хаб: «⛔️ 1» + «📜 Квесты»; доступные:
  ветки + квест) — ожидания записаны по фактическому выводу, а не по исходным массивам.
- `TasksSurfaceService::model()` и `buildScreen()` собираются из одних швов (`activeTasks` / `questSummary`),
  поэтому бот-экран не менялся; модель добавляет `ends_at`, звезду без Markdown и `daily` (`DailyTaskService::today`).
  `TasksHubAction` не тронут: персонажа он уже берёт через `BaseAction::executeWithCharacter`.
- `QuestListService::available()` сверх контракта `plan.md` отдаёт `prereq_title_ru`; развилки — `branches()`
  (обёртка над `QuestChainService::pendingBranchesForCharacter`), чтобы веб не звал два сервиса.
- Модели отдают сырой текст (`Запас *дров*` как есть): экранирует рендерер — бот как прежде (без эскейпа, 1:1),
  веб — `esc()`.
- `events` в тестах создаётся вручную: миграция CreateEventsTable не проходит на MySQL 8 (`img_path TEXT` с default).
- `phpstan-baseline.neon` в `## Files`: 24 записи четырёх переписанных handler'ов больше не совпадают — удалены;
  `EventAction::getKeyboard()` получил тип возврата вместо записи baseline.
- Вердикты (ask 6): /guide — нет, совет — нет; экраны бота не меняются.

## Findings
