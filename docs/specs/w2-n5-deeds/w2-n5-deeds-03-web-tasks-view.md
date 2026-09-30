---
story: w2-n5-deeds-03
spec: w2-n5-deeds
status: done
returned: DONE
tier: 2
worker: worker-code
model: sonnet
wave: 3
blocked_by: [w2-n5-deeds-02]
---

# Веб «📋 Дела» (`view=tasks`): хаб, списки, карточка, события, старт квеста и выбор ветки

## Goal
В `/play` появляется «📋 Дела» из дока: хаб с живыми таймерами задач, ежедневки, три списка квестов с замками, карточка квеста из строки `quests`, «События». Кнопки «Начать» и выбор ветки — мутации `op=quest_start|quest_branch` с `intent_id` через ядро story 01. Выключенный флагом раздел — строка-замок с объяснением. «⛔ Прервать» и «Квестомания» — кнопки моста.

## Requirements
> 1. На сайте в `/play` есть «📋 Дела» (`view=tasks`), вход — из дока: хаб (активные задачи с живым таймером, сводка квестов, ежедневки с прогрессом и наградой), списки квестов «активные / доступные / завершённые» и «События» (активные и 3 прошедших, отмечено, задело ли событие игрока). Всё читается без картинок.
> 2. Из веба можно начать доступный квест и выбрать ветку цепочки. Повтор формы или двойной тап — в вебе и в боте — не создаёт второй записи `quest_steps`: проверка и вставка идут в ядре под блокировкой строки персонажа.
> 4. Флаги (`navigation.tasks_hub.enabled`, `quests.daily.enabled`, `quests.extended_enabled`, `quests.faction_quests_enabled`, `quests.branching_enabled`) действуют одинаково в боте и в вебе; выключенный раздел в вебе показан строкой-замком с объяснением.

## Files
- app/Services/Web/WebNativeScreenService.php
- app/Controllers/Play.php
- app/Views/site/_play/native_tasks.php
- app/Views/site/_play/dock.php
- public/ui-kit.html
- tests/database/PlayViewControllerTest.php
- tests/unit/Views/PlayViewsTest.php

## Non-goals
- Не переносить «Квестоманию» и прерывание задачи в нативный экран.
- Не вводить новых компонентов вне `wildworld-ui.css` без ui-kit (ADR-062).

## Map slice
memory/map/quests-events-npc.md · memory/map/website.md (нативные экраны /play)

## Acceptance criteria
- [ ] `view=tasks` рендерит хаб, списки, карточку и события из моделей story 02; всё читается без картинок, 0 радиусов/теней.
- [ ] Повтор формы старта с тем же `intent_id` — одна строка `quest_steps`; второй `intent_id` на уже начатый квест — «уже начат».
- [ ] Выключенный `navigation.tasks_hub.enabled` / `quests.daily.enabled` — раздел показан строкой-замком с объяснением.
- [ ] Док ведёт в «📋 Дела»; вьюпорты 1440/768/375 без горизонтального скролла (Tier-2 Queen).

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes
- `WebNativeScreenService`: `VIEW_TASKS`, док «📋 Дела» → `view=tasks`; `tasksModel()` (разделы hub / active / available / completed / quest / events; неизвестный квест → «Доступные»); `questStart()` (`:quest_start`, ядро `QuestStartService`) и `questBranch()` (`:quest_branch`, `QuestChainService::chooseBranch`) — один раз на `intent_id`, отказ — `action_log` REJECTED. Хаб при `quests.daily.enabled` зовёт `ensureAssigned` (блокировка ядра story 01).
- Мост: «⛔️ Прервать» (`finishAllTasks_<id>`) и «🌐 Квестомания» (`questInfo` через `questAndTask`) идут от `/tasks` бота; кнопку, которой нет на экране бота, мост не жмёт.
- Выключенный `navigation.tasks_hub.enabled` — замок вместо сводки, списки и события остаются кнопками; выключенные задания дня — замок в сводке. Док «📋 Дела» в вебе есть ровно тогда, когда он есть в reply-меню бота (паритет, без добавления кнопки).
- Вьюха собрана только из существующих классов `wildworld-ui.css` (CSS не трогали, бамп `?v=` не нужен); образец в `ui-kit.html#play-tasks-demo`. Tier-2 на образце: 1904 / 768 / 375 — без горизонтального скролла, 0 радиусов и теней, консоль чистая.
- Тесты: хаб, замки, мост — на двойниках; списки, замок цепочки, карточка, события, старт (одна строка `quest_steps`, второй intent — «уже начат», PRG) и ветка — на настоящих таблицах из миграций (`events` вручную, как в `EventsModelServiceTest`: миграция не проходит строгий MySQL). Фикстуры дока «📋 Дела → мост» сменены на «⚙️ Ещё».

## Findings
