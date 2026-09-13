---
story: angela-second-base-bugs-07
spec: angela-second-base-bugs
status: done
tier: 3
worker: worker-code
tracer: false
wave: 3
blocked_by: [angela-second-base-bugs-05]
---

# Отказ называет настоящую причину, а удалённое управление знает, о какой базе речь

## Goal
Все двери, резолвящие «текущую базу», перестают отвечать одним текстом на два разных состояния
и перестают ломать удалённое управление через Вышку связи. Игрок БЕЗ баз слышит «базы нет», а не
«встань на ту базу, с которой работаешь». Игрок с двумя базами, стоящий под сигналом вышки,
снова открывает карточки построек — той же базы, которую ему только что показал экран
`DetailedBaseInfoAction`, и эта база — живая, а не брошенная.

## Requirements
> Экран-карточка здания на базе показывает уровень и состояние постройки ТОЙ базы, в клетке которой стоит игрок. Проверяемо: при одном типе здания на двух базах карточка на каждой базе показывает уровень строки `character_buildings` с соответствующим `map_cell_id`, а не чужой.
> Все тронутые экраны полноценны при отключённых картинках: уровень постройки, расстояние в ходах и состояние несёт сам текст сообщения, а не изображение.

## Что чиним (разведка сделана, искать не надо)
1. **Враньё игроку.** `ClaimedCellModel::resolveTargetBaseCell()` возвращает `null` в ДВУХ
   состояниях: «баз ≥2, игрок не на базе» и «активных баз нет вообще». Четырнадцать карточек и
   валидатор апгрейда отвечают на оба одним текстом «Баз у тебя несколько…». Игроку без баз это ложь.
2. **Регресс волны 1.** `DetailedBaseInfoAction` при покрытии вышкой прямо обещает
   «Можно управлять сооружениями удалённо!» (строка ~218) и рисует кнопки построек
   (`callback_data` = `building_{id}_{nameEn}`, строки ~238-241). Клик уходит в карточку, где
   `resolveTargetBaseCell` при ≥2 активных базах и игроке ВНЕ базы отдаёт `null` → отказ
   «встань на базу». До волны 1 клик показывал карточку (пусть и чужой базы), теперь не
   показывает ничего. Игрока с одной базой это не касается.
3. **Заброшенная база в удалённом просмотре.** `DetailedBaseInfoAction.php:59-61` выбирает базу
   как `->where('character_id', …)->first()` — без `status` и без `orderBy`. Удалённо можно
   листать постройки ЗАБРОШЕННОЙ базы, пока живая стоит рядом.

## Как чинить (решение принято, менять не надо)
Завести один общий резолвер — `app/Services/Bases/BaseScopeResolver.php` — который возвращает
целевую клетку ИЛИ типизированную причину отказа, и звать его из всех дверей вместо
inline-блока с литеральным текстом. Правила резолва:

- игрок стоит на своей активной базе → эта клетка;
- иначе, если активных баз нет → отказ `no_bases`;
- иначе, если игрока покрывает сигнал Вышки связи
  (`CommunicationTowerCoverageService::checkCoverage($characterId)['isCovered']`) → ПЕРВАЯ
  активная база по `id` (`ClaimedCellModel::findAllActiveCells()` уже даёт ровно этот порядок) —
  та же, которую покажет `DetailedBaseInfoAction` после фикса п. 3;
- иначе → отказ `ambiguous` (или прежний отказ «не на базе», если дверь его уже имела).

Тексты отказов — контракт, он один на все двери (см. `## Contracts` плана):
- `ambiguous` — прежний, байт в байт: `Баз у тебя несколько. Встань на ту базу, с которой работаешь, — и открой экран снова.`
- `no_bases` — новый: `Базы у тебя сейчас нет. Разбей лагерь — и постройки появятся на этом экране.`

`DetailedBaseInfoAction` в п. 3 получает тот же порядок выбора: `status='active'` + `orderBy('id')`,
чтобы экран и карточка всегда говорили об одной базе.

**Отвергнутая альтернатива:** передавать базу хинтом в `callback_data`
(`building_{id}_{nameEn}_{cell}`) или предлагать выбор базы кнопками, как
`TeleportUseValidator` (ADR-102). Отвергнуто: смена формата `callback_data` ломает кнопки в уже
отправленных сообщениях и требует правки роутера, а выбор кнопками спрашивает игрока о том, что
экран, с которого он пришёл, уже знает. Оба варианта меняют общий текст отказа, закреплённый
тестом истории 05.

## Files
- app/Services/Bases/BaseScopeResolver.php
- app/Controllers/Telegram/Commands/Actions/Camp/DetailedBaseInfoAction.php
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/ArsenalHandler.php
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/BlastFurnaceHandler.php
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/CommunicationTowerHandler.php
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/DefensiveBuildingHandler.php
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/GreenhouseHandler.php
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/GymHandler.php
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/HandPumpHandler.php
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/LaboratoryHandler.php
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/LeanToHandler.php
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/RoboticsWorkshopHandler.php
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/SolarStationHandler.php
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/TeleportationCenterHandler.php
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/WarehouseHandler.php
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/WorkshopHandler.php
- app/Services/Player/BuildingUpgrade/BuildingUpgradeValidator.php
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/Upgrades/BaseBuildingUpgradeAction.php
- tests/unit/Services/Buildings/BaseScopeResolverTest.php
- tests/unit/Camp/BuildingCardBaseScopeTest.php
- tests/unit/Player/BuildingUpgradeBaseScopeTest.php

## Non-goals
- В `tests/unit/Camp/BuildingCardBaseScopeTest.php` разрешено менять ТОЛЬКО ожидание текста
  отказа для случая «активных баз нет». Схема, шим, структура файла — работа истории 05,
  переделывать её здесь нельзя.
- Не менять политику «на базе / не на базе» у апгрейда: `BuildingUpgradeValidator` продолжает
  пускать или не пускать ровно там же, где сейчас. Меняется только ТЕКСТ отказа при `null` и
  то, через какой резолвер эта база получена. Если окажется, что валидатор и раньше требовал
  физического присутствия на базе — оставить как есть и сказать об этом в `## Implementation notes`.
- Не менять формат `callback_data` ни на одном экране.
- Не трогать `ClaimedCellModel`: новых методов не добавлять, существующие не переписывать
  (модель — в `## Files` истории 06).
- Не чинить `CommunicationTowerCoverageService` — он сам базо-слеп и записан в
  `## Открытые хвосты`; здесь он используется как есть, только для ответа «покрывает / нет».
- Не трогать `BaseDevelopmentAction`, `BaseCampDecorService`, `BaseService::findFirstActiveCell`,
  `Buildings/Robots/*`, `TeleportBeacon*`, `RepairBuildingAction` — все в `## Открытые хвосты`.
- Не рефакторить четырнадцать хендлеров в общий базовый класс: в каждом меняется один и тот же
  короткий блок на вызов резолвера.
- Не трогать `phpstan-baseline.neon`: расхождения с baseline — строкой в отчёте Queen.

## Map slice
`memory/map/bases.md` (мульти-база, `claimed_cells`, `character_buildings`),
`memory/map/telegram.md` (action-handler'ы). Правила: `.claude/rules/telegram-ux.md`
(фото только через `MediaSender`, caption несёт весь смысл, 2–3 кнопки в ряд).

## Acceptance criteria
- [ ] Персонаж без активных баз, открывший любую из четырнадцати карточек и «Поднять уровень»,
      получает текст про отсутствие базы, а не про «встань на ту базу, с которой работаешь».
- [ ] Персонаж с ≥2 активными базами, стоящий вне базы БЕЗ покрытия вышки, получает прежний
      текст `ambiguous` — байт в байт как сейчас.
- [ ] Персонаж с ≥2 активными базами под покрытием вышки открывает карточку постройки: экран
      показывается, и показывает он ту же базу, что и `DetailedBaseInfoAction` (координаты
      совпадают), а не отказ.
- [ ] Заброшенная база не выбирается ни экраном `DetailedBaseInfoAction`, ни резолвером, даже
      если её `id` меньше, чем у активной.
- [ ] Персонаж с ОДНОЙ активной базой: все четырнадцать карточек, апгрейд и экран базы ведут
      себя ровно как до этой истории.
- [ ] Тексты отказов самодостаточны без картинок и markdown-safe (нет непарных `*` и `_`).
- [ ] Тест на резолвер проверяет ПОВЕДЕНИЕ на посеянных строках (своя схема, свой префикс
      таблиц), а не наличие подстроки в исходнике; покрыты все четыре исхода.
- [ ] `vendor/bin/phpunit --no-coverage --no-progress` и
      `vendor/bin/phpstan analyse --memory-limit=512M --no-progress` зелёные.
- [ ] Живой Tier-3 прогон в Telegram на preprod (ask 9) делает Queen, не воркер: PHPUnit
      удалённый клик по кнопке вышки не ловит.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`

## Предупреждения воркеру
- НИКОГДА `git stash` / `git checkout`; старый вариант файла — `git show HEAD:<path>`.
- Не запускать полный набор параллельно с другими агентами.
- Никаких деструктивных операций с общей локальной тест-БД (DROP / migrate).
- Смена сигнатуры публичного метода валидатора ломает тестовые подклассы и валит ВЕСЬ набор
  фаталом загрузки: меняешь сигнатуру — прогреби наследников и вызывающих.

## Implementation notes

- Новый `App\Services\Bases\BaseScopeResolver` (единственная точка резолва) — конструктор
  `(?ClaimedCellModel $claimedCellModel = null, ?CommunicationTowerCoverageService $towerService = null)`,
  метод `resolve(int $characterId, int $currentCell): array{cell:int|null, reason:string|null, text:string|null}`.
  Константы `REASON_NO_BASES`/`REASON_AMBIGUOUS`/`TEXT_NO_BASES`/`TEXT_AMBIGUOUS` — единый источник
  обоих текстов отказа.
- Четырнадцать хендлеров: механическая замена одного и того же трёхстрочного блока
  (`ClaimedCellModel::resolveTargetBaseCell()` + ручной null-текст) на
  `(new BaseScopeResolver())->resolve(...)` + `$scope['text']`. Правка сделана скриптом
  (regex по идентичному блоку) — все 14 файлов дали `matched 1` без отклонений в структуре.
- `BuildingUpgradeValidator`: конструктор НЕ менялся (сигнатура и порядок 6 позиционных
  параметров сохранены байт в байт — их использует чужой тест
  `BuildingUpgradeBaseScopeTest`, не в `## Files` этой истории). Добавлено приватное поле
  `?BaseScopeResolver $baseScopeResolver` и ленивый геттер
  `baseScopeResolver(): BaseScopeResolver`, который строит резолвер поверх УЖЕ
  инжектированного `$this->claimedCellModel` — тестовые двойники модели подхватываются
  автоматически, без изменения способа их передачи.
- `BaseBuildingUpgradeAction`: тот же паттерн, инлайново — `(new BaseScopeResolver())->resolve(...)`.
  Импорт `ClaimedCellModel` убран (стал неиспользуемым).
- `DetailedBaseInfoAction` (п.3): выборка «первой базы для дистанционного просмотра»
  заменена с `->where('character_id', …)->first()` (без status/orderBy) на
  `findAllActiveCells((int) $character['id'])[0] ?? null` — тот же порядок
  (`status='active'`, `orderBy('id')`), что использует `BaseScopeResolver`. Само
  `handleNoBase()` (текст «У тебя нет ещё разбитого лагеря…») не трогал — это другая дверь,
  не входящая в контракт «14 карточек + валидатор + generic-апгрейд», и у неё уже был
  честный текст.
- `tests/unit/Camp/BuildingCardBaseScopeTest.php`: добавлен ОДИН новый тестовый метод
  `testNoBasesAtAllGetsHonestRefusalNotAmbiguousText()` (персонаж без единой `seedBase()`),
  инфраструктура (префикс `bcbs_`, `fopen`-шим, `createOwnTable`) не тронута.
- `tests/unit/Services/Buildings/BaseScopeResolverTest.php` (новый) — своя изолированная
  таблица `bsr_claimed_cells` (паттерн `bubs_*`), двойник `CommunicationTowerCoverageService`
  (анонимный класс, переопределяющий только `checkCoverage()`). Шесть тестов: своя база /
  нет баз вообще / вышка → первая активная по `id` / нет вышки → ambiguous (текст байт в байт
  как раньше) / заброшенная база с МЕНЬШИМ `id` не выбирается / одна база — поведение как до
  истории.

**Открытый вопрос Queen: удалённый апгрейд под вышкой.** `BuildingUpgradeValidator::validate()`
шаг 1 (`PlayerStateService::isCharacterOnBase()`) требует, чтобы игрок физически стоял на
СВОЕЙ активной базе (`ClaimedCellModel::findActiveCell()`), и это условие проверяется ДО
резолва базы шагом 1b — покрытие Вышкой связи там вообще не участвует. Если шаг 1 не прошёл,
апгрейд отказывает текстом «Вы не на базе или база отсутствует» и до `BaseScopeResolver` не
доходит. Если шаг 1 прошёл (игрок физически на активной базе), `resolveTargetBaseCell()`
внутри `BaseScopeResolver` находит ту же базу тем же методом `findActiveCell()` немедленно —
ветка «вышка покрывает → берём первую базу» для апгрейда в проде НЕДОСТИЖИМА в принципе.
**Вывод: удалённый апгрейд построек под сигналом Вышки связи НЕ работает и не работал —
нужно физическое присутствие на базе.** Политика не менялась (Non-goals), только текст
отказа и способ его получения.

**Незапланированная находка при верификации (не фикс, для сведения).** Полный прогон
`vendor/bin/phpunit --no-coverage --no-progress` дважды подряд падает ровно в одном месте —
`BuildingUpgradeBaseScopeTest::testAmbiguousBaseReturnsAgreedMessageAndDoesNotApply`,
`DatabaseException: Table 'wildworld_tests.claimed_cells' doesn't exist`. Причина: в этом
тесте `PlayerStateService` — двойник, который ВСЕГДА возвращает `isCharacterOnBase()===true`
(искусственно, шаг 1 вне охвата истории 03), поэтому шаг 1b гипотетической «вышка покрывает»
ветки в этом тесте технически достижим — то, что в проде недостижимо (см. выше), здесь
доходит до `CommunicationTowerCoverageService::checkCoverage()`, а тот бьёт НЕпрефиксованную
реальную таблицу `claimed_cells` (сервис намеренно вне правки, Non-goals). Десятки других
тестов в репозитории (`OnBaseResolutionMultiBaseTest`, `DemolishBuildingTest`,
`PvpRewardOrchestratorTest` и др.) создают/дропают эту же безпрефиксную `claimed_cells` без
изоляции — известная утечка схемы между тестами (`feedback_shared_table_schema_leaks_between_tests`).
До этой истории `BuildingUpgradeValidator` эту таблицу вообще не трогал; теперь трогает —
только в этом одном искусственном тестовом сценарии (в проде путь недостижим, см. вывод
выше). Три целевых прогона (`BaseScopeResolverTest` отдельно; все три связанных файла вместе)
— зелёные, стабильно, дважды. Файл `tests/unit/Player/BuildingUpgradeBaseScopeTest.php` НЕ в
`## Files` этой истории — трогать нельзя.

**Догон (файл добавлен в `## Files`, границы истории расширены).**
`tests/unit/Player/BuildingUpgradeBaseScopeTest.php::testAmbiguousBaseReturnsAgreedMessageAndDoesNotApply`
чинён без единой правки `app/` — только тест. Проблема была не в `BuildingUpgradeValidator`
и не в `BaseScopeResolver` (оба логически верны, Non-goals не тронуты), а в том, что
`BaseScopeResolver::__construct()` без явного второго аргумента заводит РЕАЛЬНЫЙ
`CommunicationTowerCoverageService`, а тот сам создаёт РЕАЛЬНЫЙ `ClaimedCellModel` на
непрефиксованную `claimed_cells` — таблицу, которую в общей тест-БД держат/дропают десятки
других тестов без изоляции. `BuildingUpgradeValidator::baseScopeResolver()` — приватный
ленивый геттер без сеттера (сигнатуру конструктора валидатора трогать было нельзя — она
контракт этого же теста), поэтому тестовый двойник `CommunicationTowerCoverageService`
(анонимный класс, `checkCoverage()` → `['isCovered' => false]`) подставляется в приватное
поле `baseScopeResolver` через `ReflectionProperty` в helper-методе `validator()` — той же
префиксованной `bubs_claimed_cells`, что использует сам валидатор. Ветка «вышка покрывает»
для апгрейда и так недостижима в проде (см. вывод выше), так что `isCovered=false` в
двойнике ничего не меняет в проверяемом поведении — только убирает обращение к чужой
таблице. Проверено: `tests/unit/Player/BuildingUpgradeBaseScopeTest.php` зелёный в одиночку
(7 тестов, 24 assertions) и весь набор `vendor/bin/phpunit --no-coverage --no-progress`
зелёный (`Tests: 4114, Assertions: 32659, Skipped: 10`, 0 errors/failures).

## Findings
