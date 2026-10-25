---
story: w-reg-honest-water-01
spec: w-reg-honest-water
status: todo
returned:
tier: 2
worker: worker-code
wave: 1
blocked_by: []
---

# Шаг «Дары пустоши» засчитывает добытую воду

## Goal
Добыча игрока оставляет отметку `gathered_resource` в `action_log`, пока у него активна цель
`gather_resource`; обработчик целей и «🎯 Сейчас» считают эти отметки; шаг `OnbStepGather` переведён на
новый тип миграцией-ресидом; паёк в зачёт не идёт.

## Requirements
> 1. Шаг «Дары пустоши» засчитывает только воду, добытую завершённой добычей; паёк (и любая вода, полученная не добычей) не считается.
> 2. Счётчик — отметка в `action_log` на каждую завершённую добычу; без новой таблицы и без миграции схемы.
> 3. «🎯 Сейчас: … (X/5)» на карточке Персонажа и на карте показывает добытое, а не воду в рюкзаке.
> 4. Тем, у кого шаг уже активен в момент выкатки, счёт с нуля (только добыча после выкатки).
> 5. Одинаково в боте и вебе: правило живёт в ядре (обработчик целей квестов и `PolarStarService`), не в рендерере клиента.
> 6. Текст шага не обещает того, чего нет: цель называет добычу («добудь 5 единиц воды»), паёк в счёт не идёт; `done_text` «Первый ресурс в кармане!» срабатывает только после реальной добычи.

## Files
- app/Services/Quest/GatheredResourceLedger.php
- app/Services/Player/Gather/GatherResultPersister.php
- app/TaskHandlers/Quests/QuestObjectiveHandler.php
- app/Services/Onboarding/PolarStarService.php
- app/Services/Onboarding/OnboardingChainCatalog.php
- app/Services/Quest/QuestChainService.php
- app/Services/Admin/FunnelAnalyticsService.php
- app/Database/Migrations/2026-10-25-120000_ReseedOnbStepGatherObjective.php
- tests/database/GatheredResourceLedgerTest.php

## Non-goals
- Не трогать `collect_resource` для остальных квестов — он остаётся «владеть в рюкзаке».
- Не менять паёк (`StarterKitService`) и награду шага.
- Не добавлять тип в админ-форму квестов и не писать отметки для робота-добытчика.
- Не заводить таблицу/колонку (ask 2).

## Map slice
memory/map/onboarding.md — цепочка и `PolarStarService`; `GatherResultPersister` — тонкий срез в `mmorpg-vault/tech-writing/services/`.

## Acceptance criteria
- [ ] Новый персонаж с пайком (10 воды) и активным `OnbStepGather`: цель не выполнена, «🎯 Сейчас» показывает 0/5.
- [ ] После добычи, принёсшей ≥5 воды суммарно (одной или несколькими добычами), цель выполнена; прогресс в «🎯 Сейчас» = добытое.
- [ ] Отметка не пишется, если активного `gather_resource` нет (ветеран добывает — строк в `action_log` не прибавилось).
- [ ] Миграция-ресид идемпотентна: `quests` строки `OnbStepGather` → `gather_resource` / `Water` / 5, новые goal/description из каталога; `down()` возвращает `collect_resource`.
- [ ] `php -l` миграции чистый; phpstan по тронутым файлам — 0 ошибок.

## Verification
`git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`
`vendor/bin/phpunit --no-coverage --no-progress tests/database/GatheredResourceLedgerTest.php`
`vendor/bin/phpunit --no-coverage --no-progress`

## Implementation notes

## Findings
