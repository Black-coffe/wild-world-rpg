---
story: multibase-picker-11
spec: multibase-picker
status: done
returned: DONE
tier: 3
worker: worker-code
model: opus
tracer: false
wave: 5
blocked_by: [multibase-picker-09, multibase-picker-10]
---

# Голый `construction` показывает и штампует ту же базу, что `resolve()`; «Развитие базы» называет базу

## Goal
Совет, раунд 2, ask 3 RED. Голый `construction` в `DetailedBaseInfoAction` (~:91-114) берёт `findAllActiveCells()[0]` (активная база с наименьшим id) и проверяет только `checkCoverage()`. Если игрока покрывает только Вышка базы-2, экран показывает постройки базы-1 под числами Вышки базы-2 и штампует id базы-1 в кнопки. `resolveForBase()` потом эти кнопки отвергает. После этой истории:
1. **Голый `construction`**: база экрана берётся из `BaseScopeResolver::resolve()` (`cell`, `base_id`). Постройки, шапка покрытия (уровень Вышки, `distance`/`maxCoverage` — из строки `coverageByBase()` с тем же `base_id`) и суффикс `_b<id>` на кнопках относятся к этой одной базе. Путь с суффиксом `construction_b<id>` не меняется.
2. **Отказы голого пути не меняются.** Если игрок не покрыт, остаётся прежний отказ экрана, байт в байт. Если `checkCoverage()` говорит «покрыто», а `resolve()` вернул `cell=null`, выводится текст `resolve()` без изменений. Новых текстов нет.
3. **`BaseDevelopmentAction`**: кнопка «🏗 К базе» (~:110, :123) — `construction_b<id>`, если экран открыт с суффиксом. Без суффикса остаётся голый `construction`. Экран «Развитие базы» называет показанную базу (имя + координаты) через `StartRobotGatheringAction::baseLabel()` всякий раз, когда база определена (opus UNASKED 4).

## Requirements
> Кнопка без идентификатора (из старых сообщений) работает по прежнему правилу `BaseScopeResolver`.
> обработчик заново проверяет, что база принадлежит персонажу, активна и доступна (игрок на ней или под её сигналом), иначе — честный отказ.
> «Развитие базы» показывает уровни построек выбранной базы, а не `MAX(level)` по всем базам; «Декор базы» правит выбранную базу.

## Files
- app/Controllers/Telegram/Commands/Actions/Camp/DetailedBaseInfoAction.php
- app/Controllers/Telegram/Commands/Actions/Camp/BaseDevelopmentAction.php
- tests/unit/Camp/DetailedBaseInfoBareConstructionTest.php
- tests/unit/Camp/BaseDevelopmentBaseScopeTest.php

## Non-goals
- Не трогать `BaseScopeResolver`, `CommunicationTowerCoverageService`, `StartRobotGatheringAction` (только вызов `baseLabel()` с прежней сигнатурой).
- Не добавлять суффикс кнопкам «🏠 База» на карточках построек, `Build`, «📦 Склад», «🔨 Снести», `DeleteBase`, «📡 Маяки». Это открытые хвосты в plan.md.
- Не менять тексты отказов, `BuildingCardBaseScopeTest`, `BasePickerTest`, `BaseService`.
- Не менять запрос уровней в `BaseDevelopmentAction` (история 06).

## Map slice
`memory/map/bases.md`: Entry points (`BaseScopeResolver`). Контракты plan.md: суффикс, `coverageByBase`, `resolve()`/`resolveForBase`. Implementation notes историй 02, 06, 09.

## Acceptance criteria
- [ ] Две активные базы, игрок вне обеих, покрывает только Вышка базы-2 → голый `construction` показывает постройки базы-2. Шапка покрытия несёт числа базы-2. Каждая кнопка постройки и «Развитие базы» кончается на `_b<id базы-2>`. `DetailedBaseInfoBareConstructionTest`.
- [ ] Покрывают обе → база-1 (как `resolve()`), кнопки с `_b<id базы-1>`. Не покрывает ни одна → прежний текст отказа байт в байт (сверено с кодом до правки), кнопок построек нет. `DetailedBaseInfoBareConstructionTest`.
- [ ] Для каждого сценария выше `id` на кнопках равен `base_id`, который возвращает `resolve()`, и `resolveForBase()` с этим `id` не отвечает `unavailable`.
- [ ] «Развитие базы» с `_b<id>` → «🏗 К базе» = `construction_b<id>`, в тексте есть имя и координаты базы. Без суффикса → голый `construction`. `BaseDevelopmentBaseScopeTest`, прежние кейсы зелёные.
- [ ] Экраны полны без картинки: база, числа и состояние несёт текст. Тесты строят свою схему сами.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`

## Implementation notes
- `DetailedBaseInfoAction`: голый путь вне базы при `checkCoverage()` covered зовёт `resolve()`; `cell=null` → текст `resolve()` как есть. База экрана — строка `findAllActiveCells()` с `map_cell_id == resolve()['cell']`, шапка — `coverageResultForBase()` (строка `coverageByBase()` того же id), суффикс — её id. Ветки «на базе», «баз нет», «не покрыт» (`handleNotOnBasePhysically`) не тронуты.
- INTERFACES: `resolve()` не возвращает `base_id` (только `cell`), а менять `BaseScopeResolver` запрещено non-goal → id выводится из `cell` по активным базам персонажа (1:1). Недостижимая ветка «клетка не нашлась» отвечает существующим `TEXT_AMBIGUOUS`, нового текста нет.
- `BaseDevelopmentAction`: «🏗 К базе» = `construction_b<id>` при суффиксе, в т.ч. в `refusal()` (как велит story, :123); без суффикса голый `construction`. Шапка «🏠 База: Имя (x, y)» через `baseLabel($charId, $cell)` на обоих путях, где база определена. Запрос уровней не тронут.
- `BaseDevelopmentBaseScopeTest`: схема получила `map` и `claimed_cells.camp_name` (их читает `baseLabel()`), +2 кейса. Новый `DetailedBaseInfoBareConstructionTest` — реальные сервисы, своя схема `dbbc_`, `fopen`-шим из `BuildingCardBaseScopeTest`.
- Проверено подменой: с `DetailedBaseInfoAction` из HEAD кейс «только база-2» красный, кейс отказа зелёный на обеих версиях (текст байт в байт тот же).

## Findings
