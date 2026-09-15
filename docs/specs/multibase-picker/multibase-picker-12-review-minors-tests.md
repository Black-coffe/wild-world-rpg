---
story: multibase-picker-12
spec: multibase-picker
status: todo
returned:
tier: 3
worker: worker-code
model: sonnet
tracer: false
wave: 5
blocked_by: [multibase-picker-09, multibase-picker-10]
---

# Тесты, которые могут покраснеть, и честный отказ пикера (lead-review, раунд 2, minors 2-6)

## Goal
lead-review раунда 2 нашёл тесты, которые остаются зелёными и после регрессии, и одну неверную инструкцию игроку. После этой истории:
1. **Minor 2.** `CompleteRobotGatheringBaseTest::testLegacyTaskPicksActiveBaseWithLowestId` краснеет, если убрать `orderBy('id','ASC')` из легаси-ветки `CompleteRobotGatheringHandler`. Пример способа: тестовая `claimed_cells` на MyISAM, строки вставляются в обратном порядке id, так что скан без ORDER BY отдаёт не наименьший id первым. Если надёжного способа в собственной схеме теста нет, то docblock и имя теста не утверждают, что он доказывает детерминизм, а причина записана в Findings.
2. **Minor 3.** Тест запускает `RobotGathererActivator::activate()` и проверяет, что экран робота называет ту же базу, что `launchBase()`. Сценарий: вне базы, покрыта только база-2, на ней Мастерская.
3. **Minor 4.** Каждый тест хаба без базы в `HangarBaseScopeTest` проверяет lock-объяснение для своего `reason` (нет баз / несколько баз вне сигнала) и путь 🏠 База → 🏗 Строить → 🤖 Мастерская робототехники.
4. **Minor 5.** В ветке minor 3 `BaseService::showBasePicker()` (одна покрытая база, её строка не найдена) ответ не велит встать на базу или подойти под сигнал. Новый текст: `Не удалось открыть базу — нажми «🏠 База» ещё раз.`
5. **Minor 6.** Docblock `StartRobotGatheringBaseTest` называет оба исключения, которые ловит тест: `TelegramException` и `\ErrorException`.

## Requirements
> Задание, запущенное до выкатки (без сохранённой базы), завершается без ошибки по прежнему правилу.
> Экран запуска и сообщение-итог называют базу и координаты.
> Нет Мастерской на этой базе → lock-состояние с объяснением «нужна Мастерская робототехники на этой базе» и путём к постройке; хозяйство другой базы не показывается как местное.
> Ровно одна база под сигналом → её экран открывается сразу, без выбора.

## Files
- tests/unit/TaskHandlers/CompleteRobotGatheringBaseTest.php
- tests/unit/Camp/StartRobotGatheringBaseTest.php
- tests/unit/Camp/HangarBaseScopeTest.php
- app/Services/BaseService.php
- tests/unit/Camp/BasePickerTest.php

## Non-goals
- Не менять `CompleteRobotGatheringHandler`, `RobotGathererActivator`, `StartRobotGatheringAction`, `HangarAction`. Если `activate()` нельзя запустить в тесте без правки кода, это WALL: описать в Findings и не трогать код.
- Не менять `BaseServiceMessageFormatter`, `BaseScopeResolver::TEXT_UNAVAILABLE` и другие ветки `showBasePicker()`.
- Не добавлять кнопки в ответ ветки minor 5.

## Map slice
`memory/map/bases.md`: Gotchas. Implementation notes историй 09 и 10. Контракт plan.md «База запуска робота».

## Acceptance criteria
- [ ] Minor 2: без `orderBy` тест легаси-завершения красный, с ним зелёный. Проверено временным удалением строки, которое откачено до коммита и описано в Implementation notes. Либо действует запасной вариант из Goal 1 с причиной в Findings.
- [ ] Minor 3: `RobotGathererActivator::activate()` запущен, в его тексте или caption стоят имя и координаты базы-2. `StartRobotGatheringBaseTest`.
- [ ] Minor 4: оба теста хаба без базы проверяют lock-текст своего `reason` и путь к Мастерской целиком, а не подстрокой. `HangarBaseScopeTest`.
- [ ] Minor 5: в ветке «одна покрытая, строка не найдена» ответ равен новому тексту и не содержит слов про сигнал Вышки. `BasePickerTest`.
- [ ] Minor 6: docblock соответствует пойманным исключениям.
- [ ] Тесты строят свою схему сами.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`

## Implementation notes

## Findings
