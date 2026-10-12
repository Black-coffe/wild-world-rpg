# «Мир» с экрана дрона и робот-добытчик с лагеря (plan)

**Tier:** 1 · **Spec slug:** `oct-world-robot-fix` · **Brief:** [brief.md](brief.md)
**Governed by:** ADR-150 (единая поверхность «🌍 Мир»), multibase-picker (база запуска робота)
**Depends on:** develop @ df8920f9

## Goal
Два бага из чата игроков. B1: callback `move` перестаёт отказывать при сборе/исследовании и ведёт
себя как нижняя кнопка «🌍 Мир» — смотреть карту можно, шагать нельзя (это уже держит
`MoveService::step`). B3: `launchBase()` для игрока на базе без Мастерской сначала ищет покрытую
Вышкой базу с Мастерской, и только если такой нет — отказывает по базе, на которой он стоит.

## Assumptions
- Отказ при переезде (`checkRelocationAndBlock`) в `MoveCharacterAction` оставляем: о нём не
  жаловались, а переезд меняет клетку игрока.
- Порядок выбора для игрока на базе без Мастерской: первая покрытая база с Мастерской в порядке,
  который уже отдаёт `coverageByBase()` — тот же, что для игрока вне базы.

## Stories

**Wave 1**
- `oct-world-robot-fix-01` — «🌍 Мир» без отказа при занятости + робот с лагеря через покрытую базу

## Contracts
- none

## Integration gate
`vendor/bin/phpunit --no-coverage --no-progress`

## Descoped

*(empty)*

## Plan deltas

- 2026-10-12 · story 01 · тесты B1 — в `tests/database/MoveServiceTest.php` вместо двух новых файлов в `tests/unit/World/`: там уже собрана схема из миграций, запись запросов Bot API и паритет отказов шага; отдельные файлы дублировали бы фикстуру. Отвергнуто: новые unit-файлы без БД (не дошли бы до реального `MoveService::step`).

**Approved:**
**Briefed:** via mini-brief, Andrei, 2026-10-12
**Branch:** vulyk/oct-world-robot-fix
**Checked:**
**Council:**
**Council:** GREEN round 1, 2026-10-12, at a0009099, pack 15728c02a72f
**Shipped:**
