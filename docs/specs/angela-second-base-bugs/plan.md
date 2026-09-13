# Вторая база: карта, карточки зданий и апгрейд (plan)

**Tier:** 3 · **Spec slug:** `angela-second-base-bugs` · **Brief:** [brief.md](brief.md)
**Governed by:** ADR-095 (мульти-база), ADR-102 (`resolveTargetBaseCell`), ADR-122 (per-base налог),
ADR-142 (Навес как one-shot первое укрытие), ADR-020 (media-off), `.claude/rules/db-schema.md`
**Depends on:** `docs/specs/bugs-thread-triage` — вердикты `verdicts/bases.md` по
`4294974671` / `4294974674` («не устранено») и `4294974616` («не баг»)

## Goal
У игрока может быть несколько баз, но три поверхности игры про это не знают: текстовая карта
помечает 🏕 ровно одну базу (`TextMapService` берёт `claimed_cells ->first()` без `orderBy`),
карточки зданий и апгрейд ищут постройку по паре `(character_id, building_id)` без `map_cell_id`
и попадают в строку чужой базы. Последнее не косметика: `BuildingUpgradeApplier` обновляет ту же
найденную строку, то есть апгрейд, запущенный со второй базы, прокачал бы постройку на первой.
После этой работы каждая из трёх поверхностей работает с базой, на которой игрок реально стоит,
а карта показывает все его базы.

## Assumptions
- «Текущая база» определяется существующим контрактом ADR-102 — `ClaimedCellModel::resolveTargetBaseCell($characterId, (int) $character['cell_number'])`; новой семантики не вводим.
- Запросы вида «есть ли у персонажа хоть одна Мастерская робототехники» в `Buildings/Robots/*` и `TeleportBeacon*` — вне охвата: владелец выбрал три поверхности (карта, карточки зданий, апгрейд). Их база-слепота остаётся открытым хвостом и записана ниже в `## Открытые хвосты`.
- `BaseService.php:78` и `BaseCampDecorService` («Декор базы» всегда правит первую базу) — тот же класс, но вне выбранного охвата; тоже в `## Открытые хвосты`.
- Вердикт Редколлегии по `/guide` и «Совету дня» выносит Queen после мерджа, до тега (ask 7); по умолчанию ожидается «нет» — новой игровой двери не появляется, чинится обещанное поведение, — но проверяется по факту наличия раздела про несколько баз в `GuideCatalog`.
- Тесты не должны опираться на таблицу `map` с реальным миром (`feedback_verify_render_on_db_with_real_world_data`): проверяемую логику выбора клеток-маркеров выделить так, чтобы её можно было проверить без наполненной карты.
- **Ask 5 (вторая половина) — вне историй, делает Queen.** Механика Навеса не меняется ни одной историей (`FirstShelterService` не в `## Files` ни у одной), а ответ Анжеле в ветке Bugs-info отправляет Queen после мерджа, вместе с ответом по двум починенным багам — одним сообщением, а не по ходу работы.
- **Ask 6 (media-off) — несут истории, не гейт.** Отдельной команды-проверки на media-off нет: самодостаточность текста записана критерием приёмки в каждой из трёх историй, а окончательно проверяется живым прогоном (ask 9) на персонаже с выключенными картинками.
- **Ask 8 (вторая половина) — tech-writing после мерджа.** Ноты в `mmorpg-vault/tech-writing/` (`TextMapService`, `ClaimedCellModel`, `BuildingUpgradeValidator`, затронутые handler'ы) обновляет `drone-docs` после мерджа — по кастовой таблице `CLAUDE.vulyk.md`, а не внутри историй: vault лежит вне этого репозитория и под `scope-check.sh` не попадает.
- **Ask 9 (Tier-3) — Queen, на preprod, с подготовкой данных.** Проверено SELECT'ом 2026-09-13: на testbot НЕТ ни одного персонажа с двумя активными базами, тест-чар `aviad_echo` (`id=491`, `telegram_user_id=25`, level 24) имеет одну. Перед прогоном на preprod надо завести ему вторую базу (`claimed_cells` + пара строк `character_buildings` разного уровня) — на testbot любые `UPDATE` разрешены. На проде живой прогон не делаем.
- **Ask 10 (выкатка) — Queen, вне историй.** Мердж в `develop` → GitHub Actions катит preprod → прогон ask 9 → тег `v0.51.x` на `develop` → прод + смоук. Состав диффа перед тегом сверяется: в репозитории сейчас работает параллельная сессия.

## Stories

**Wave 1**
- `angela-second-base-bugs-01-map-all-bases` — 🏕 на каждой активной базе в окне карты + расстояние до ближайшей (tracer).
- `angela-second-base-bugs-02-building-cards-base-scope` — карточки зданий читают постройку текущей базы.
- `angela-second-base-bugs-03-upgrade-base-scope` — валидация и применение апгрейда на текущей базе.

## Contracts
- `App\Models\ClaimedCellModel::findAllActiveCells(int $characterId): array<int, array<string,mixed>>` — новый метод, возвращает ВСЕ активные записи баз персонажа. Добавляет и владеет им story 01; истории 02 и 03 его не трогают и не вызывают.
- Разрешение «текущей базы» в историях 02 и 03 идёт ТОЛЬКО через существующий `ClaimedCellModel::resolveTargetBaseCell(int $characterId, int $currentCell): ?int`, где `$currentCell = (int) $character['cell_number']`. Новых методов модели эти истории не добавляют.
- Когда база неоднозначна (`resolveTargetBaseCell` вернул `null`: ≥2 баз и игрок не на базе) — экран не молчит и не падает, а просит встать на нужную базу. Текст один и тот же в обеих историях: `Баз у тебя несколько. Встань на ту базу, с которой работаешь, — и открой экран снова.`

## Integration gate
`vendor/bin/phpunit --no-coverage --no-progress` и `vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
Перед каждой волной — `bash scripts/wave-check.sh docs/specs/angela-second-base-bugs`.

## Открытые хвосты
Найдено разведкой, сознательно вне охвата этой спеки (решение владельца «весь класс в трёх поверхностях»):
- `app/Services/Housing/BaseCampDecorService.php:133` — «Декор базы» всегда редактирует первую базу.
- `app/Services/BaseService.php:78` — `findFirstActiveCell` как «моя база».
- `app/Controllers/Telegram/Commands/Actions/Camp/Buildings/Robots/*` и `TeleportBeacon*.php` — наличие здания проверяется по персонажу, не по базе.

## Descoped

*(empty)*

## Plan deltas

**Approved:** <owner, date>
**Briefed:** via grill, Andrei, 2026-09-13
**Branch:** <\/vulyk-build>
**Checked:** <scripts/human-check.sh>
**Council:** <scripts/cycle.sh judge>
**Shipped:** <scripts/ship-check.sh --record>
