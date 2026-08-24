---
story: backpack-teleport-base-choice-01
spec: backpack-teleport-base-choice
status: todo
tier: 1
worker: worker-code
tracer: true
wave: 1
blocked_by: []
---

# Резолвер цели телепорта: активные базы + выбор по id

## Goal
`TeleportUseValidator` перестаёт брать «первую попавшуюся» строку `claimed_cells`: умеет перечислить активные базы персонажа и принять явный `claimedCellId`; при одной базе поведение прежнее, при нескольких без id — сигнал `choose_base`.

## Requirements
> по какому принципу и на какую базу закидывает?
> Пока на нужную не выкинуло, пришлось по порядку две снести

## Files
- app/Services/Player/TeleportUse/TeleportUseValidator.php
- tests/unit/Services/Player/TeleportUseValidatorBaseChoiceTest.php

## Non-goals
- Не трогать `TeleportUseAction` / форматтер / кнопки — это story 02.
- Не чинить `PlayerRespawner` и другие bare-`first()` по `claimed_cells` (Law 3).
- Не добавлять колонку «главная база», миграции, GameSettings.
- Не менять форму успешного результата валидаторов — `TeleportUseAction` читает её как сейчас.

## Map slice
memory/map/player.md §Entry points (`TeleportUse/`); `app/Models/ClaimedCellModel.php:91-127` (`findFirstActiveCell`, `resolveTargetBaseCell`) — образец фильтра.

## Contract (из plan.md)
- `listActiveBases(int $characterId): array` — `claimed_cells.{id,map_cell_id,camp_name}` + `map.{coordinate_x,coordinate_y}`, `status='active'`, `ORDER BY claimed_cells.id`.
- `validateBackpack/validateGold/validatePortable/validateExperience(int $characterId, ?int $claimedCellId = null)`.
- `findBaseLocation(int $characterId, ?int $claimedCellId)` — id задан → строка должна принадлежать персонажу и быть active, иначе `reason=no_base`; id не задан → 1 база = она; ≥2 → `['ok'=>false,'reason'=>'choose_base','bases'=>listActiveBases()]`; 0 → как сейчас.

## Acceptance criteria
- [ ] Персонаж с базами `abandoned`+`active` телепортируется только на `active`.
- [ ] Персонаж с 1 активной базой без id — валидация проходит как раньше (все 4 способа).
- [ ] Персонаж с 2 активными базами без id — `ok=false`, `reason='choose_base'`, `bases` содержит обе в порядке id, деньги/заряды не списаны.
- [ ] Чужой или abandoned `claimedCellId` → `ok=false`, `reason='no_base'`.
- [ ] Тест не зависит от локальной пустой БД: моки моделей как в `tests/unit/Services/Player/TeleportCostServiceTest.php`, либо `tests/database/` с посевом строк (см. `feedback_local_green_on_empty_test_db_proves_nothing`).

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Services/Player/TeleportUseValidatorBaseChoiceTest.php`

## Tracer
Слой сервис → тест. Если валидаторы возвращают не массив, а объект/исключение — сообщить в INTERFACES, story 02 адаптируется.

## Implementation notes

## Findings
