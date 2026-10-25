# W-REG F6: шаг «вода» засчитывает добытое (plan)

**Tier:** 2 · **Spec slug:** `w-reg-honest-water` · **Brief:** [brief.md](brief.md)
**Governed by:** ADR-088 (расширенные цели квестов), ADR-103 (онбординг-цепочка), ADR-139 (`PolarStarService`), ADR-190 (ядро)
**Depends on:** `w-reg-text-fixes` (v0.51.699)

## Goal
Новый тип цели `gather_resource`: прогресс = сумма воды, полученной **завершённой добычей**, по отметкам
в `action_log`. Шаг `OnbStepGather` переводится с `collect_resource` на него (каталог + ресединг строки
`quests`), «🎯 Сейчас (X/5)» считает то же самое. Паёк и прочая вода в рюкзаке в зачёт не идут.

## Assumptions
- Отметка пишется в `GatherResultPersister::persist()` (общий путь добычи игрока, оба клиента), **только
  пока у персонажа активен квест с `objective_type = gather_resource`** — у ветеранов строк не прибавляется.
  Робот-добытчик (`CompleteRobotGatheringHandler`) не считается: шаг учит добывать самому.
- Формат: одна строка на ресурс за добычу, `action_name = 'gathered_resource'`, количество и
  `resources.id` — в `description` (`<resource_id>:<qty>`), `action_status = 'Completed'`, `chat_id` — как
  у соседних маркеров онбординга (колонка NOT NULL).
- Учитывается то, что реально легло игроку после подати ADR-135 (вход `writeCharacterResources`), включая
  долю, уехавшую на склад базы транспортом.
- Тип `gather_resource` обрабатывается там же, где онбординг-типы (killswitch онбординг-цепочки), и в
  `QuestChainService`/`FunnelAnalyticsService` списках расширенных типов — для standalone-квестов в будущем.
- Админ-форма квестов (`_quest_form.php`) новый тип не получает — вне объёма.

## Stories

**Wave 1**
- `w-reg-honest-water-01` — ядро: отметка добычи, тип `gather_resource` в обработчике целей и `PolarStarService`, перевод шага + ресединг, тексты шага, тесты

## Contracts
- none

## Integration gate
`vendor/bin/phpunit --no-coverage --no-progress`

## Descoped

*(empty)*

## Plan deltas

**Approved:** Andrei, 2026-10-25
**Briefed:**
**Branch:**
**Checked:**
**Council:**
**Shipped:**
