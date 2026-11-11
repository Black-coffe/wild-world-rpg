# S1 — пакет атомарности (plan)

**Tier:** 3 · **Spec slug:** `s1-atomicity` · **Brief:** [brief.md](brief.md)
**Model profile:** opus-builds
**Governed by:** ADR-167 (🔒 поверх 🔒), ADR-181 (условная запись, `insertUnique`, `decrementIfAtLeast`), ADR-186 (generated column + UNIQUE, `2026-09-11-210000_Adr186CreatePvpStandoffs.php:88`), ADR-087 (WipeManifest — не меняется), ADR-190 (ядра бот+веб). Ноты: `tech-writing/services/ActiveTasksService.md`, `tech-writing/services/ConditionalWriteService.md`.
**Depends on:** exploit-fix (ADR-181: `insertExclusiveTaskRow()`, `ConditionalWriteService`), s2-conditional-writes — в `develop`.

## Goal
Три гонки закрываются на уровне БД, а не чтения. (1) `quest_steps` получает UNIQUE на пару квест-персонаж, а `QuestStartService` переходит с голого `insert()` на `insertUnique()`: повторный старт сводится к исходу «уже начат». (2) `character_tasks` получает денормализованный флаг 🔒 (`is_exclusive`, заполняется при вставке из `tasks.parallel_execution_allowed`) и STORED-колонку `exclusive_slot`. Колонка равна id персонажа только для строки `in_work` с флагом и NULL для всех остальных; UNIQUE по ней. Все вставки идут через `ActiveTasksService::insertExclusiveTaskRow()`, а переходы в `in_work` строк, которые могут быть 🔒, — через новый `moveToInWork()`. Отказ БД превращается в `Refused` и в текст ADR-167, списание при этом не происходит. (3) `character_resources` получает UNIQUE на (персонаж, ресурс). Начисление делается одним upsert, четыре списания — условным UPDATE; сигнатуры не меняются. Все 55 тестов с рукописным DDL этих таблиц получают новую схему. `strictOn` не трогаем, он уходит в S1b.

## Assumptions
- **A1. Флаг 🔒 — копия на момент вставки.** `is_exclusive` берётся из `tasks.parallel_execution_allowed` в `insertExclusiveTaskRow()` и бэкфиллится миграцией для всех строк. Если админ позже поменяет флаг у `tasks`, существующие строки не переписываются.
- **A2. Killswitch `craft.exclusive_lock.enabled` = 0 → новые строки пишутся с `is_exclusive = 0`.** Так выключатель по-прежнему снимает блокировку без деплоя. Иначе БД держала бы блокировку и при выключенном ADR-167. Владелец может выбрать «БД держит всегда»: тогда правка в `insertExclusiveTaskRow()` одна.
- **A3. Возобновление `paused` 🔒, пока другая 🔒 в `in_work`, отказывается** тем же текстом ADR-167, строка остаётся `paused`. Ask 2 «`paused` ограничение не задевают» читается так: пауза не занимает слот. Обхода при возобновлении это не означает.
- **A4. Фоновые отказы** (следующий шаг Похода, продвижение очереди крафта, завершение переезда) не бросают исключение. Строка не создаётся или не переводится, игроку уходит текст ADR-167 тем путём сообщений, который у handler'а уже есть. Очередной 🔒-крафт, который не смог продвинуться, остаётся `queued` (виден в очереди, отменяется с возвратом).
- **A5. Состав мест записи** — 13 вставок в 12 файлах + `ActiveTasksService` (итого 14 файлов, совпадает со списком брифа). Если воркер найдёт вставку вне своего `## Files`, он возвращает `NEEDS_CONTEXT`, и она приходит дельтой плана.
- **A6. Тех-ноты и ADR (ask 8)** пишутся после мерджа: ноты — `drone-docs` в `mmorpg-vault/tech-writing/`, решение «эксклюзивность держит БД» — Queen дополнением к ADR-167 в `mmorpg-vault/decisions/`. В `## Files` story их нет. Вердикты guide/tips («нет — игрок не видит новой механики») зафиксированы в брифе.
- **A7. Тест с рукописным DDL обновляет тот, кто им владеет.** 13 из 55 тестов проверяют файлы, которые меняют story 01–05, и лежат в их `## Files`. Остальные 42 разделены между тремя haiku-story по каталогам. Тест, у которого в фикстуре нет UNIQUE, ничего не доказывает: upsert без UNIQUE молча вставит вторую строку.
- **A8. Red-story называют в `## Verification` только свой красный тест** (плюс phpstan и lint миграций). `stamp-red` запечатывает каждый тест из `## Verification`, а существующие тесты, которым story только чинит DDL, запечатывать нельзя. Их воркер гоняет точечно, полный набор раунда — подтверждает.

- **A9. Coverage (drone-coverage, 2026-11-11) — partial, несётся вперёд:** ask 1 и ask 4 — гонки проверяют `tests/database/QuestStepsUniqueStartTest.php` и `tests/database/CharacterResourceAtomicWriteTest.php` (story 02, red); ask 3 — «24 вызова» в брифе считали и комментарии с `insertUnique`, реальных вставок 13 в 12 файлах + `ActiveTasksService`, а UPDATE-пути в `in_work` (GatherAction, MarchService, PlayerStateService и др.) добавлены сверх списка брифа — по ask 2 их тоже держит БД; ask 7 — story 02 ставит S1b в трек S (§4) и в очередь §2, S1 — строкой журнала §6; ask 8 — ноты и дополнение ADR-167 после мерджа (A6). Ask 2 при выключенном killswitch держит не БД, а никто — это A2, решение владельца на утверждении.

## Recon answers (Queen, ripgrep, 2026-11-11)
- Рукописный DDL (`CREATE TABLE … <table>`) — в 55 тестах: `character_resources` 42, `character_tasks` 13, `quest_steps` 6. Общего хелпера схемы нет. Распределение — A7 и `## Files` story.
- Самая поздняя миграция — `2026-12-24-100020_SeedTailMedicineTip.php`; префиксы `2026-12-25-1000{00,10,20}` свободны.
- `StartCraftPortableTeleport2Action.php` лежит в `…/Craft/WorkbenchStandard/TeleportBeacon/`.
- `QuestStartService.php:141` — голый `$db->table('quest_steps')->insert([...])`, не `insertUnique`.
- Очередь крафта: `CraftQueueService` только читает (`:76`). Переход `queued → in_work` — около `GenericCraftCompletionHandler.php:284` и `CraftOrderService.php:308`; story 03 указывает на оба места, воркер подтверждает.
- `app/Controllers/Telegram/Commands/BaseShiftingCommand.php`, ветка `Refused` у `insertUnique` — комментарий на `:209`.
- **Воркер проверяет сам по своим файлам:** имена и типы FK в `character_tasks` (story 01), способ создания строки задачи в `GatherAction` / `MarchService` / `PlayerStateService` / `StartCraftPortableTeleport2Action` (story 03, 05), копирование целой строки задачи (story 01, 03, 05), текущие возвраты методов ресурсов и удаление нулевой строки (story 02).

## Audience surfaces
Новой фичи и нового входа нет. Игрок встречает только текст отказа ADR-167 на том экране, где он нажал «начать», — в боте или на `/play` (ядра ADR-190 общие).

| Что | Где бывает аудитория (доказательство) | Где стоит вход |
|---|---|---|
| отказ «уже занят …» при гонке старта 🔒-задачи | bot, play (старт задач из обоих клиентов, ADR-190) | bot, play (тот же экран старта) |
| повторный старт квеста → «уже начат» | bot, play (`QuestStartService` — ядро для бота и веба) | bot, play (прежний исход `ALREADY`) |

## Stories

| Story | File | Wave | Files | Red | Model |
|---|---|---|---|---|---|
| `s1-atomicity-01` | `s1-atomicity-01-exclusive-slot.md` | 1 | 5 | required | — |
| `s1-atomicity-02` | `s1-atomicity-02-quest-and-resources.md` | 1 | 8 | required | — |
| `s1-atomicity-03` | `s1-atomicity-03-craft-call-sites.md` | 2 | 16 | required | — |
| `s1-atomicity-04` | `s1-atomicity-04-camp-and-relocation-call-sites.md` | 2 | 14 | required | — |
| `s1-atomicity-05` | `s1-atomicity-05-field-call-sites.md` | 2 | 9 | required | opus |
| `s1-atomicity-06` | `s1-atomicity-06-test-schema-fixtures.md` | 2 | 14 | — | haiku |
| `s1-atomicity-07` | `s1-atomicity-07-fixtures-database-i-w.md` | 2 | 18 | — | haiku |
| `s1-atomicity-08` | `s1-atomicity-08-fixtures-unit-exploit.md` | 2 | 10 | — | haiku |

**Wave 1**
- `s1-atomicity-01` — `character_tasks`: `is_exclusive` + `exclusive_slot` + UNIQUE (миграция с бэкфиллом и проверкой нарушений); `ActiveTasksService`: `insertExclusiveTaskRow` ставит флаг, новые `moveToInWork` / `slotTakenText` / `lastInsertedTaskId`; тест двух одновременных стартов.
- `s1-atomicity-02` — `quest_steps` UNIQUE + `QuestStartService` через `insertUnique`; `character_resources` UNIQUE + upsert-начисление + условные списания в `CharacterResourceModel`; `ROADMAP.md` (S1 закрыт, S1b заведён).

**Wave 2** (все `blocked_by: [01, 02]`; файлы попарно не пересекаются)
- `s1-atomicity-03` — крафт: `CraftOrderService`, продвижение очереди, 7 legacy `StartCraft*2Action`, ремонт.
- `s1-atomicity-04` — роботы, теплица, переезд (+ `BaseShiftingCommand`), удаление базы, стройка.
- `s1-atomicity-05` — поле: Поход (handler и сервис), добыча, мини-разведка, `PlayerStateService`.
- `s1-atomicity-06` / `07` / `08` — рукописный DDL оставшихся 42 тестов → схема C9: `tests/database` A–G; `tests/database` I–W и `Transport/`; `tests/unit` и `tests/exploit-poc`.

## Contracts
- **C1. Схема `character_tasks`** (story 01, миграция `2026-12-25-100000_S1CharacterTasksExclusiveSlot`):
  `is_exclusive TINYINT(1) NOT NULL DEFAULT 0`;
  `exclusive_slot <тип FK персонажа> GENERATED ALWAYS AS (IF(status = 'in_work' AND is_exclusive = 1, <FK персонажа>, NULL)) STORED`;
  `UNIQUE KEY uq_ct_exclusive_slot (exclusive_slot)`. Синтаксис — по прецеденту ADR-186. `exclusive_slot` прикладной код **никогда не пишет**: его нет в `CharacterTaskModel::$allowedFields` (`is_exclusive` там есть), а код, копирующий строку, снимает `id` и `exclusive_slot`.
- **C2. `ActiveTasksService::insertExclusiveTaskRow(array $row): WriteOutcome`** — сигнатура прежняя, вызывается для **любой** вставки `character_tasks`, параллельной тоже. Если вызывающий не передал `is_exclusive`, сервис ставит его сам: `1`, если `tasks.parallel_execution_allowed = 0` для задачи строки и `exclusiveLockEnabled()`, иначе `0`. `Applied` — строка вставлена. `Refused` — 🔒-слот персонажа занят, ничего не вставлено. Исключение уходит дальше только на прочие ошибки (NOT NULL, FK).
- **C3. `ActiveTasksService::lastInsertedTaskId(): int`** — id строки после `Applied` от C2 на том же соединении, `0` в остальных случаях. Для мест, которым нужен id новой задачи.
- **C4. `ActiveTasksService::moveToInWork(int $charTaskId, string $fromStatus, array $set = []): WriteOutcome`** — одна инструкция `UPDATE IGNORE character_tasks SET status = 'in_work', <$set> WHERE id = ? AND status = ?`. `Applied` — переведена. `Refused` — строка всё ещё в `$fromStatus`: слот занят. `Missing` — строки в `$fromStatus` нет. Не бросает исключение на коллизию UNIQUE и не портит `transStatus` открытой транзакции вызывающего. Ключи `$set` — по правилу имён `ConditionalWriteService`, `status` и `exclusive_slot` в `$set` запрещены (`InvalidArgumentException`). Нужен только переходам строк, которые могут быть 🔒. Параллельные задачи и переходы **из** `in_work` остаются как есть.
- **C5. `ActiveTasksService::slotTakenText(int $characterId, string $attemptedNameRus = ''): string`** — Markdown-текст отказа ADR-167 («уже занят …», через `ActionScopeService::exclusiveBlockText`) для текущей блокирующей задачи. Если блокировщик успел исчезнуть, возвращает `alreadyStartedExclusiveTaskText()`. Единственный текст для любого `Refused` из C2/C4 на игроцком пути.
- **C6. Порядок записи при старте:** вставка или перевод задачи (C2/C4) идёт **до** списания ресурсов, золота и предметов, либо в одной транзакции с ними, и `Refused` откатывает списание. На `Refused` ничего не списано и ничего не выдано.
- **C7. `quest_steps`:** `UNIQUE KEY uq_qs_quest_character (quest_id, character_id)`; `QuestStartService::start()` пишет строку через `ConditionalWriteService::insertUnique()`. `Refused` → прежний исход `ALREADY`, побочные эффекты старта не выполняются.
- **C8. `character_resources`:** `UNIQUE KEY uq_cr_char_resource (id_characters, id_resources)` заменяет неуникальный `idx_cr_char_resource`, `down()` его возвращает. Начисление — одна инструкция `INSERT … ON DUPLICATE KEY UPDATE quantity = quantity + ?` (параметр, не `VALUES()`). Списание — `ConditionalWriteService::decrementIfAtLeast('character_resources', $rowId, 'quantity', $n, <как сейчас при нуле>)`. Публичные сигнатуры и возвращаемые значения шести методов модели прежние.
- **C9. Тестовая схема.** Тест с рукописным DDL обновляет **все** три таблицы, которые в нём есть, и это делает тот story, у которого тест в `## Files`. В `character_tasks` — `is_exclusive` и `exclusive_slot` с определениями, скопированными дословно из миграции story 01, плюс `UNIQUE KEY uq_ct_exclusive_slot (exclusive_slot)`. В `character_resources` — `UNIQUE KEY uq_cr_char_resource (id_characters, id_resources)` вместо `idx_cr_char_resource`. В `quest_steps` — `UNIQUE KEY uq_qs_quest_character (quest_id, character_id)`. Новые красные тесты story 01/02 берут колонки и индексы S1 запуском `up()` своей миграции, а не рукописным DDL.
- **C10. Миграции S1** перед созданием UNIQUE считают нарушения (дубли пар; у `character_tasks` — персонажей с 2+ `in_work` и `is_exclusive = 1` после бэкфилла). При нарушениях бросают `RuntimeException` с таблицей, числом и до 5 примерами. Строки не удаляют. `down()` полностью обратим.

## Integration gate
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Se*`
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/[!S]* tests/unit/S[!e]*`
`vendor/bin/phpunit --no-coverage --no-progress tests/database/[AC]*Test.php`
`vendor/bin/phpunit --no-coverage --no-progress tests/database/[BE]*Test.php`
`vendor/bin/phpunit --no-coverage --no-progress tests/database/[DF]*Test.php`
`vendor/bin/phpunit --no-coverage --no-progress tests/database/[G-O]*Test.php tests/database/*/`
`vendor/bin/phpunit --no-coverage --no-progress tests/database/P[!v-z]*Test.php tests/database/U*Test.php`
`vendor/bin/phpunit --no-coverage --no-progress tests/database/P[v-z]*Test.php`
`vendor/bin/phpunit --no-coverage --no-progress tests/database/[Q-S]*Test.php`
`vendor/bin/phpunit --no-coverage --no-progress tests/database/[TV-Z]*Test.php tests/session`

`tests/exploit-poc/` в гейт не входит. Его фикстуры правит story 08, а прогоняет её собственная `## Verification`.

## Rejected alternative
Отдельная таблица-слот `character_exclusive_slots (character_id PK, char_task_id)`, которую занимает старт 🔒-задачи и освобождает её выход из `in_work`. Отвергнута по двум причинам. Ask 6 прямо запрещает новые таблицы (WipeManifest не меняется). И это второй источник правды: каждый выход из `in_work` (Worker-claim, отмена, пауза, прерывание, смерть) обязан освободить слот, а пропущенный путь навсегда запирает персонажа. Generated column считается из самой строки и рассинхронизироваться не может. Триггер `BEFORE INSERT`, заполняющий флаг из `tasks`, тоже отвергнут: логика, невидимая из PHP, и её пришлось бы повторять в каждом из 55 рукописных DDL тестов.

## Descoped

*(empty)*

## Plan deltas

**Approved:**
**Briefed:**
**Branch:**
**Checked:**
**Council:**
**Shipped:**
