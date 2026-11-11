# S1 — пакет атомарности

**Date:** 2026-11-11 · **Tier:** 3 · **Source:** `ROADMAP.md` §2 п.3, §4 трек S (S1)

## Request

> бнри следующую задачу с роудмепа

Пункт ROADMAP, на который указывает очередь (п.1 E-FIRST.M ждёт 20 новичков — на 11.11 их 6; п.2 W-REG.4 ждёт OAuth-ключи):

> **S1** · Tier 3 · Пакет атомарности: `UNIQUE` на `quest_steps(quest_id, character_id)` и «одна эксклюзивная задача» в `character_tasks` со всеми местами записи через `insertUnique()` (F11); четыре read-then-write метода `CharacterResourceModel` (`increaseResources`, `deductResource`, `deductResourceForCraft`, `decreaseQtyById`); `strictOn => false` (`app/Config/Database.php:42,67`) → аудит сырых INSERT и `true`.

## Recon facts (прод, только чтение, 2026-11-11)

- `quest_steps`: 1409 строк, 1409 различных пар `(quest_id, character_id)` — дублей нет, `UNIQUE` ставится без чистки. Индексы — только PK и два FK.
- `character_tasks`: статусы in_work 20, paused 95, queued 17, completed 1080, interrupted 1. Индексы — PK, FK, `idx_ct_status_endtime`, `idx_ct_character_status`; уникальных нет.
- 🔒-задач (`tasks.parallel_execution_allowed = 0`) больше одной в `in_work`/`paused` у **10** персонажей; в `in_work` вместе с параллельными (робот, крафт) — обычное дело (6 персонажей держат 2+ in_work, это законно).
- Правило «🔒 поверх 🔒 не начинается» живёт в коде с ADR-167: `ActiveTasksService::findBlockingTask` / `exclusiveConflict` (проверка read-then-write, не ограничение БД).
- `ConditionalWriteService::insertUnique()` есть (`app/Services/Db/ConditionalWriteService.php:319`, ADR-181).
- `strictOn => false` — `app/Config/Database.php:42` и `:67`; на проде `@@sql_mode` уже содержит `STRICT_TRANS_TABLES` (сервер строгий, CI4-флаг — нет).
- 🔒-дублей в `in_work` на проде **0**: правило ADR-167 (`ActiveTasksService::findBlockingTask`, `ActiveTasksService.php:164-165`) считает только `in_work`; 10 персонажей с 2+ 🔒 держат их в `paused` — ограничение по `in_work` их не задевает.
- `character_resources`: 4976 строк, дублей пары `(id_characters, id_resources)` **0**; есть неуникальный `idx_cr_char_resource` на эту пару.
- Места записи: `character_tasks` — 24 вызова insert в 14 файлах (`CraftOrderService`, `ActiveTasksService`, `RelocationTaskCreator`, `MarchingTaskHandler`, `RepairCraftedItemAction`, `PlantCropActionStart`, `StartRobotExplorationAction`, `StartrobotexplorerCommand`, 6 legacy `StartCraft*2Action`); `quest_steps` — одна (`QuestStartService.php:141`).
- Прецедент «уникальность при условии»: generated column + UNIQUE в `pvp_standoffs` (`2026-09-11-210000_Adr186CreatePvpStandoffs.php:88`); `ActiveTasksService::insertExclusiveTaskRow()` (`:50`) уже пишет через `insertUnique()`.
- `CharacterResourceModel` (`app/Models/CharacterResourceModel.php`): `increaseResources` (`:40-61`, 7 вызовов в 5 файлах) и двойник `addOrIncreaseResource` (`:115-135`, 18 вызовов в 17 файлах) — чтение и абсолютная запись суммы; `deductResource` (`:154-175`, 3), `deductResourceForCraft` (`:203-231`, 1 + обёртка `deductResourceByName`), `decreaseQtyById` (`:237-249`, 2) — проверка в PHP без блокировки. Атомарный сосед уже есть: `decrementIfAtLeast()` (`:71-94`) на `ConditionalWriteService::decrementIfAtLeast`.
- `strictOn => false` у CI4 MySQLi **вырезает** `STRICT_*` из `sql_mode` сессии (`vendor/codeigniter4/framework/system/Database/MySQLi/Connection.php:126-145`) — переключение меняет поведение прода; мест записи в `app/` — сотни.

## Answers

1. **strictOn** — Отдельным пунктом (Рекомендую): S1 = UNIQUE на квестах, «одна 🔒-задача», методы ресурсов; strictOn — новый пункт S1b в ROADMAP: сначала аудит (study), потом переключение. У strictOn другой риск и другой откат.
2. **🔒-задача** — Индекс в БД (Рекомендую): вычисляемая колонка «занятый 🔒-слот» + UNIQUE, как у PvP-противостояний (ADR-186); все места записи идут через `insertExclusiveTaskRow()`. БД сама откажет второй записи; на проде нарушений 0.
3. **Начисление** — Оба через UNIQUE + upsert (Рекомендую): UNIQUE на (персонаж, ресурс) — дублей 0 из 4976; начисление одним `INSERT … ON DUPLICATE KEY UPDATE quantity = quantity + n`; двойник `addOrIncreaseResource` чинится в той же спеке.

## Asks

1. `quest_steps` получает `UNIQUE (quest_id, character_id)` миграцией; единственное место записи (`QuestStartService`) пишет через `insertUnique()`, и повторный старт того же квеста тем же персонажем (двойной тап, бот и веб одновременно) создаёт ровно одну строку и не падает исключением — проверяется тестом.
2. У персонажа не может быть двух задач `in_work` с `tasks.parallel_execution_allowed = 0`: ограничение держит БД (вычисляемая колонка + UNIQUE по образцу ADR-186), а не только проверка ADR-167; параллельные задачи (крафт, роботы) по-прежнему идут рядом с 🔒-задачей; `paused`/`queued`/`completed` ограничение не задевают — проверяется тестом с двумя одновременными стартами 🔒-задачи.
3. Все места записи в `character_tasks` (24 вызова в 14 файлах, список в брифе) пишут через `ActiveTasksService::insertExclusiveTaskRow()`; отказ БД из-за занятого 🔒-слота игрок видит тем же текстом отказа ADR-167 («уже занят …»), а не ошибкой, и ресурсы при отказе не списываются.
4. `character_resources` получает `UNIQUE (id_characters, id_resources)`; `increaseResources` и `addOrIncreaseResource` начисляют одним атомарным upsert — два одновременных начисления дают сумму обоих — проверяется тестом.
5. `deductResource`, `deductResourceForCraft` (и обёртка `deductResourceByName`), `decreaseQtyById` списывают условной записью (`quantity >= n` в самом UPDATE, через `decrementIfAtLeast` или его row-id форму): два одновременных списания не уводят количество ниже нуля и не списывают больше, чем было; сигнатуры и возвращаемые значения для вызывающих не меняются.
6. Миграции безопасны для прода: перед созданием UNIQUE проверяют дубли и при их наличии падают с понятным сообщением, а не удаляют строки; `php -l` чист; новые колонки и индексы попадают в тестовую схему (фикстуры, которые пишут DDL `character_tasks` руками); `WipeManifest` не меняется (новых таблиц нет).
7. strictOn в этой спеке не трогаем: `app/Config/Database.php` в диффе нет; в `ROADMAP.md` S1 закрыт строкой журнала, а новый пункт **S1b** (аудит записи под strict + переключение `strictOn`) стоит в треке S и в очереди §2.
8. Вердикты: в `/guide` — нет, совет — нет, потому что игрок не видит новой механики (тексты отказов прежние); tech-writing ноты тронутых моделей, сервисов, handler'ов и `db/` таблиц обновлены; ADR — решение «эксклюзивность задачи держит БД» записано в `mmorpg-vault/decisions/` (или дополнением к ADR-167).
