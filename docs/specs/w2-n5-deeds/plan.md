# W2.N5 — «Дела» на нейтральном ядре: хаб, квесты, ежедневки, события (plan)

**Tier:** 2 · **Spec slug:** `w2-n5-deeds` · **Brief:** [brief.md](brief.md)
**Governed by:** ADR-190 (нейтральное ядро), ADR-189 (мост как фолбэк), ADR-150 (навигация, хаб «📋 Дела»), ADR-088 (квесты; поправка 2026-09-23 — карточка из `quests`), ADR-181 (условные записи), ADR-062, ADR-024, ADR-020
**Depends on:** w2-n4-base (v0.51.681): `WebNativeScreenService` VIEW_*/OP_*, `assertIntent`/`claim`, дедуп `web_play_intents`, `site/_play/{hud,dock}.php`

## Goal
Экраны «Дела» перестают строиться внутри Telegram-handler'ов. Ядро отдаёт модели хаба (активные задачи,
сводка квестов, ежедневки), трёх списков квестов и «Событий»; старт квеста и выбор ветки — одна операция ядра
под блокировкой строки персонажа, как и выдача ежедневок. Бот рисует из моделей прежние тексты и кнопки. В вебе
появляется нативный «📋 Дела» (`view=tasks`) со стартом квеста и выбором ветки.

## Assumptions
- Ответы гриля приняты по рекомендации владельца («по рекомендации вперёд»), см. `brief.md` `## Answers`.
- Нейтральные сервисы уже есть и расширяются, а не дублируются: `QuestOverviewService` (сводка, классификация),
  `DailyTaskService` (`today`, `ensureAssigned`), `QuestChainService` (`pendingBranchesForCharacter`, `chooseBranch`).
  Новое: модель хаба из `TasksSurfaceService` (данные вместо Markdown), `QuestListService` (активные / доступные /
  завершённые), `QuestStartService::start()` (тело `GenericQuestStartAction`), модель событий из `EventAction`.
- Блокировка: транзакция + `SELECT id FROM characters WHERE id = ? FOR UPDATE`, затем перепроверка и вставка —
  в `QuestStartService::start`, `QuestChainService::chooseBranch`, `DailyTaskService::ensureAssigned`.
  `UNIQUE` на `quest_steps` не вводим (повторяемость квестов не проверена). Четыре легаси-старта
  (`QuestStartExplore30Cells` и соседи) переводятся на `QuestStartService`.
- Веб не переносит «⛔ Прервать» и «Квестоманию» — в вебе это кнопки моста (`op=bridge`), как прочие экраны бота.
- Персонаж в бот-списках — через `BaseAction`, не `getCharacterIdByTelegramId($chatId)` (виртуальный чат моста).
- Карточка квеста в вебе — из строки `quests` (поправка ADR-088), без действий, которых нет у бота.
- Новых чисел баланса нет; новых таблиц/колонок нет (WipeManifest не меняется).
- `verify-gap` wave-check на полном наборе — известный ложный сигнал (правка на `vulyk/evolve-2026-09-29`);
  `empty-glob` — новые файлы.
- Ask 6 (вердикты) и ask 7 (живой проход) закрывает Queen после сборки.

## Stories

**Wave 1**
- `w2-n5-deeds-01` — пути записи: `QuestStartService::start()` (тело `GenericQuestStartAction` + четыре легаси-старта), блокировка строки персонажа в старте, выборе ветки и выдаче ежедневок.

**Wave 2**
- `w2-n5-deeds-02` — модели «Дела»: хаб (`TasksSurfaceService`), списки квестов (`QuestListService`), события (`EventsModelService`); бот-экраны — рендереры с прежним текстом (паритет тестом).

**Wave 3**
- `w2-n5-deeds-03` — веб `view=tasks`: хаб, списки, карточка, события, старт квеста и выбор ветки с `intent_id`, замки флагов, док.

## Contracts
- `QuestStartService::start(int $characterId, string $titleEn): array{ok: bool, code: string, message: string, title_ru: ?string}`;
  коды `started` / `already` / `locked` / `disabled` / `unknown`.
- `QuestChainService::chooseBranch()` — прежняя сигнатура и результат, внутри блокировка.
- `QuestListService::{active, available, completed}(int $characterId): list<array>` — строки с `id`, `title_en`, `title_ru`,
  `reward`, для доступных — `locked` + `lock_reason`.
- `TasksSurfaceService::model(int $characterId): array` — активные задачи (`ends_at`), сводка квестов, ежедневки;
  `buildScreen()` рисует из неё прежний текст.
- `EventsModelService::model(int $characterId): array{active: list, past: list}` — с `touched: bool`.
- Веб: `WebNativeScreenService::VIEW_TASKS = 'tasks'`, `OP_QUEST_START`, `OP_QUEST_BRANCH`, вьюха `site/_play/native_tasks.php`.

## Integration gate
`vendor/bin/phpunit --no-coverage --no-progress`

## Descoped

*(empty)*

## Plan deltas
**Briefed:** via grill (assumed), Andrei, 2026-09-29
**Branch:** vulyk/w2-n5-deeds
