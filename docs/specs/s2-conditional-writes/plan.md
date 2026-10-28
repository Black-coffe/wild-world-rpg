# S2 — пакет условной записи: F4, F8, F9, F10, F14, F16, F21–F24, опт, телепорт (plan)

**Tier:** 3 · **Spec slug:** `s2-conditional-writes` · **Brief:** [brief.md](brief.md)
**Governed by:** ADR-181 (условная запись, `ConditionalWriteService`, `NamedLock`, правило «выдача только после
подтверждённого списания»), ADR-151 (статы под `FOR UPDATE` через `CharacterStatsService`), ADR-167 (эксклюзивные
задачи), ADR-179/180 (контракт набора PoC), `docs/defects/exploit-fix-leaves-proceeds.md`, `CLAUDE.md` → ADMIN-TUNABLE
BALANCE, WIPE-COVERAGE, TIPS/GUIDE-COVERAGE.
**Depends on:** спека `docs/specs/exploit-fix/` (примитив `app/Services/Db/{ConditionalWriteService,NamedLock,WriteOutcome}.php`
и его первые применения; `## Contracts` там — источник API), хотфикс торговли 08.10 (`sellResource()` закрыт,
`bulkSellResources` уже под транзакцией и токеном), разведка `recon/scout-a.md`, `recon/scout-b.md`, `recon/symbol-map.md`.

## Goal

Перевести одиннадцать оставшихся точек read-then-write из `FIX-BACKLOG.md` на уже существующий примитив ADR-181, не
меняя ни баланса, ни схемы, ни игровых текстов: списание семени, золота ремонта/вклада, дозы препарата и заряда
телепорта становится условной записью с проверкой `affectedRows` и идёт ДО выдачи; кулдаун руин резервируется до лута;
бой с NPC и рулетка смерти сериализуются именованным локом; дельты событий и выдача роботов пишутся относительно, а не
со снимка; статус задачи переходит только из ожидаемого состояния; `bulkSellResources` читает исход `increaseGold`;
`activate()` отвергает не-транспорт. Каждая правка — отдельный коммит, независимый от соседних (ответ владельца 3),
один тег после совета. Доказательство — не гонка (PHPUnit её не воспроизводит), а **форма записи**: тест каждой story
подсовывает устаревший снимок (состояние БД изменилось после пройденного гейта-чтения) или занятый лок и требует
отказа без эффектов.

## Assumptions

- **Покрытие asks 6, 11, 12 — в story, не в этой сводке** (drone-coverage 28.10 видел только plan.md и отметил их
  частичными). Ask 6: оба теста — сумма двух дельт от одного снимка и «минус золота больше баланса → 0» — критерии
  `s2-conditional-writes-06` (`:59`). Ask 11: тест отказа `increaseGold` (ресурсы на месте, золото не изменено) —
  `BulkSellIncreaseGoldRefusalTest` в `-10` (`:54-61`). Ask 12: оба пути, рюкзак и портативный, откат при
  исключении/`false` перемещения и тест «исключение оставляет заряд» — `-11` (`:55-61`).
- Условный перевод спавна в «мёртв» внутри лока (story 02) — защита второго слоя сверх ask 2: лок держит гонку,
  условие держит повтор после снятия лока («NPC уже исчез.» по `affectedRows`, не по чтению).
- **Добытого через эти дыры не изымаем, потому что доказать добычу нечем** (карточка
  `docs/defects/exploit-fix-leaves-proceeds.md` требует решить это в плане, а не «потом»). Факты разведки: все PoC
  по этим пунктам зелёные последовательно, гонка ни по одному не воспроизведена, замера на проде не делалось, журнала
  денег/предметов (F27) не существует — ни подтвердить, ни опровергнуть эксплуатацию нельзя. Откатной story в плане
  нет сознательно: изъятие по догадке у живых игроков хуже, чем незакрытое окно, которого никто не видел. **Владелец
  подтверждает эту строку на approval-стопе явно.** Если он хочет замер — это отдельный `SELECT`-отчёт по `action_log`
  (study work, не story этой спеки).
- Решения владельца из `## Answers` зашиты в story и не обсуждаются воркерами: F8 — лок на спавн, `PvEService::attack`
  не трогается; F16 — все дельты, включая ресурсы и метеор, золото ≥ 0, здоровье/усталость/атрибуты с полом 0.01;
  F23 — лок на весь прогон, без миграции; выкат — один тег.
- **Миграций, новых таблиц, колонок и ключей `GameSettings` нет.** `WipeManifest` не трогается. Кулдаун руин берётся
  из того же источника, что читает `RuinLootService` сегодня.
- **Тексты отказов — существующие** («У тебя нет семян…», «NPC уже исчез.», «препарат закончился», «Задача не найдена
  или уже завершена.», «Не удалось выполнить оптовую продажу…»). Новых player-текстов story не вводят; где текста на
  пути отказа не было (F22 — что показывает вызывающий на `false`), story его не придумывает, а оставляет
  вызывающего как есть и отмечает в отчёте.
- **Параллелизм ограничен общим тест-стендом.** Волны по 3–4 story: все тесты спеки ходят в одну `wildworld_tests`,
  а фикстуры соседей пересобирают общие таблицы (`feedback_never_run_full_suite_in_parallel`,
  `feedback_local_suite_destroys_test_db_every_run`). Воркер гоняет только свой тест-файл; полный набор — только
  `lead-review` в круге; `DROP DATABASE` запрещён любому агенту.
- **Общие PoC-файлы `tests/exploit-poc/{DuplicationTest,GapAuditTest}.php` никто не правит**: их делят по три story,
  правка = коллизия волны. Если PoC от правки позеленел или покраснел — это факт для отчёта и гейта, не повод править тест.
- **`phpstan-baseline.neon` не правит ни один воркер.** Story, удаляющая строки с ранее заигноренной ошибкой
  (F14, F24, телепорт — вероятнее всего), получит «unmatched ignored error»; воркер перечисляет записи под
  `## Findings`, возвращает `WALL`, Королева сводит их коммитом `chore(baseline)` и закрывает story заново (урок
  exploit-fix 2026-09-02: `git add` целиком утянул чужой diff).
- Тестовые схемы строятся из настоящих миграций по факту отсутствия таблицы + сброс кэша метаданных — приём
  `exploit-fix-13` (`CharacterResourceConditionalWriteTest`, `RelocationConfirmOutcomeTest`); рукописный DDL только
  для `character_tasks.task_settings` (колонки нет в миграциях, `memory/map/tasks-worker.md`).
- Tier-3 смоук на preprod после мерджа делает Королева руками: посадка, препарат, рюкзачный телепорт, «Драться» с NPC,
  «Забрать всё» — happy path каждого тронутого экрана бота (`feedback_smoke_every_client_the_diff_touches`); тексты
  отказа старые, поэтому проверяется порядок записей, а не рендер нового текста.
- Вердикты ask 14 (guide — нет, совет — нет, GameSettings — нет, WipeManifest — нет) и ask 13 (`sellResource()` не
  трогается) несёт этот план; tech-writing — `drone-docs` после мерджа; `ROADMAP.md` (S2 из §2/§4, строка в §6) —
  Королева на шипе. Ask 15 — `## Integration gate` ниже.

## Open questions (recon, для `drone-scout` до волны 1)

1. F8: открывает ли `PvEService::attack()` (или что-то под ним) собственный `GET_LOCK`/транзакцию? Если да — правило
   «один лок на запрос» ломается и story 02 нужна другая форма. Проверить также `AutoPveHandler` → `attack()` не
   зовёт `NpcInteractionService`.
2. F23: берёт ли цепочка `handlePlayerDeathAndReward()` → `DeathService` именованный лок (поиск `GET_LOCK`,
   `NamedLock::` в `app/Services/Player/Death/**`)?
3. F24: есть ли вызывающие `CompleteRobotGatheringHandler::handle()` / `CompleteRobotExplorationHandler::handle()`
   кроме `Worker::processTasks()` (админка, spark, другой handler)? Если есть и он не клеймит статус — убирать запись
   нельзя, нужен `transitionIfCurrent`.
4. F22: что показывает вызывающий `activate()` при `false` (symbol-map: `tests textual 5`, `path mentions 3`), и
   есть ли тесты, которые активируют не-транспорт как фикстуру.
5. Телепорт: открывает ли `TeleportExecutor::teleport()` или `revealAround` свою транзакцию / `transComplete()`
   (вложенность в CI4 считается по `transDepth`, но явный `transCommit()` внутри сломает внешний откат).
6. F10: `AnomalyLootAction.php:49` — идёт ли аномалия через `RuinLootService::loot()` целиком или сама зовёт
   `touch()`; есть ли у `character_ruin_loot` второй писатель.

Воркер, упёршийся в любой из этих вопросов без ответа, возвращает `NEEDS_CONTEXT`, а не угадывает.

**Ответы (drone-scout + git grep Королевы, 28.10, до волны 1)** — в `## Map slice` story 02, 04, 07, 08, 09, 11. Q1: в `attack()` нет лока/транзакции, AutoPve не зовёт NPC-сервис — story 02 в силе. Q2: в цепочке смерти локов нет. Q3: обработчики роботов зовёт только Worker — убрать дубль. Q4: текст отказа общий; фикстуры `VehicleActivationServiceTest` — риск `WALL`. Q5: `adjust()` вкладывается счётчиком глубины, откат — на внешнем уровне; `revealAround` не в потоке телепорта. Q6: аномалии целиком через `loot()`, UNIQUE на `character_ruin_loot` есть.

## Stories

Все story независимы (`blocked_by: []`); волны — только предел параллельности тест-стенда.

**Волна 1**
- `s2-conditional-writes-01` — F4: семя списывается `decrementIfAtLeast` до создания задачи посадки (`PlantCropActionStart`).
- `s2-conditional-writes-02` — F8: `fight()`/`rob()` под `NamedLock` на спавн, consume условный, `PvEService::attack` нетронут.
- `s2-conditional-writes-03` — F9: золото ремонта робота, пакетного ремонта дронов и вклада во фракцию — `decrementIfAtLeast` до эффекта; прогресс проекта — `increment`.
- `s2-conditional-writes-04` — F10: резерв кулдауна руин/аномалий условной записью до лута (`RuinLootService` + модель лога).

**Волна 2**
- `s2-conditional-writes-05` — F14: доза препарата списывается условно до лечения/дебаффов/сытости (`UsePharmacyAction`).
- `s2-conditional-writes-06` — F16: `IntentApplier` — статы и золото через `adjust()`, ресурсы и метеор относительной записью.
- `s2-conditional-writes-07` — F22: `VehicleActivationService::activate()` отвергает предмет без ключа транспорта.
- `s2-conditional-writes-08` — F23: `DeathRouletteHandler` под `NamedLock` на весь прогон.

**Волна 3**
- `s2-conditional-writes-09` — F21 + F24: статус `character_tasks` — один условный писатель (`FinishTaskAction`
  `transitionIfCurrent`; обработчики роботов без дубля статуса, выдача сбора относительно).
- `s2-conditional-writes-10` — Опт: `bulkSellResources` читает `increaseGold`; регресс-тест двойного «Забрать всё».
- `s2-conditional-writes-11` — Телепорт: CAS заряда и перемещение в одной транзакции (`TeleportUseAction`).

## Contracts

Воркеры читают отсюда; API примитива не меняется ни одной story (изменение → `INTERFACES` в отчёте, дельта здесь).

- **`App\Services\Db\WriteOutcome`** — `enum { Applied, Refused, Missing }`. `Refused` = строка есть, предусловие
  не выполнено; `Missing` = строки нет (отказ + `error` в лог, это дефект, не игровая ситуация). `match` без `default`.
- **`App\Services\Db\ConditionalWriteService`** (конструктор `?BaseConnection $db = null`):
  - `decrementIfAtLeast(string $table, int $rowId, string $column, int $amount, bool $deleteWhenEmpty = false): WriteOutcome`
    — ключ только `id`.
  - `transitionIfCurrent(string $table, int $rowId, string $column, string $from, string $to): WriteOutcome` — ключ только `id`.
  - `increment(string $table, array $where, string $column, int $amount): WriteOutcome` — `Applied | Missing`.
  - `insertUnique(string $table, array $row): WriteOutcome` — `Refused` на дубле (`ON DUPLICATE KEY UPDATE col = col`),
    строка несёт все NOT NULL-колонки сама (`created_at`/`updated_at`).
  - Условного `DELETE` и CAS по произвольному предикату (`last_looted_at < NOW() - INTERVAL ? SECOND`, двухколоночный
    снимок) в примитиве **нет** — пишется инлайн `$db->query()` + `$db->affectedRows()` по образцу
    `TeleportItemConsumer` / `BaseStorageModel::withdrawRow()`. Не `Model::update()`: его `bool` не говорит, совпал ли `WHERE`.
  - Примитив **не открывает транзакций**; `Refused` не валит транзакцию вызывающего — откат делает вызывающий явно.
    Исход транзакции — по возврату, не по `transStatus()`. Транзакция завершается ровно один раз на каждом пути выхода,
    включая исключение (critical круга 3–5 exploit-fix).
- **`App\Services\Db\NamedLock::withLock(string $name, callable $fn): mixed`** — `GET_LOCK(?, 0)`, non-blocking,
  **`null` при занятом локе**, освобождение в `finally`. Не более одного лока на запрос, вложенность запрещена.
  Порядок: лок → `transBegin` → работа → commit/rollback → освобождение. Имена этой спеки: `npc_spawn_<spawnId>` (story 02),
  `death_roulette` (story 08, без id — прогон один на весь мир). Префиксацию/хэширование делает `NamedLock` так, как
  делает сегодня; второй схемы имён не заводить.
- **`CharacterStatsService::adjust($charId, $deltas, $floors)`** (по разведке; сигнатуру воркер сверяет по коду) —
  единственный путь записи статов, `FOR UPDATE` внутри (ADR-151). Золото по умолчанию зажимается ≥ 0; здоровье,
  усталость, атрибуты — пол 0.01 передаётся явно (сохранить сегодняшнее поведение `IntentApplier`).
- **Форма теста спеки (общая для всех story).** Не гонка, а снимок: тест проходит гейт-чтение продового кода, затем
  меняет строку в БД (0 семян / 0 доз / золото меньше цены / спавн уже мёртв / кулдаун уже свежий) и зовёт продовый
  метод; ожидание — отказ, строка не ниже нуля, эффекта/задачи/награды нет. Для локов — второе соединение
  `Config\Database::connect('tests', false)` держит `GET_LOCK` с тем же именем; ожидание — выход без эффектов.
  Один тест на форму, не покрытие. Фикстуры — миграционные (см. Assumptions). Файл называется по пункту
  (`tests/database/<Item>...Test.php`), общие PoC не трогаются.
- **Правило для ревью (ADR-181, дословно):** «Изменение, от которого зависит выдача, делается одним условным
  `UPDATE` с проверкой `affectedRows`; выдача идёт только после подтверждённого списания. Прочитанное значение — не
  основание для записи в ту же строку.»

**Отвергнутая альтернатива.** F8 через `transitionIfCurrent` на спавне **до** боя (как в исходной записи ADR-181
п. 3) — отвергнуто владельцем в пользу лока: бой может быть проигран, и тогда спавн, уже переведённый в «мёртв» до
боя, исчезает у проигравшего; возвращать его обратно — вторая запись со снимка. Инвариант F8 охватывает три записи
(бой в `PvEService::attack`, награда, спавн) и одним `WHERE` не выражается — это ступень 3 лестницы ADR-181 п. 4,
именованный лок. Цена: `PvEService::attack` внутри лока обязан не брать второй лок (вопрос 1).

## Integration gate

Перед каждой волной — `bash scripts/wave-check.sh docs/specs/s2-conditional-writes`.
После волны 3 `lead-review` гоняет (ask 15):

- `vendor/bin/phpunit --no-coverage --no-progress`
- `vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
- `git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`

Плюс гейт набора PoC, **пофайлово и никогда параллельно** (общий стенд `wildworld_tests`):
`for f in tests/exploit-poc/*.php; do vendor/bin/phpunit --no-coverage --no-progress "$f"; done`.
Ожидание точное: **тот же список красных, что до спеки, ни одного нового красного и ни одной ошибки окружения**.
Список «до» Королева снимает тем же пофайловым прогоном на `develop` до волны 1 и кладёт в `recon/poc-baseline.md`;
PoC, позеленевший от правки (вероятны `GapAuditTest::testSeedDeductionCannotDriveQuantityNegative`,
`DuplicationTest::testGoldNeverNegative…`), — допустимое отклонение в сторону «меньше красных», записывается в
`## Plan deltas`.

## Descoped

*(empty)*

## Plan deltas

**2026-10-28 · волна 2, story 06.** `tests/unit/Services/Events/IntentApplierTest.php` (3 из 4 красные) закреплял запись со снимка через подмену `CharacterModel::update()` — ту самую форму, которую чинит F16; добавлен в `## Files` и `## Verification` story 06. Устаревшие записи `phpstan-baseline.neon` по `IntentApplier.php` сняты Королевой (`aebbd7a3`, по правилу Assumptions). Story 05 закрыта после baseline без правок кода.

<!--
The lines below are the cycle's confirmation markers (docs/cycle.md); each placeholder is replaced by
the command or script that owns it, and scripts/ship-check.sh reads them all. No line of this comment may start with
a marker: the scripts read the first line that does.
-->
**Approved:** владелец, 2026-10-28 («да»; строка Assumptions «добытого не изымаем» подтверждена)
**Briefed:** <written by scripts/cycle.sh briefed>
**Branch:** vulyk/s2-conditional-writes
**Checked:** <written by scripts/human-check.sh>
**Council:** <written by scripts/cycle.sh judge/escalate>
**Shipped:** <written by scripts/ship-check.sh --record>
