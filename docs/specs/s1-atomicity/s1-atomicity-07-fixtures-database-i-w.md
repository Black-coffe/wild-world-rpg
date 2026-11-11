---
story: s1-atomicity-07
spec: s1-atomicity
status: todo
returned:
tier: 3
worker: worker-test
model: haiku
wave: 2
blocked_by: [s1-atomicity-01, s1-atomicity-02]
---

# Фикстуры `tests/database` I–W и `Transport/` получают схему S1

## Goal
Почему haiku: одна и та же механическая правка рукописного DDL по образцу Contract C9 в 18 файлах, без логики.

После story каждый из 18 тестов ниже несёт в своём рукописном `CREATE TABLE` колонки и индексы S1 ровно по C9, для каждой из трёх таблиц, что в нём есть (`character_tasks`, `character_resources`, `quest_steps`). Фикстура без `uq_cr_char_resource` молча вставляет вторую строку вместо начисления. Фикстура `character_tasks` без `is_exclusive` падает на «Unknown column». Определения `is_exclusive` и `exclusive_slot` копируются дословно из миграции `app/Database/Migrations/2026-12-25-100000_S1CharacterTasksExclusiveSlot.php` (story 01, уже влита; читать, не править).

## Requirements
> 6. Миграции безопасны для прода: перед созданием UNIQUE проверяют дубли и при их наличии падают с понятным сообщением, а не удаляют строки; `php -l` чист; новые колонки и индексы попадают в тестовую схему (фикстуры, которые пишут DDL `character_tasks` руками); `WipeManifest` не меняется (новых таблиц нет).

## Files
- tests/database/IntentApplierRelativeDeltasTest.php
- tests/database/ItemModifierServiceTest.php
- tests/database/LootProcessorTest.php
- tests/database/LootTableServiceTest.php
- tests/database/MarchMiniEventServiceTest.php
- tests/database/OnboardingNudgeHandlerTest.php
- tests/database/PlayViewControllerTest.php
- tests/database/PlayerEconomyServiceTest.php
- tests/database/QuestChainServiceTest.php
- tests/database/ResourceOverviewServiceTest.php
- tests/database/ResourceShopBotParityTest.php
- tests/database/ResourceShopScreenServiceTest.php
- tests/database/ResourceTradeGoldRaceTest.php
- tests/database/RuinLootCooldownReserveTest.php
- tests/database/RuinLootServiceTest.php
- tests/database/StreakMilestoneServiceTest.php
- tests/database/WebCraftStartTailsTest.php
- tests/database/Transport/DeathWiringTest.php

## Non-goals
- Не менять ассерты, сиды и логику тестов — только DDL трёх таблиц.
- Не переводить фикстуры на запуск миграций и не выносить общий хелпер схемы.
- Не трогать `app/` и тесты из `## Files` других story.

## Map slice
Contract C9 в `plan.md`. `memory/map/tasks-worker.md` → `## Gotchas` (пункт про `task_settings`: рукописный DDL `character_tasks` вынужден).

## Acceptance criteria
- [ ] В каждом файле DDL `character_tasks` (если есть) содержит `is_exclusive`, `exclusive_slot` (generated, STORED) и `UNIQUE KEY uq_ct_exclusive_slot`. DDL `character_resources` (если есть) — `UNIQUE KEY uq_cr_char_resource (id_characters, id_resources)` вместо `idx_cr_char_resource`. DDL `quest_steps` (если есть) — `UNIQUE KEY uq_qs_quest_character (quest_id, character_id)`.
- [ ] Каждый тест файла зелёный. Если тест краснеет из-за нового UNIQUE (сид вставляет дубль пары) или из-за изменения поведения из story 03/05 (`PlayViewControllerTest`, `WebCraftStartTailsTest` проходят через `CraftOrderService`), его не чинить: записать в Findings и вернуть `NEEDS_CONTEXT`.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/database/IntentApplierRelativeDeltasTest.php`
`vendor/bin/phpunit --no-coverage --no-progress tests/database/ItemModifierServiceTest.php`
`vendor/bin/phpunit --no-coverage --no-progress tests/database/LootProcessorTest.php`
`vendor/bin/phpunit --no-coverage --no-progress tests/database/LootTableServiceTest.php`
`vendor/bin/phpunit --no-coverage --no-progress tests/database/MarchMiniEventServiceTest.php`
`vendor/bin/phpunit --no-coverage --no-progress tests/database/OnboardingNudgeHandlerTest.php`
`vendor/bin/phpunit --no-coverage --no-progress tests/database/PlayViewControllerTest.php`
`vendor/bin/phpunit --no-coverage --no-progress tests/database/PlayerEconomyServiceTest.php`
`vendor/bin/phpunit --no-coverage --no-progress tests/database/QuestChainServiceTest.php`
`vendor/bin/phpunit --no-coverage --no-progress tests/database/ResourceOverviewServiceTest.php`
`vendor/bin/phpunit --no-coverage --no-progress tests/database/ResourceShopBotParityTest.php`
`vendor/bin/phpunit --no-coverage --no-progress tests/database/ResourceShopScreenServiceTest.php`
`vendor/bin/phpunit --no-coverage --no-progress tests/database/ResourceTradeGoldRaceTest.php`
`vendor/bin/phpunit --no-coverage --no-progress tests/database/RuinLootCooldownReserveTest.php`
`vendor/bin/phpunit --no-coverage --no-progress tests/database/RuinLootServiceTest.php`
`vendor/bin/phpunit --no-coverage --no-progress tests/database/StreakMilestoneServiceTest.php`
`vendor/bin/phpunit --no-coverage --no-progress tests/database/WebCraftStartTailsTest.php`
`vendor/bin/phpunit --no-coverage --no-progress tests/database/Transport/DeathWiringTest.php`

## Implementation notes

## Findings
