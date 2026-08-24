---
story: chat-requests-batch-01
spec: chat-requests-batch
status: done
tier: 1
worker: worker-code
tracer: true
wave: 1
blocked_by: []
---

# Робот-промышленник называет себя своим именем

## Goal
Все сообщения о работе робота-сборщика называют ту машину, которую игрок реально
запустил: Промышленник подписывается Промышленником, Добытчик — Добытчиком. Сейчас
имя «Робот-добытчик» зашито константой в семи местах одного обработчика.

## Requirements
> [19.08.2026] Max Syskov: «и у меня промышленник, но в сообщении добытчик»

## Files
- app/TaskHandlers/CompleteRobotGatheringHandler.php
- tests/unit/TaskHandlers/CompleteRobotGatheringNameTest.php

## Notes
Резолвер уже есть в этом же файле (~строка 452): `task_settings.crafted_item_id` →
`CraftedItemsModel::find()`. Взять оттуда `name_rus` и подставить вместо константы.
Строки с хардкодом: 118, 125, 142, 165, 185, 224, 381.
Если строка не нашлась — падать на нейтральное «Робот», а не на чужое имя.

## Non-goals
- Не менять формулу охвата и состав добычи (это story 02 и она про текст экрана).
- Не трогать `RobotGathererActivator` и запуск — правится только текст отчёта.
- Не переименовывать сущности в каталоге `crafted_items` (это отдельная заявка №3).

## Acceptance criteria
- [x] Создан и зелёный `tests/unit/TaskHandlers/CompleteRobotGatheringNameTest.php` — он обязан падать на доправочном поведении,
      иначе гейт зелёный впустую (урок «скан исходника ≠ покрытие»).
- [x] `vendor/bin/phpunit --no-coverage --no-progress` целиком зелёный.

## Verification
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress app/TaskHandlers/CompleteRobotGatheringHandler.php`

## Findings

Добавлен приватный резолвер `resolveRobotDisplayName(array $task): string` (рядом с
уже существовавшим `resolveRobotNameEn()`, ~стр. 452) — читает тот же
`task_settings.crafted_item_id` → `CraftedItemsModel::find()`, но берёт `name_rus`
вместо `name_eng`. Не нашлось строки / `crafted_item_id` / `task_settings` —
нейтральный фолбэк `'Робот'`. Вызывается один раз в начале `handle()` (сразу после
резолва `$chatId`, до всех веток с текстом) — `$robotName` подставлен во все 7
хардкод-мест (118, 125, 142, 165, 185, 224 → `$robotName`; 381 через новый параметр
`formatGatheringResultMessage(..., string $robotName = 'Робот')`, единственный
caller которого — этот же класс, сигнатуру безопасно расширить дефолтным значением).

Тест `CompleteRobotGatheringNameTest` ловит баг двумя способами: (1) вставляет
реальную строку `crafted_items` (`hasInDatabase`, авто-откат) и проверяет, что
резолвер вернул именно `name_rus` этой строки, а не константу; (2) вызывает
`formatGatheringResultMessage` напрямую и утверждает `assertStringNotContainsString('Робот-добытчик', ...)`
— старая константа больше не может утечь в текст отчёта. Проверено: откат правки
(`git stash` только на handler) даёт 3 ReflectionException (метода не существует)
+ 1 assertion failure (сообщение содержит старую константу) — тест красный именно
на доправочном коде, не проходит по совпадению.

Локальная `wildworld_tests` — разреженная база без миграций `buildings`/`crafted_items`
(конструктор хендлера бьёт в `buildings` живым запросом, DI на модели нет). Тест сам
создаёт обе таблицы в `setUp()` только если их ещё нет (`tableExists()`-гейт) и дропает
только те, что создал сам — на окружении с полным дампом (CI/testbot) тест их не трогает.

Полный `vendor/bin/phpunit --no-coverage --no-progress`: 3350 тестов, 28139 assertions,
без failures/errors (36 pre-existing deprecations, 10 skipped — не мои).
