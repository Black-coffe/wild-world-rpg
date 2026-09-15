---
story: multibase-picker-02
spec: multibase-picker
status: done
returned: DONE
tier: 3
worker: worker-code
model: sonnet
tracer: false
wave: 2
blocked_by: [multibase-picker-01]
---

# Пикер баз на «🏠 База» и экран выбранной базы

## Goal
«🏠 База» у персонажа с ≥2 активными базами вне базы: базы под сигналом собственной Вышки — кнопками `🏠 {имя} ({x},{y})` (`Base_b<id>`), остальные — строками текста с именем, координатами и расстоянием в ходах, под ними «Телепорт»/«Двигаться». Ровно одна под сигналом → её экран сразу. На своей базе → экран этой базы. Callback с суффиксом → `resolveForBase()`; `unavailable` → честный отказ. Все кнопки экрана выбранной базы несут суффикс её id, и дальше по цепочке тоже.

Живой путь (сверено Queen'ом 2026-09-15, разведка ошиблась): callback `Base` → `Camp/Buildings/ShowBaseInfoAction` → `App\Services\BaseService::showBaseInfo()` → `BaseServiceMessageFormatter` (кнопки `hangar` :214, `campDecor` :216, «Развитие базы», стройка `construction`). Текст reply-меню «🏠 База» — `GenericmessageCommand.php:606` → тот же `showBaseInfo($chatId, $character)` без базы (файл не трогать, путь без суффикса = пикер). `construction` → `Camp/DetailedBaseInfoAction` — детальный экран, единственное место, где строятся кнопки построек `building_<id>_<name>` (:241). Цепочка суффикса: `Base_b<id>` → экран базы → `construction_b<id>`, `hangar_b<id>`, `campDecor_b<id>`, `baseDevelopment_b<id>` → в `DetailedBaseInfoAction` `building_<id>_<name>_b<id>`.

## Requirements
> Отхожу от второй базы на один шаг, жму «база», вижу интерфейс только первой базы, интерфейса второй базы, на которой тоже есть вышка связи и от которой я в одном шаге - не вижу!
> КМК на карте нажатие на кнопку «база» должно выводить кнопки баз, вышки связи которых дотягиваются до перса, а уже по нажатию на них можно проваливаться в интерфейс управления конкретной базой
> «🏠 База» у персонажа с ≥2 активными базами, стоящего НЕ на своей базе: каждая база, до клетки игрока от которой дотягивается её собственная Вышка связи, — отдельной кнопкой; остальные активные базы перечислены в тексте сообщения с именем, координатами и расстоянием в ходах, под ними — «Телепорт»/«Двигаться». Ровно одна база под сигналом → её экран открывается сразу, без выбора. Игрок стоит на своей базе → экран именно этой базы.
> Кнопка базы в пикере и все кнопки, ведущие с экрана выбранной базы (карточки построек, «Поднять уровень», «🤖 Ангар», «Развитие базы», «Декор базы»), несут идентификатор базы в `callback_data` (≤64 байт, проверено тестом)
> Discoverability: вход — прежняя кнопка «🏠 База», новых скрытых входов нет. Onboarding: JIT-подсказка не нужна — экран выбора сам объясняет, что баз несколько.

## Files
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/ShowBaseInfoAction.php
- app/Services/BaseService.php
- app/Services/Bases/BaseServiceMessageFormatter.php
- app/Controllers/Telegram/Commands/Actions/Camp/DetailedBaseInfoAction.php
- tests/unit/Camp/BasePickerTest.php

## Non-goals
- Не менять поведение персонажа с одной базой и путь «на своей базе» (кроме суффикса в кнопках).
- Не трогать обработчики карточек, Ангара, развития, декора (истории 03, 04, 06) и маршрутизацию (история 01).
- Не добавлять новых входов/команд; не менять правило маяков на экране «📡 Маяки».
- «Поднять уровень» живёт на карточке — его суффикс делает история 03, не эта.

## Map slice
`memory/map/bases.md` — Entry points, «Экран «📡 Маяки» — две двери». `recon.md` — «🏠 База» и Вышка связи; прецедент телепорта (`TeleportUseMessageFormatter::chooseBase()`, `ButtonPacker::pack()`, `MarkdownSafe::name()`). Контракты plan.md: суффикс, `coverageByBase`, `resolveForBase`.

## Acceptance criteria
- [ ] Две базы под сигналом → две кнопки базы, в тексте — объяснение, что баз несколько; ряды через `ButtonPacker`. `BasePickerTest`.
- [ ] Одна под сигналом, одна вне → экран покрытой открывается сразу, без выбора. Ни одной → все базы текстом (имя, координаты, расстояние в ходах) + «Телепорт»/«Двигаться». `BasePickerTest`.
- [ ] Игрок на базе-2 → экран базы-2; постройки из `character_buildings` с её `map_cell_id`. `BasePickerTest`.
- [ ] Callback экрана базы с `_b<id>` чужой/неактивной/недоступной базы → текст `unavailable` из plan.md; с доступной — экран именно её.
- [ ] Каждая кнопка экрана выбранной базы (карточки, «🤖 Ангар», «Развитие базы», «Декор базы») кончается на `_b<id>`; тест проверяет `strlen(callback_data) ≤ 64` на самом длинном английском имени постройки и id базы в 10 знаков.
- [ ] Пикер и экран базы полны без картинки (имя, координаты, расстояние, уровень Вышки, инструкции — в тексте); имена через `MarkdownSafe::name()`.
- [ ] Тест строит свою схему сам.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`

## Implementation notes

- `ShowBaseInfoAction`/`DetailedBaseInfoAction` split `BaseCallbackSuffix` on their own `callback_data`; router (story 01) does not strip it.
- `BaseService::showBaseInfo()` gained `?int $baseId = null` (4th param, backward-compatible with the 2 unmodified call sites `GenericmessageCommand.php`/`BotMenuService.php`): non-null → `showBaseById()` (`resolveForBase()`, honest `unavailable` text, else that base's screen); null → own base / single-base legacy path unchanged / `showBasePicker()` for ≥2 active bases off-base.
- `showBasePicker()`: covered count===1 → opens that base directly (no picker shown); else `BaseServiceMessageFormatter::basePicker()` (text-only, no photo) — covered bases as `Base_b<id>` buttons (`ButtonPacker::pack()`), rest as text lines with name/coords/distance ("N ходов"), trailing Телепорт/Двигаться row.
- `baseBuildings()` now takes `int $baseId` (last param) and suffixes `construction`/`hangar`/`campDecor`; `DetailedBaseInfoAction::showBuildings()` suffixes `building_<id>_<name>` and `baseDevelopment`. `BaseCallbackSuffix::append()` used everywhere (throws >64 bytes — none of our concatenations get near it, verified by test with a 10-digit base id + longest building name `TeleportationCenter`).
- phpstan: added `characterIdOf()`/`currentCellOf()`/`findBaseRow()`/`toStringKeyed()` private helpers in `BaseService` specifically to avoid `(int) $mixed` casts (`feedback_phpstan_no_mixed_to_int_cast`) — kept the file's pre-existing baseline count for `cast.int` at 8 (unchanged), no baseline edits needed.
- Test `tests/unit/Camp/BasePickerTest.php`: builds its own schema under table prefix `bp2_` (own MySQL connection, drop+create in setUp/tearDown). The "chosen base with buildings" screen ends in `Request::encodeFile(base_url(...))` (`sendPhoto`), unreachable in this test stand regardless of `disable_media` (the `fopen()` happens before `MediaSender` gets to check the flag — pre-existing pattern, see `StartRobotGatheringBaseTest`'s docblock). Scenarios landing there are asserted by catching the thrown `TelegramException` and checking its message names `base_with_its_buildings.jpg` (proves the real render was reached, not an error/picker branch) plus, where available, a DB side-effect (`claimed_cells.last_visited_at` via `touchVisit()`, which runs before the photo call). Text-only branches (picker, unavailable, no-base) are asserted end-to-end via the real response text/buttons.
- INTERFACES: `BaseService::showBaseInfo(int $chatId, array|CharacterEntity $characterRow, ?int $editMessageId = null, ?int $baseId = null): ServerResponse` (new 4th param); `BaseServiceMessageFormatter::baseBuildings(..., ?array $interior = null, int $baseId = 0): array` (new last param), new `BaseServiceMessageFormatter::basePicker(array $bases): array`; `DetailedBaseInfoAction::showBuildings(..., ?array $coverageResult = null, ?int $baseId = null): ServerResponse` (new last param).

## Findings

None — story unambiguous, no blockers hit.
