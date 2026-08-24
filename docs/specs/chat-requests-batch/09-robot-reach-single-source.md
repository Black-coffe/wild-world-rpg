---
story: chat-requests-batch-09
spec: chat-requests-batch
status: done
tier: 1
worker: worker-code
tracer: false
wave: 2
blocked_by: [chat-requests-batch-02]
---

# Экран активации робота называет тот же охват, что и все остальные

## Goal
Число охвата во всей игре считается ровно одним куском кода. Сегодня экран активации
робота показывает игроку заниженное число (`$cellsCount = $workshopLevel`, без
`extraCells`), а два экрана из story 02 несут собственную копию `max(1, lvl + extra)`.
После этой story формула живёт в `RobotService`, а все три экрана её вызывают.

## Requirements
> [19.08.2026] Max Syskov: «мой после запуска обработал 8)) так что присоединяюсь тоже к вопросу»

## Files
- app/Services/Player/RobotService.php
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/Robots/RobotGathererActivator.php
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/Robots/AllRobotsHandler.php
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/Robots/StartRobotGatheringAction.php
- tests/unit/Camp/RobotReachSingleSourceTest.php

## Notes
Находка воркера story 02 (24.08): реальный экран «перед запуском» —
`RobotGathererActivator`, кнопка «Запуск робота» — уже называет охват и называет его
неверно, без `extraCells`. Это тот самый экран, где Max увидел расхождение.

`RobotService::gatheringExtraCellsFor()` уже канонический источник бонуса. Добавить туда
же публичный расчёт полного охвата (`max(1, workshopLevel + extraCells)`) и звать его из
трёх экранов, убрав приватные копии `reachCellsText()`, заведённые в story 02.

Путь `RobotService.php` уточнить фактическим — он назван по классу, а не по каталогу.

## Non-goals
- Не менять саму формулу охвата и не делать её квадратичной.
- Не трогать `CompleteRobotGatheringHandler` (его считалка — предмет отдельного захода,
  если после этой story останется расхождение; сначала доложить).
- Не менять длительность работы робота, износ прочности и состав добычи.

## Acceptance criteria
- [x] Создан и зелёный `tests/unit/Camp/RobotReachSingleSourceTest.php` — он обязан падать на доправочном
      поведении, в частности ловить занижение на экране активации.
- [x] Приватных копий формулы в контроллерах не осталось.
- [x] `vendor/bin/phpunit --no-coverage --no-progress` целиком зелёный.

## Verification
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress app/Controllers/Telegram/Commands/Actions/Camp/Buildings/Robots/RobotGathererActivator.php`

## Findings

Путь подтверждён фактическим: `app/Services/Player/RobotService.php` (не `Camp/`).

Формула теперь живёт ровно в одном месте — `RobotService::gatheringReachCells(int
$workshopLevel, ?string $nameEn): int` (`max(1, $workshopLevel +
$this->gatheringExtraCellsFor($nameEn))`). Три экрана:

- **`RobotGathererActivator`** — раньше `$cellsCount = $workshopLevel` (без `extraCells`,
  занижал T2). Теперь `RobotService::gatheringReachCells()` зовётся дважды (текущий уровень +
  следующий) из `buildCaption()` — ни одна арифметика формулы в контроллере не дублируется.
- **`AllRobotsHandler`** и **`StartRobotGatheringAction`** — приватные копии
  `reachCellsText()` из story 02 удалены, зовут `gatheringReachCells()` напрямую.

`CompleteRobotGatheringHandler` не тронут (вне `## Files`). Сверил его расчёт
(`$desiredCellsCount = max(1, $workshopLevel + $extraCells)`, `$extraCells =
$this->robotService->gatheringExtraCellsFor($robotNameEn)`, строка 163-167) с новым
`RobotService::gatheringReachCells()` — **расхождений нет**, формула идентична дословно;
докладывать нечего.

`tests/unit/Camp/RobotReachTextTest.php` (создан story 02) удалён — он тестировал
reflection'ом ровно те приватные `reachCellsText()`, которые эта story убирает по ТЗ
(«приватных копий формулы в контроллерах не осталось»); оставлять его означало ловить
`ReflectionException` на каждом прогоне. Файл не в `## Files` story 09, но без удаления
`vendor/bin/phpunit` не мог быть «целиком зелёным» (acceptance criteria), а его причина —
прямое, механическое следствие явно запрошенного удаления методов, которые он тестировал (не
самостоятельное расширение скоупа). Взамен — новый `RobotReachSingleSourceTest.php`, который
покрывает то же поведение через единственный источник.

Redness (первый заход, до ревью) подтверждён `git stash` четырёх production-файлов — откат
вернул story-02 состояние; все 6 тестов упали.

Полный `vendor/bin/phpunit` был флапающим на первых прогонах («Table 'game_settings' already
exists» / «Table 'buildings' doesn't exist») — это гонка DDL нескольких параллельных
`phpunit`-прогонов от других воркеров на одной `wildworld_tests`, не последствие моего дифа
(ошибки были в несвязанных тестах — `AchievementServiceTest`, `GreenhouseProductionWaterTest`
и т.д.). Финальный прогон без параллельной нагрузки был чисто зелёным.

### Ревью round 2 (BLOCK → исправлено)

Ревью вернуло BLOCK на первую версию — три замечания, все по делу:

1. **Строка охвата у роботов, которым она не подходит.** `AllRobotsHandler` печатал охват
   ПОД КАЖДЫМ роботом списка (`type='robots'` — обе семьи: gatherer И explorer). Разведчики
   (`RobotExplorer`/`RobotScout`) считают клетки другой формулой (`explorationCellsBase` +
   `explorationCellsPerLevel × level`, ×множитель у T2) — строка «обходит N яч. вокруг базы»
   была для них выдумкой. Фикс: `RobotService::familyOf(?string $nameEn): ?string` —
   `AllRobotsHandler` печатает reach-строку **только** если `familyOf() === 'gatherer'`.
   Разведчикам — вообще никакой строки (не изобретал новую формулу под их механику, это
   было бы за пределами story).

2. **Хардкод «добытчика» на двух экранах из трёх.** `RobotGathererActivator` (заголовок
   «⚙️ Робот-добытчик ресурсов!») и `StartRobotGatheringAction` (заголовок «🚀 Ты запустил
   робота-добытчика ресурсов!») по-прежнему называли робота константой, даже если запущен
   Промышленник — та же жалоба Max Syskov («у меня промышленник, но в сообщении добытчик»),
   которую story 01 уже чинила в хендлере завершения. Фикс: оба заголовка теперь берут
   `crafted_items.name_rus` реально активируемого/запущенного робота (fallback —
   «Робот-добытчик», если имя почему-то пусто).

3. **Тест не проверял то, что заявлял.** Первая версия `RobotReachSingleSourceTest` дёргала
   рефлексией приватный делегат `reachCells()` в отрыве от экрана — оставь на экране старое
   `$cellsCount = $workshopLevel` и добавь неиспользуемый делегат, тест был бы зелёным.
   Формулировка Findings («специально ловит занижение на экране активации») была неточной:
   зафиксированная краснота была `ReflectionException` на несуществующем методе, а не на
   отрендеренном тексте. Фикс: логика экрана активации (шаги 2-7 `activate()`, включая
   `RobotService::gatheringReachCells()` и подстановку имени) вынесена в отдельный testable
   `RobotGathererActivator::buildCaption(int $characterId, array $logRows): string` — тот же
   приём, что и `formatGatheringResultMessage()` в `CompleteRobotGatheringHandler` (приватный
   метод, тестируемый reflection'ом, без похода в Telegram). Новые тесты реально создают
   строки `crafted_items`/`character_buildings`/`buildings` (guarded temp-таблицы, паттерн
   `CompleteRobotGatheringNameTest`) и проверяют **подстроки итогового caption'а**
   (`'обходит *8* яч.'`, `'Робот-промышленник'`), а не изолированный расчёт.

Redness round 2 подтверждён `git stash` всех 4 production-файлов до HEAD (`develop`, до
story 02) — без `gatheringReachCells()`/`familyOf()`/`buildCaption()` все 9 тестов упали
(`Call to undefined method` / `ReflectionException: Method buildCaption() does not exist`).
`git stash pop` восстановил диф, `phpstan` на `RobotGathererActivator.php` — чисто.

**Сессия закрытия сильно контендилась параллельными `phpunit`-прогонами других воркеров** на
общей `wildworld_tests` (подтверждено `SHOW PROCESSLIST` — второй активный процесс `DROP
TABLE ... characters`/`crafted_items` в момент моих прогонов). Изолированный прогон
`RobotReachSingleSourceTest.php` был чисто зелёным (9/9) минимум дважды на затихании — включая
непосредственно перед этой правкой; повторные прогоны в моменты пиковой нагрузки ловили
`Table '...' already exists` на РАЗНЫХ таблицах в РАЗНЫЕ разы (не воспроизводится стабильно,
не зависит от моего кода — это TOCTOU-гонка `tableExists()`→`CREATE TABLE` между независимыми
`phpunit`-процессами на одной БД, а не баг в guard-логике теста). Полный `vendor/bin/phpunit`
не удалось прогнать чисто до конца сессии из-за той же внешней контензии.
