# Рюкзак-телепорт: выбор базы при нескольких базах (plan)

**Approved:** 2026-08-24 (владелец, в чате)
**Tier:** 2 · **Spec slug:** `backpack-teleport-base-choice` · **Brief:** [brief.md](brief.md)
**Governed by:** ADR-095 (мульти-база), ADR-102 (`ClaimedCellModel::resolveTargetBaseCell` — канон выбора базы), ADR-020 (media-off), правило «ноль кнопок в строку» (`packButtonRows`), GUIDE/TIPS-coverage.
**Depends on:** портативный телепорт (bbaea90b…), экран «📡 Маяки» 2026-08-18.

## Goal
Игрок с несколькими базами, применяя рюкзак-телепорт (и его три брата: золото / портативный / опыт — одна и та же `findBaseLocation()`), должен **сам выбрать, на какую базу** прыгнуть, а при одной базе — прыгать сразу, как сейчас. Сегодня цель = первая строка `claimed_cells WHERE character_id=?` без `orderBy` и без фильтра `status='active'` → старейшая (потенциально заброшенная) база; игроку это нигде не объяснено, и он сносит базы, чтобы «попасть на нужную».

## Диагноз (разведка 2026-08-24)
- `app/Services/Player/TeleportUse/TeleportUseValidator.php:265-282` — `findBaseLocation()`: bare `->first()`, без `status`, без `orderBy`; используется всеми 4 ветками (`:114/:158/:214/:244`).
- `app/Controllers/Telegram/Commands/Actions/Camp/TeleportUseAction.php` — callback `TeleportUse_{Backpack,WithGold,Portable,WithExperience}`, выбора базы нет.
- Канон уже есть: `ClaimedCellModel::resolveTargetBaseCell()` / `findFirstActiveCell()` (ADR-102) — телепорт их не использует.
- Флага «главная база» в схеме нет и не нужен: активная база — позиционная.
- Прод: сейчас ни у кого нет ≥2 активных баз (Torch0010 снёс две, осталась `claimed_cells.id=242` «Старый Маяк»); лимит баз растёт с уровнем (`buildings.bases.levels_per_base`=50, hard_cap 19) — проблема системная для всех L100+.

## Assumptions
- Решение — **выбор базы на экране телепорта**, а не флаг «главная база»: нет новой колонки/миграции/WipeManifest, совпадает с ADR-102 (игрок явно указывает базу). Отвергнуто: «на ближайшую» (непредсказуемо, не то, что просил игрок) и «на базу, где стоял последним» (нужно хранить состояние).
- Одна база → прыжок сразу без лишнего экрана (поведение не меняется для игроков с одной базой).
- Все 4 способа оплаты получают выбор одинаково (одна функция).
- Заброшенные (`status='abandoned'`) базы из целей исключаются.
- Респавн после смерти (`PlayerRespawner.php:82`) — тот же класс bare-`first()`, но **вне этого заказа** (игрок там не выбирает); фиксируется как открытый хвост в hot.md.
- Балансовых чисел нет → GameSettings не трогаем.
- Tips-вердикт: **да**, категория `персонаж` — совет «если баз несколько, телепорт спросит куда». Guide-вердикт: **да**, расширить существующий раздел `teleport`.

## Stories

**Wave 1**
- `backpack-teleport-base-choice-01` — `TeleportUseValidator`: список активных баз + цель по `claimedCellId`; unit-тест на фильтр/порядок/чужой id.
- `backpack-teleport-base-choice-03` — `/guide` раздел `teleport`, seed-совет, tech-writing ноты.

**Wave 2**
- `backpack-teleport-base-choice-02` — `TeleportUseAction`: при ≥2 баз экран выбора с кнопками по базам, callback с id; при 1 — как раньше.

## Contracts
- `TeleportUseValidator::listActiveBases(int $characterId): array` — строки `claimed_cells` (`id, map_cell_id, camp_name`) + `coordinate_x/coordinate_y` из `map`, `ORDER BY claimed_cells.id`, только `status='active'`.
- `validateBackpack/validateGold/validatePortable/validateExperience(array|CharacterEntity $character, ?int $claimedCellId = null)` (первый параметр — как и был) — при `null` и ровно одной активной базе берут её; при `null` и ≥2 возвращают `['ok'=>false,'reason'=>'choose_base','bases'=>[...]]`; при id, не принадлежащем персонажу / не active → `['ok'=>false,'reason'=>'no_base']`. Существующая форма успешного результата сохраняется.
- Callback: `TeleportUse_<Kind>_<claimedCellId>` (напр. `TeleportUse_Backpack_242`); `TeleportUse_<Kind>` без хвоста остаётся валидным (одна база → прыжок; несколько → экран выбора).
- Подпись кнопки: `🏠 <camp_name|«База»> (<x>,<y>)`; ряды через `packButtonRows()` (2–3 в ряд).

## Integration gate
`vendor/bin/phpunit --no-coverage --no-progress` · `vendor/bin/phpstan analyse --memory-limit=512M --no-progress` · `git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null` · Tier-3 на preprod: тест-чар с 2 базами → рюкзак → экран выбора → прыжок на выбранную; с 1 базой → прыжок сразу. Затем тег на `develop` → прод (release-flow), ответ Torch0010 личкой.

## Descoped
