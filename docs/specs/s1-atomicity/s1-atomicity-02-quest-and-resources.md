---
story: s1-atomicity-02
spec: s1-atomicity
status: todo
returned:
tier: 3
worker: worker-code
wave: 1
blocked_by: []
red: required
# red_reason:
# red_verified_at:
---

# UNIQUE + атомарная запись: `quest_steps` и `character_resources`; S1b в ROADMAP

## Goal
После story `quest_steps` несёт `uq_qs_quest_character`. `QuestStartService.php:141` переходит с голого `$db->table('quest_steps')->insert()` на `insertUnique()`: `Refused` даёт прежний исход `ALREADY` без побочных эффектов (Contract C7). `character_resources` несёт `uq_cr_char_resource` вместо `idx_cr_char_resource` (C8). Шесть методов `CharacterResourceModel` пишут атомарно: `increaseResources` и `addOrIncreaseResource` — одним upsert; `deductResource`, `deductResourceForCraft`, `deductResourceByName` и `decreaseQtyById` — через `decrementIfAtLeast`. Сигнатуры и возвраты прежние, поэтому вызывающих не трогаем. Обе миграции проверяют дубли до индекса (C10). `ROADMAP.md` закрывает S1 строкой журнала и заводит S1b.

## Requirements
> 1. `quest_steps` получает `UNIQUE (quest_id, character_id)` миграцией; единственное место записи (`QuestStartService`) пишет через `insertUnique()`, и повторный старт того же квеста тем же персонажем (двойной тап, бот и веб одновременно) создаёт ровно одну строку и не падает исключением — проверяется тестом.
> 4. `character_resources` получает `UNIQUE (id_characters, id_resources)`; `increaseResources` и `addOrIncreaseResource` начисляют одним атомарным upsert — два одновременных начисления дают сумму обоих — проверяется тестом.
> 5. `deductResource`, `deductResourceForCraft` (и обёртка `deductResourceByName`), `decreaseQtyById` списывают условной записью (`quantity >= n` в самом UPDATE, через `decrementIfAtLeast` или его row-id форму): два одновременных списания не уводят количество ниже нуля и не списывают больше, чем было; сигнатуры и возвращаемые значения для вызывающих не меняются.
> 6. Миграции безопасны для прода: перед созданием UNIQUE проверяют дубли и при их наличии падают с понятным сообщением, а не удаляют строки; `php -l` чист; новые колонки и индексы попадают в тестовую схему (фикстуры, которые пишут DDL `character_tasks` руками); `WipeManifest` не меняется (новых таблиц нет).
> 7. strictOn в этой спеке не трогаем: `app/Config/Database.php` в диффе нет; в `ROADMAP.md` S1 закрыт строкой журнала, а новый пункт **S1b** (аудит записи под strict + переключение `strictOn`) стоит в треке S и в очереди §2.

## Files
- app/Database/Migrations/2026-12-25-100010_S1QuestStepsUnique.php
- app/Database/Migrations/2026-12-25-100020_S1CharacterResourcesUnique.php
- app/Services/Quest/QuestStartService.php
- app/Models/CharacterResourceModel.php
- tests/database/QuestStepsUniqueStartTest.php
- tests/database/CharacterResourceAtomicWriteTest.php
- tests/unit/Models/CharacterResourceConditionalWriteTest.php
- ROADMAP.md

## Non-goals
- Не править ни одного из ~30 вызывающих `CharacterResourceModel`. Если метод нельзя сделать атомарным без смены сигнатуры или возврата — стоп и отчёт.
- Не трогать `decrementIfAtLeast()` модели и `ConditionalWriteService`: upsert — сырой запрос в модели.
- Не трогать `app/Config/Database.php` (strictOn — S1b) и `WipeManifest`.
- Не чинить DDL остальных 41 теста с `character_resources`/`quest_steps` — это story 03–08.
- В `ROADMAP.md` только строка журнала S1 и пункт S1b, без перестановки остальной очереди.

## Map slice
`memory/map/quests-events-npc.md` → `## Gotchas` (exploit-audit `quest_steps`, `QuestStartService`). `memory/map/player.md` → `## Key types / contracts` (пара `id_characters`/`id_resources`, рудимент `id_telegram_users`). `memory/map/craft.md` → `## Gotchas` (ADR-181, списание ресурсов). Нота `tech-writing/services/ConditionalWriteService.md` (`deleteWhenEmpty`, различающий тест через `Events::on('DBQuery')`).

## Acceptance criteria
- [ ] **Воркер проверяет сам:** что сейчас возвращают `increaseResources` / `addOrIncreaseResource` (bool, новое количество, id) и удаляет ли `deductResource` / `decreaseQtyById` строку при нуле. После правки поведение то же.
- [ ] Обе миграции: подсчёт дублей пары до индекса, при наличии `RuntimeException` с таблицей, числом и до 5 примерами, строки не удаляются. `down()` обратим (у ресурсов возвращает `idx_cr_char_resource`).
- [ ] Оба новых теста берут индекс S1 запуском `up()` своей миграции, а не рукописным DDL.
- [ ] Квест: конкурирующая строка вклинивается между чтением-гейтом `start()` и вставкой (второе соединение или `Events::on('DBQuery')`). Итог — одна строка `quest_steps`, второй вызов отдаёт `ALREADY`, исключения нет, побочные эффекты старта не повторены.
- [ ] Начисление: два одновременных начисления (два соединения) одной пары — по ресурсу, которого нет, и по существующему — дают одну строку и сумму обоих. Начисление — одна SQL-инструкция.
- [ ] Списание: при `quantity = n` два одновременных списания `n` дают ровно один успех. Остаток не меньше 0, второй вызов возвращает прежнее значение «не хватило».
- [ ] Рукописный DDL `CharacterResourceConditionalWriteTest` — по C9, тест зелёный. Воркер гоняет его точечно; в `## Verification` его нет, чтобы `stamp-red` не запечатал (A8).
- [ ] `ROADMAP.md`: S1 закрыт строкой журнала, S1b стоит в треке S (§4) и в очереди §2.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/database/QuestStepsUniqueStartTest.php`
`vendor/bin/phpunit --no-coverage --no-progress tests/database/CharacterResourceAtomicWriteTest.php`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
`git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`

## Implementation notes

## Findings
