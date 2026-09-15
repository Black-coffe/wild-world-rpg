# Выбор базы: пикер «🏠 База», Ангар и робот у выбранной базы (plan)

**Tier:** 3 · **Spec slug:** `multibase-picker` · **Brief:** [brief.md](brief.md)
**Governed by:** ADR-095 (мульти-база), ADR-102 (выбор базы телепорта — прецедент кнопок), ADR-120 (хаб автоматизации «🤖 Ангар»), ADR-031 (Вышка связи), ADR-024 (баланс в `GameSettings`), ADR-020 (media-off), ADR-127/134 (guide/tips), черновик ADR-187 (`docs/specs/angela-second-base-bugs/adr-proposals.md`)
**Depends on:** `docs/specs/angela-second-base-bugs` (shipped v0.51.667) — `BaseScopeResolver`, `ClaimedCellModel::findAllActiveCells()`, текст отказа `ambiguous`, закреплённый `tests/unit/Camp/BuildingCardBaseScopeTest.php`

## Goal
У игрока с несколькими базами «🏠 База» вне базы всегда открывает первую активную — даже если он в шаге от второй и её собственная Вышка связи до него дотягивается. Причина двойная: покрытие считается по одной произвольной базе (`first()` без `orderBy`), а выбранной базы нигде нет. После работы покрытие считается по каждой активной базе (радиус — ключ `GameSettings`), «🏠 База» показывает кнопками базы под сигналом (одна — открывается сразу), остальные — текстом с координатами и расстоянием; номер выбранной базы едет в `callback_data` каждой кнопки её экрана, и обработчик заново проверяет доступность. На ту же базу завязаны карточки построек и апгрейд, «🤖 Ангар», «Развитие базы» и «Декор базы»; робот-промышленник запускается только с базы, где есть своя Мастерская, копает вокруг неё и называет её. Хранилища «выбранной базы» нет — база живёт в кнопке (Answers 1).

**Выбранный подход и отклонённый.** Принято: идентификатор базы суффиксом в `callback_data` + повторная проверка в обработчике; кнопки без суффикса идут по старому правилу `BaseScopeResolver`. Отклонено: «текущая выбранная база» в кэше/колонке персонажа — меньше правок в кнопках, но состояние расходится между сообщениями (старое сообщение базы-1 после выбора базы-2 правило бы базу-2), нужна `WipeManifest`-классификация, и владелец прямо сказал «хранилище не нужно». Цена принятого — правка каждой кнопки экрана базы и проверка лимита 64 байта тестом.

## Assumptions
- **База идентифицируется `claimed_cells.id`** (как в `TeleportUseValidator::listActiveBases()`), а постройки базы — по её `map_cell_id`. Новых таблиц и колонок нет; `WipeManifest` не трогается.
- **Робот запускается, стоя на базе** (как сегодня — `BaseCheckService::checkBaseStatus()`); «выбранная база» для запуска — клетка, на которой стоит игрок. Номер базы через цепочку callback'ов роботов (`AllRobotsHandler` / `ActivateRobotHandler`) не протягивается: удалённый запуск робота через Вышку в брифе не просили. Если владелец хочет удалённый запуск — это отдельная спека.
- **Легаси-путь `BaseScopeResolver::resolve()` и его тексты не меняются байт в байт**, `tests/unit/Camp/BuildingCardBaseScopeTest.php` не правится ни одной историей и остаётся зелёным.
- **Ask 6 (вторая половина) и ask 8 (tech-writing) — Queen / `drone-docs` после мерджа.** ADR-187 живёт вне репо (`C:\Projects\mmorpg-vault\decisions\`): публикуется из черновика с обновлением — раздел «Revisit when» закрыт этой спекой (выбор базы кнопками, база в `callback_data`, легаси-кнопки по старому правилу), правило маяков «Центр телепортации на любой базе» записано как намеренное. Ноты `mmorpg-vault/tech-writing/` для каждой тронутой сущности (`CommunicationTowerCoverageService`, `BaseScopeResolver`, `DetailedBaseInfoAction`, `BaseServiceMessageFormatter`, `HangarAction`, `StartRobotGatheringAction`, `RobotGathererActivator`, `CompleteRobotGatheringHandler`, `BaseDevelopmentAction`, `BaseCampDecorService`, карточки построек, `UpgradeBuildingAction`, `BuildingUpgradeValidator`, `GuideCatalog`) — `drone-docs`. `memory/map/bases.md` обновляет `librarian`.
- **Ask 6 (вторая половина) — проверяемо, до совета.** ADR-187 публикуется в `C:\Projects\mmorpg-vault\decisions\ADR-187-*.md` (`lead-architect` по черновику `angela-second-base-bugs/adr-proposals.md`) ДО `open-round`, чтобы совет судил готовый файл: в нём раздел «Revisit when» помечен закрытым этой спекой (выбор базы кнопками, база в `callback_data`, легаси-кнопки по старому правилу), и отдельной строкой — правило маяков «Центр телепортации на любой базе» как намеренное. Проверка ask 6 советом — `grep -l "Центр телепортации на любой базе" C:/Projects/mmorpg-vault/decisions/ADR-187-*.md`.
- **Ask 8 (Tier-3) — Queen на preprod-testbot**, не на проде. Тест-чар `id=491` (`telegram_user_id=25`) имеет одну базу: перед прогоном SQL'ом на testbot завести ему вторую активную базу с Вышкой связи, покрывающей клетку прогона, и БЕЗ Мастерской робототехники на одной из баз. Сценарий: выбор базы → карточка постройки второй базы → «🤖 Ангар» второй базы (lock без Мастерской / Мастерская именно её) → запуск робота называет базу; все экраны — с выключенными картинками.
- **Ответ Анжеле в треде Bugs-info после шипа — Queen**, одним сообщением по всем четырём репортам.
- **Сборка — `/vulyk-build multibase-picker --fallback`**: `cycle-clerk` Workflow-драйвера обрезает JSON (известная стена).
- **Имя ключа `GameSettings` и форма его сида** (`communication_tower.coverage_per_level`, дефолт `100`) — предложение плана; воркер 01 сверяет с конвенцией соседних ключей и сообщает фактическое имя в INTERFACES.
- **`## Files` историй 01, 02, 03, 06, 07 сверены Queen'ом до утверждения** (см. «Ответы на вопросы разведки» и дельту ниже).

## Ответы на вопросы разведки (Queen, grep, 2026-09-15)
1. 14 карточек — `Camp/Buildings/{Arsenal,BlastFurnace,CommunicationTower,DefensiveBuilding,Greenhouse,Gym,HandPump,Laboratory,LeanTo,RoboticsWorkshop,SolarStation,TeleportationCenter,Warehouse,Workshop}Handler.php`; «Поднять уровень» строится в каждой карточке (`'upgrade_building_' . $buildingId`, напр. `ArsenalHandler.php:136`); апгрейд — `Camp/Buildings/UpgradeBuildingAction.php` через префикс `upgrade_building_` в `CallbackPrefixDispatcher.php:60`. Что есть `{id}` — воркер 03 сверяет.
2. Маршруты `Base`, `construction`, `baseDevelopment`, `hangar`, `campDecor*` — точное совпадение в `app/Config/CallbackRoutes.php` (:341-348, :494-499); суффикс `_b<id>` их не найдёт без правки роутера. Роутер — `SystemCommands/CallbackqueryCommand.php` (:85, :143) + `app/Services/Telegram/CallbackRouter.php`; префиксы — `CallbackPrefixDispatcher`. Всё это — история 01.
3. «🤖 Ангар» и «🎨 Декор» — `BaseServiceMessageFormatter.php:214-216`; action декора — `Camp/Decor/BaseCampDecorAction.php`.
4. **Живой экран «🏠 База» — НЕ `DetailedBaseInfoAction`.** `Base` → `Camp/Buildings/ShowBaseInfoAction` → `app/Services/BaseService.php::showBaseInfo()` → `BaseServiceMessageFormatter`; reply-меню — `GenericmessageCommand.php:606` → тот же `showBaseInfo()`. `DetailedBaseInfoAction` висит на `construction` и строит кнопки построек `building_<id>_<name>` (:241). История 02 перенацелена (дельта ниже).
5. Ключи `GameSettings` сидируются миграциями `Seed*GameSettings.php`; последний префикс в дереве — `2026-12-06-110000`, новые: `2026-12-07-100000` (01) и `2026-12-07-110000` (07).
6. `RobotGathererActivator` вызывается из `ActivateRobotHandler` / `AllRobotsHandler` — история 05 сверяет разделение с `StartRobotGatheringAction` и место сообщения-итога.

## Stories

**Wave 1** — фундамент и независимые куски
- `multibase-picker-01-coverage-resolver-callback` — покрытие по каждой базе + ключ радиуса, `BaseScopeResolver::resolveForBase()`, кодек суффикса базы и маршрутизация суффиксных callback'ов. **tracer, model: opus.**
- `multibase-picker-05-robot-gathering-base` — робот: Мастерская на базе запуска, клетка базы в `task_settings`, итог вокруг неё, легаси-задания без ошибки. **model: opus.**
- `multibase-picker-07-guide-and-tip` — абзац в `GuideCatalog` `base` + seed-совет `общие`. model: sonnet.

**Wave 2** — все `blocked_by: [multibase-picker-01]`
- `multibase-picker-02-base-picker-screen` — пикер на «🏠 База», экран выбранной базы, суффикс базы во всех его кнопках, тест ≤64 байт. model: sonnet.
- `multibase-picker-03-cards-upgrade-base-id` — 14 карточек, `BuildingHandlerAction`, апгрейд принимают суффикс базы. model: sonnet.
- `multibase-picker-04-hangar-per-base` — Ангар: Мастерская и уровень той базы, имя+координаты, lock. model: sonnet.
- `multibase-picker-06-development-decor-base` — «Развитие базы» и «Декор базы» по выбранной базе. model: sonnet.

Карта asks → истории: 1 → 02; 2 → 01; 3 → 01 (проверка, маршрут), 02 (кнопки), 03, 04, 06 (разбор суффикса); 4 → 04; 5 → 05; 6 → 06 + ADR-187 (Assumptions); 7 → media-off критерием в 02/04/05, discoverability/onboarding в 02, tips/guide в 07; 8 → `## Integration gate` + Assumptions (tech-writing, Tier-3).

## Contracts
- **Идентификатор базы** — `claimed_cells.id` (int). Персонаж-владелец + `status='active'` проверяются при каждом использовании.
- **Суффикс базы в `callback_data`:** `<прежний callback>_b<baseId>`, например `building_12_Warehouse_b345`, `upgrade_building_12_b345`, `hangar_b345`. Кодек — `App\Services\Bases\BaseCallbackSuffix` (история 01):
  - `append(string $callbackData, int $baseId): string`
  - `split(string $callbackData): array{0: string, 1: ?int}` — `[данные без суффикса, baseId|null]`; суффикс только по `/_b(\d+)$/`.
  - `MAX_BYTES = 64`; `append()` бросает `\LengthException`, если результат длиннее 64 байт (`strlen`).
- **Маршрутизация (история 01):** callback с суффиксом доходит до того же обработчика, что и без него, и обработчик получает ПОЛНЫЙ `callback_data` (с суффиксом). Кнопка без суффикса маршрутизируется как сегодня.
- **Кнопка базы в пикере:** `Base_b<baseId>` → `ShowBaseInfoAction` → `BaseService::showBaseInfo()` с выбранной базой; с экрана базы — `construction_b<id>`, `hangar_b<id>`, `campDecor_b<id>`, `baseDevelopment_b<id>`; из `DetailedBaseInfoAction` — `building_<id>_<name>_b<id>` (история 02 строит, история 01 гарантирует маршрут всех этих форм).
- **Покрытие (история 01):** `CommunicationTowerCoverageService::coverageByBase(int $characterId, int $playerCell): list<array{base_id:int, cell:int, name:string, x:int, y:int, towerLevel:int, distance:int, maxCoverage:int, isCovered:bool}>` — все активные базы персонажа по `claimed_cells.id` ASC; `towerLevel=0`/`isCovered=false` у базы без Вышки; `maxCoverage = towerLevel × GameSettings('communication_tower.coverage_per_level')`. `checkCoverage(int $characterId)` сохраняет форму ответа и становится детерминированным.
- **Проверка выбранной базы (история 01):** `BaseScopeResolver::resolveForBase(int $characterId, int $currentCell, int $baseId): array{cell: ?int, base_id: ?int, reason: string, text: string}`; `reason ∈ {'on_base', 'tower', 'unavailable'}`. `unavailable` (не своя / не активна / игрок не на ней и не под её сигналом) → `cell=null`, текст един для всех дверей: `Эта база сейчас недоступна: встань на неё или подойди под сигнал её Вышки связи — и открой «🏠 База» снова.` `resolve()` не меняется.
- **Задание робота (история 05):** `character_tasks.task_settings` JSON получает ключ `base_cell` (int, номер клетки базы запуска) рядом с `crafted_item_id`. Отсутствие ключа = легаси-задание.
- **Имя базы в текстах** — `MarkdownSafe::name()` + `(x, y)` из `claimed_cells`/карты, как в `TeleportUseMessageFormatter::chooseBase()`.

## Integration gate
`vendor/bin/phpunit --no-coverage --no-progress` и `vendor/bin/phpstan analyse --memory-limit=512M --no-progress`, `git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`. Набор — на свежей пустой БД по рецепту `brief.md` («Как проверять»). Перед каждой волной — `bash scripts/wave-check.sh docs/specs/multibase-picker`.

## Descoped

*(empty)*

## Plan deltas

- **2026-09-15 · `## Verification` во всех историях — полный набор.** Как в `angela-second-base-bugs`: `close-story` сверяет команду байт в байт с ячейкой `## Commands`, поэтому каждая история проверяется `vendor/bin/phpunit --no-coverage --no-progress`; конкретные тест-файлы названы в `## Acceptance criteria`. Предупреждение `wave-check` `verify-gap` на этой строке — известное ложное срабатывание (бинарь, а не путь). `phpstan-baseline.neon` не трогает ни одна история; понадобится — воркер сообщает, Queen решает дельтой.

- **2026-09-15 · Пути `## Files` сверены до утверждения.** Триггер: разведка назвала живым экраном «🏠 База» `DetailedBaseInfoAction`, а `Base` роутится в `ShowBaseInfoAction` → `BaseService::showBaseInfo()`; `wave-check` нашёл глобы и несуществующие каталоги тестов. Решение: история 02 владеет `ShowBaseInfoAction`, `BaseService`, `BaseServiceMessageFormatter`, `DetailedBaseInfoAction`; история 01 — роутером `CallbackqueryCommand` + `CallbackRouter` + `CallbackPrefixDispatcher` вместо `CallbackRoutes.php`; 03 — 14 явных карточек и `Camp/Buildings/UpgradeBuildingAction.php`; 06 — `Camp/Decor/BaseCampDecorAction.php`; миграции — явные имена; тесты — в существующих `tests/unit/Camp`, `tests/unit/Telegram`, `tests/unit/Services/Onboarding`. `GenericmessageCommand.php` не трогается: путь без суффикса = пикер.
- **2026-09-15 · Источник цитат — строки `## Answers` и `## Asks` брифа.** Триггер: `trace-check.sh` сверяет цитаты только с `> `-строками брифа, а `## Asks` — нумерованный список (контракт C8, его читает `cycle.sh`). Решение, как в `angela-second-base-bugs`: цитаты историй приведены здесь дословно:
> `CommunicationTowerCoverageService` оценивает покрытие по КАЖДОЙ активной базе персонажа в детерминированном порядке (по `id`), а не по одной `first()` без `orderBy`; радиус покрытия на уровень вышки — ключ `GameSettings` с rationale/effect/above/below, soft/hard-границами и Reset-to-default (сейчас зашито `towerLevel * 100`), дефолт равен нынешнему поведению.
> «🏠 База» у персонажа с ≥2 активными базами, стоящего НЕ на своей базе: каждая база, до клетки игрока от которой дотягивается её собственная Вышка связи, — отдельной кнопкой; остальные активные базы перечислены в тексте сообщения с именем, координатами и расстоянием в ходах, под ними — «Телепорт»/«Двигаться». Ровно одна база под сигналом → её экран открывается сразу, без выбора. Игрок стоит на своей базе → экран именно этой базы.
> Кнопка базы в пикере и все кнопки, ведущие с экрана выбранной базы (карточки построек, «Поднять уровень», «🤖 Ангар», «Развитие базы», «Декор базы»), несут идентификатор базы в `callback_data` (≤64 байт, проверено тестом); обработчик заново проверяет, что база принадлежит персонажу, активна и доступна (игрок на ней или под её сигналом), иначе — честный отказ. Кнопка без идентификатора (из старых сообщений) работает по прежнему правилу `BaseScopeResolver`.
> «🤖 Ангар» показывает Мастерскую робототехники и её уровень ТОЙ базы, с которой открыт, и называет базу (имя + координаты) в тексте. Нет Мастерской на этой базе → lock-состояние с объяснением «нужна Мастерская робототехники на этой базе» и путём к постройке; хозяйство другой базы не показывается как местное.
> Робот-промышленник запускается только с базы, на которой есть своя Мастерская робототехники; клетка базы запуска сохраняется в задании (`character_tasks.task_settings`), и завершение сбора (`CompleteRobotGatheringHandler`) собирает вокруг именно неё. Экран запуска и сообщение-итог называют базу и координаты. Задание, запущенное до выкатки (без сохранённой базы), завершается без ошибки по прежнему правилу.
> «Развитие базы» показывает уровни построек выбранной базы, а не `MAX(level)` по всем базам; «Декор базы» правит выбранную базу. Правило маяков («Центр телепортации на любой базе») записано как намеренное в обновлении ADR-187, где закрыт пункт «Revisit when: владелец захочет выбор базы».
> Media-off: пикер, экран базы, Ангар, запуск и итог робота полны без картинки — имя базы, координаты, расстояние, уровень, состояние и инструкции несёт текст. Discoverability: вход — прежняя кнопка «🏠 База», новых скрытых входов нет. Onboarding: JIT-подсказка не нужна — экран выбора сам объясняет, что баз несколько. Tips: да — идемпотентная seed-миграция `*Seed<Что>Tip.php`, категория `общие`, тон Роби, без чисел баланса. `/guide`: да — абзац о нескольких базах и Вышке связи в разделе `base` `GuideCatalog`.
> База в самой кнопке: номер базы добавляется в callback кнопки постройки (как в выборе базы телепорта); кнопки из старых сообщений продолжают работать по старому правилу; хранилище не нужно.
> Работает у выбранной базы: запуск только с базы, где есть своя Мастерская робототехники; робот копает вокруг этой базы (база запоминается в задании, без новой колонки); экран запуска называет базу и координаты; роботы остаются общим инвентарём персонажа.
> Развитие и декор — да, маяки — в хвост: «Развитие базы» и «Декор базы» работают с той же базой, что и пикер; правило «Центр телепортации на любой базе» у маяков записывается как намеренное, не чинится.

- **2026-09-15 · Вышка покрывает только свою базу — проверено на проде.** Триггер: воркер 01 сообщил смену поведения — Вышка считается для базы, только если `character_buildings.map_cell_id` = клетке этой активной базы (раньше — любая Вышка персонажа). Прод, read-only SELECT: 12 Вышек, 12 — на активной базе владельца, `NULL`/чужая клетка — 0; регрессии покрытия у живых игроков нет. Решение: принять.
- **2026-09-15 · `phpstan-baseline.neon` — одна отдельная правка после волны 2.** Триггер: переписанный `CommunicationTowerCoverageService` оставил 8 записей `ignore.unmatched` в baseline; волна 2 может добавить свои. Решение: истории волны 2 baseline НЕ трогают (сообщают несовпадения в Findings); после волны 2 одна правка baseline одним воркером, до `open-round`. Отклонено: отдать baseline одной из историй волны 2 — одновременная правка общего файла (`feedback_never_two_workers_on_one_file`).

**Approved:** Andrei, 2026-09-15 (сборка через `/vulyk-build multibase-picker --fallback`)
**Briefed:** <...>
**Branch:** vulyk/multibase-picker
**Checked:** <scripts/human-check.sh>
**Council:** <scripts/cycle.sh judge>
**Shipped:** <...>
