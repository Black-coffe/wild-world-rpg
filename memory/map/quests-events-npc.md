<!-- Срез-указатель, а не копия территории. Подробность — в mmorpg-vault; здесь только то,
     что нужно, чтобы понять, куда идти, и не вляпаться. Посеян обследованием дерева репозитория
     и конституцией проекта 2026-08-19; углубляется /vulyk-map <path> через drone-scout. -->
last-verified: 2026-08-19

# Scout report: Квесты, события, NPC

## Purpose
Контентные петли: квестовые цепочки и ежедневные задания, мировые события с эффектами,
ветвящиеся диалоги и отношения с NPC.

## Entry points
- Квесты: `app/Services/Quest/QuestChainService.php`, `QuestOverviewService.php`,
  `DailyTaskService.php` + `DailyTaskCatalog.php`; модель `QuestModel`, таблица `quest_steps`;
  TaskHandlers `app/TaskHandlers/Quests/`.
- События: `app/Services/Events/` — `EventDispatcher`, `EffectResolver`, `EffectAccumulator`,
  `Effects/`, `EventCloseHandler`, `EventLevelTierService`, `EventRecipientFinder`,
  `NotificationPolicy`, `EventPreferenceService`; TaskHandlers `app/TaskHandlers/Events/`.
- NPC: `app/Services/NPC/NpcDialogueTreeService.php`, `NpcInteractionService.php`,
  `NpcRelationService.php`; TaskHandlers `app/TaskHandlers/NPC/`.

## Key types / contracts
`objective_type` квестов включает `collect_resource` и `building_level` (ADR-088).
Диалог NPC обязан быть ветвящимся: одиночная пара «вопрос-ответ» диалогом не считается.

## Dependencies
inbound: Telegram-actions, `Worker`.
outbound: ресурсы, статы, `Services/Notifications`, `GameSettings`.

## Gotchas
- Флаг one-shot у события обязан **исполняться**, а не просто существовать: не enforced —
  эффект компаундится.
- Дефолт в коде ≠ значение на проде: сверять `game_settings` на проде перед выводами.
- Enum-колонки в STRICT-режиме валят INSERT при значении вне списка (`action_log`).
- Ловушка (exploit-audit, `docs/specs/exploit-audit/REPORT.md` #5, `EA-tasks-03`): `quest_steps`
  несёт только внешние ключи, никакого `UNIQUE(quest_id, character_id)` — двойной тап
  `questStart<TitleEn>` может создать две строки; 0 дублей на проде при 1230 строках.

## Vault
`mmorpg-vault/apps/quests/index.md` · `mmorpg-vault/apps/events/index.md` · `mmorpg-vault/apps/npc/index.md`
