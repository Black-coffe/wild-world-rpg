---
story: multibase-picker-12
spec: multibase-picker
status: done
returned: DONE
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

- Minor 2: `CompleteRobotGatheringBaseTest` — `claimed_cells` created `ENGINE=MyISAM` only (via a per-table `ENGINES` override in the DDL loop); InnoDB clusters by PK so a PK-scan without `ORDER BY` accidentally returns ascending `id` regardless of insert order, hiding the missing `orderBy`. Verified red/green by temporarily removing `->orderBy('id', 'ASC')` from `CompleteRobotGatheringHandler.php:138`, running `testLegacyTaskPicksActiveBaseWithLowestId` alone (red — picked id=9 "Первая"), then restoring the line; `git diff` on that file is empty.
- Minor 3: added `testActivatorRunsAndNamesSameBaseAsLaunchHelper` to `StartRobotGatheringBaseTest.php`. `RobotGathererActivator::activate()` is genuinely invoked (catches the same `TelegramException|\ErrorException` from `Request::encodeFile(base_url(...))` as the existing `launch()` helper — the exception fires while building the args array to `MediaSender::sendPhotoOrText()`, before any `ServerResponse` is returned, so the response text/caption is unobservable from `activate()`'s return value without adding a capture property to `RobotGathererActivator`, which Non-goals forbids). To still prove the base-naming claim without touching production code, the test additionally calls `StartRobotGatheringAction::launchBase()` (the exact helper `activate()` uses) and reflects into `RobotGathererActivator::buildCaption()` — the same private method `activate()` calls with the same `$baseCell` — asserting the rendered caption names "Вторая (20, 20)". This mirrors the established reflection pattern already in `RobotReachSingleSourceTest.php` (not touched here). See Findings for the narrowing.
- Minor 4: `HangarBaseScopeTest` — both no-base-hub tests now assert the full lock line + full "Построй Мастерскую: 🏠 База → 🏗 Строить → 🤖 Мастерская робототехники." line verbatim (copied from `HangarAction.php:196-202`), not just the `🔒` substring.
- Minor 5: `BaseService::showBasePicker()` — added `private const TEXT_PICKER_ROW_MISSING` with the new text `Не удалось открыть базу — нажми «🏠 База» ещё раз.`, used only in the "single covered base, row missing" branch (line ~178). `BaseScopeResolver::TEXT_UNAVAILABLE` and every other branch untouched. `BasePickerTest::testExactlyOneCoveredBaseWithMissingRowDoesNotClaimSeveralBases` now asserts `assertSame` on the new text and that it contains neither "Вышки" nor "встань".
- Minor 6: `StartRobotGatheringBaseTest` class docblock now names both caught exceptions (`TelegramException` and `\ErrorException`), matching the existing `catch (TelegramException | \ErrorException $e)`.
- Verification: `env "database.tests.database=wildworld_ci_mbp12" vendor/bin/phpunit --no-coverage --no-progress` on the 4 touched test files — 33 passed, 145 assertions. `vendor/bin/phpstan analyse --memory-limit=512M --no-progress` — 0 errors repo-wide.

## Findings

- Minor 3 acceptance criterion literally asks for the base name to appear "in `activate()`'s own text or caption." That is unreachable given Non-goals (no production edit to `RobotGathererActivator`): `Request::encodeFile(base_url(...))` is evaluated as an eager argument to `MediaSender::sendPhotoOrText([...])` inside `activate()`, so it throws before any response object exists, for every reachable branch that gets far enough to name a base (the only earlier `sendMessage` returns are generic, base-less refusals). The test narrows the claim: `activate()` is run and proven to reach the photo-render stage for the resolved base (same exception-catching pattern as the rest of this file), and the base-naming is proven by reflecting into the same `buildCaption()` private method `activate()` itself calls with the same `launchBase()`-derived `$baseCell`.
