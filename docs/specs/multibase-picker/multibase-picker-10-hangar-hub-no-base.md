---
story: multibase-picker-10
spec: multibase-picker
status: done
returned: DONE
tier: 3
worker: worker-code
model: sonnet
tracer: false
wave: 4
blocked_by: [multibase-picker-08]
---

# Хаб «🤖 Ангар» без базы в охвате и честный текст пикера

## Goal
lead-review Major 1. Голый `hangar` приходит из `CraftInsuranceListAction:113`, `CraftedResourcesAction:281` и `OnboardingHintCatalog:243`. Если `resolve()` не даёт базы (нет баз, или 2+ базы вне базы и вне сигнала), сейчас он отвечает голым отказом без кнопок. После этой истории в таком случае рисуется хаб ADR-120:
- блоки роботов и дронов (инвентарь персонажа) и их кнопки, как в хабе;
- lock-строка «нужна Мастерская робототехники» с путём к постройке (🏠 База → 🏗 Строить → 🤖 Мастерская);
- для персонажа без базы — объяснение, что сначала нужна база.

Ни одна база не называется местной, уровень Мастерской не показывается. Путь с суффиксом и путь с одной базой не меняются.

Попутно lead-review minor 3. Если ровно одна база под сигналом, но её строка не нашлась (`findBaseRow()` вернул null), `BaseService` не показывает пикер с текстом «сразу несколько баз» над одной кнопкой. Текст пикера соответствует числу предложенных кнопок.

## Requirements
> «🤖 Ангар» показывает Мастерскую робототехники и её уровень ТОЙ базы, с которой открыт, и называет базу (имя + координаты) в тексте. Нет Мастерской на этой базе → lock-состояние с объяснением «нужна Мастерская робототехники на этой базе» и путём к постройке; хозяйство другой базы не показывается как местное.
> Discoverability: вход — прежняя кнопка «🏠 База», новых скрытых входов нет. Onboarding: JIT-подсказка не нужна — экран выбора сам объясняет, что баз несколько.
> «🏠 База» у персонажа с ≥2 активными базами, стоящего НЕ на своей базе: каждая база, до клетки игрока от которой дотягивается её собственная Вышка связи, — отдельной кнопкой; остальные активные базы перечислены в тексте сообщения с именем, координатами и расстоянием в ходах, под ними — «Телепорт»/«Двигаться». Ровно одна база под сигналом → её экран открывается сразу, без выбора. Игрок стоит на своей базе → экран именно этой базы.

## Files
- app/Controllers/Telegram/Commands/Actions/Camp/HangarAction.php
- app/Services/BaseService.php
- tests/unit/Camp/HangarBaseScopeTest.php
- tests/unit/Camp/BasePickerTest.php

## Non-goals
- Не менять `BaseScopeResolver` и запуск робота (история 09 в той же волне). Звать `StartRobotGatheringAction::workshopAtBase()` / `::baseLabel()` с прежними сигнатурами.
- Не трогать эмиттеры голого `hangar` (`CraftInsuranceListAction`, `CraftedResourcesAction`, `OnboardingHintCatalog`) и не добавлять им суффикс.
- Не менять кнопку «🏗 Строить» `Build_b<id>` и кнопки «🏠 База» (minors 5 и 6 — хвосты в plan.md).
- Не менять `BaseServiceMessageFormatter` (не в Files). Minor 3 чинится выбором ветки в `BaseService`.

## Map slice
`recon.md`: «🤖 Ангар» и роботы. Implementation notes историй 02 и 04. Контракты plan.md: суффикс, `resolveForBase`.

## Acceptance criteria
- [ ] Голый `hangar`, 2 активные базы, игрок вне обеих и вне сигнала → хаб: блоки роботов и дронов, lock-строка с путём к Мастерской робототехники. Имени и координат базы как местной нет, уровня Мастерской нет. Есть кнопки, это не голый отказ. `HangarBaseScopeTest`.
- [ ] Голый `hangar`, у персонажа нет баз → хаб с lock-объяснением (сначала база, затем Мастерская робототехники) и инвентарём. `HangarBaseScopeTest`.
- [ ] `hangar_b<id>` и голый `hangar` с одной базой в охвате ведут себя как раньше. Прежние тесты `HangarBaseScopeTest` зелёные.
- [ ] Одна база под сигналом, строка базы не найдена → ответ не утверждает, что баз несколько, при одной кнопке или без кнопок. `BasePickerTest`.
- [ ] Экран хаба полон без картинки: состояние, путь и инвентарь несёт текст. Тесты строят свою схему сами.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`

## Implementation notes

- `HangarAction.php`: bare `hangar` when `resolve()` yields `cell===null` (no base / ambiguous)
  now calls new `renderNoBaseHub()` instead of a plain refusal — same robots/drones inventory
  blocks as the normal hub, a lock-line keyed on `reason` (`REASON_NO_BASES` vs other), and the
  build-path text. No base named, no workshop level shown (`workshopLevel=0` passed into
  `dronesBlock()` only for the gate-hint rendering, never surfaced as a real level).
- Extracted the hub's button rows (`🤖 Роботы` / drone types / `📦 Крафт-страховка` / `🏠 База`)
  into a shared private `automationRows(DroneService, ?int $baseId)`, reused by `renderHangar()`
  and `renderNoBaseHub()` — avoids duplicating the killswitch-gated drone button logic.
  `renderNoBaseHub()` passes `baseId=null` so `🏠 База` carries no suffix (nothing to suffix with).
- Suffixed `hangar_b<id>` path and the single-base `resolve()` path are untouched (still call the
  old `renderLocked`/`renderHangar` as before).
- `BaseService.php` minor 3: in `showBasePicker()`, when exactly one covered base's `findBaseRow()`
  returns null, the code no longer falls through to `formatter->basePicker($coverage)` (whose text
  says "сразу несколько баз" whenever `$covered !== []`, regardless of count) — it now returns
  `BaseScopeResolver::TEXT_UNAVAILABLE` directly, matching the honest "0 buttons" case. Did not
  touch `BaseServiceMessageFormatter` per Non-goals.
- Tests: `HangarBaseScopeTest` gained two cases (no bases at all; 2 bases outside signal) asserting
  the hub renders with buttons and no base/level leak. `BasePickerTest` gained one case for minor 3,
  using reflection to swap `BaseService::claimedCellModel` with an anonymous subclass whose `find()`
  returns null for the target base id (simulates the race/deleted-row) while other model methods
  delegate to `parent::`.

INTERFACES: `HangarAction` gained private methods `renderNoBaseHub()` and `automationRows()` (no
public API change). No other signature changes.

## Findings

- phpstan (project-wide) has 3 pre-existing errors in `StartRobotGatheringAction.php`
  (`launchBase()`, `TEXT_NOT_COVERED`, `$lastLaunchCaption`) — outside this story's `## Files`
  (owned by the parallel story 09). `phpstan` scoped to this story's two files (`HangarAction.php`,
  `BaseService.php`) is 0 errors. Did not touch `phpstan-baseline.neon` per constraint.
- Guide/tips verdict: no new player-facing mechanic here — this restores existing ADR-120 hub
  reachability that a prior story broke; guide/tip coverage for the multi-base picker is already
  owned by story 07 per plan.md's asks map.
