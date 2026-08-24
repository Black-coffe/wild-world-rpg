---
story: backpack-teleport-base-choice-04
spec: backpack-teleport-base-choice
status: done
tier: 1
worker: worker-code
tracer: false
wave: 3
blocked_by: [backpack-teleport-base-choice-01, backpack-teleport-base-choice-02, backpack-teleport-base-choice-03]
---

# Ремонт по ревью: экран выбора называет способ, канон-хелперы, покрытие координат

## Goal
Закрыть находки `lead-review` №3, 4, 7, 8, 11, 12 без изменения внешнего контракта (callback, форма результата валидаторов, поведение при 0/1/≥2 базах — как в story 01/02, смок на preprod уже зелёный).

## Requirements
> по какому принципу и на какую базу закидывает?

## Files
- app/Services/Player/TeleportUse/TeleportUseValidator.php
- app/Services/Player/TeleportUse/TeleportUseMessageFormatter.php
- app/Controllers/Telegram/Commands/Actions/Camp/TeleportUseAction.php
- app/Database/Migrations/2026-08-24-220000_SeedTeleportBaseChoiceTip.php
- tests/unit/Services/Player/TeleportUseValidatorBaseChoiceTest.php
- tests/unit/Services/Player/TeleportUseMessageFormatterBaseChoiceTest.php
- phpstan-baseline.neon

## Conditions to satisfy (из ревью)
- №8 — экран выбора называет способ, который будет потрачен: «🎒 Рюкзак-телепорт (1 заряд)» / «💰 Телепорт за золото» / «📡 Портативный телепорт (1 заряд)» / «✨ Телепорт за опыт» — без чисел баланса (стоимость золота/опыта НЕ печатать, только способ). `chooseBase(string $kind, ...)` уже получает kind.
- №12 — при пустом списке баз после `extractBases()` экран выбора НЕ рендерится: показать существующее сообщение «база не найдена» (`baseNotFound()`).
- №7 — в тексте совета убрать «бесплатный» про телепорт за опыт (он стоит опыта; см. `TeleportAction.php:124`). Миграция идемпотентна по `title_en` — если уже применена на preprod, текст обновится только при новом `title_en`... НЕ менять `title_en`; вместо этого сделать upsert: при существующей строке обновлять `content` (паттерн: `where('title_en')->first()` → update, иначе insert).
- №4 — счёт активных баз и единственная база — через `ClaimedCellModel::countActiveBases()` / `findFirstActiveCell()` (`app/Models/ClaimedCellModel.php:91,132`); `listActiveBases()` оставить в сервисе (с `ORDER BY id` + координаты), но записать в докблоке, почему он не в модели (обогащение из `map` — не забота модели claimed_cells).
- №3 — тест `listActiveBases()` проверяет `coordinate_x/coordinate_y` из мока `MapModel`.
- №11 — новые offsetAccess-подавления для этого кода из `phpstan-baseline.neon` убрать, сузив типы на месте доступа (`(int) ($row['id'] ?? 0)` и т.п. с явным `is_array`/`isset`), чтобы `phpstan analyse` был чист без baseline-записей на новые строки. Существующие старые записи файла не трогать.

## Non-goals
- Не менять формат callback `TeleportUse_<Kind>_<id>`, не менять GuideCatalog, tech-writing, план.
- Не трогать `PlayerRespawner`, `ClaimedCellModel`.
- Не добавлять GameSettings, не печатать стоимость в золоте/опыте (баланс — анти-дрейф).

## Map slice
memory/map/player.md; story 01/02 §Implementation notes.

## Acceptance criteria
- [ ] Экран выбора содержит название способа (тест форматтера на все 4 kind'а).
- [ ] Пустой `bases` → `baseNotFound()`, не экран с «Активных баз: 0».
- [ ] Совет не называет телепорт за опыт бесплатным; повторный `migrate` обновляет content, не дублирует строку.
- [ ] `TeleportUseValidatorBaseChoiceTest` покрывает координаты; все прежние тесты story 01/02 зелёные.
- [ ] `vendor/bin/phpstan analyse --memory-limit=512M --no-progress` чист, при этом в `phpstan-baseline.neon` нет записей на новые строки этого кода.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Services/Player/TeleportUseValidatorBaseChoiceTest.php tests/unit/Services/Player/TeleportUseMessageFormatterBaseChoiceTest.php && vendor/bin/phpstan analyse --memory-limit=512M --no-progress && php -l app/Database/Migrations/2026-08-24-220000_SeedTeleportBaseChoiceTip.php`

## Implementation notes

- №8 — `TeleportUseMessageFormatter::chooseBase()` now names the spending method via a new
  private `methodLabel(string $kind)` (match-expression, 4 kinds + `default`): «🎒 Рюкзак-телепорт
  (1 заряд)» / «💰 Телепорт за золото» / «📡 Портативный телепорт (1 заряд)» / «✨ Телепорт за
  опыт». No gold/experience numbers printed (anti-drift). Header line: `Активных баз: *{N}*.
  {method} — куда прыгаем?`.
- №12 — `TeleportUseAction::handleReason()`: `extractBases()` result checked for `=== []` before
  calling `formatter->chooseBase()`; empty → `baseNotFound()` instead. Defensive path only —
  `findBaseLocation()` never emits `reason=choose_base` with an empty `bases` array (always ≥2
  active bases), so this guards malformed data, not a normal flow.
- №7 — `SeedTeleportBaseChoiceTip` migration: removed «бесплатный» before «телепорт за опыт» in
  `content`. `up()` no longer no-ops on an existing `title_en` row: it now UPSERTs — `update([
  'content', 'updated_at'])` when the row exists, `insert()` otherwise. `title_en` unchanged
  (idempotency key preserved per instruction — changing it would have produced a duplicate row on
  preprod where the original migration already ran).
- №4 — `TeleportUseValidator::findBaseLocation()`: the "count active bases" and "single active
  base" branches (no explicit `$claimedCellId`) now call `ClaimedCellModel::countActiveBases()` /
  `findFirstActiveCell()` (ADR-102 canon) instead of raw `where()->countAllResults()` /
  `where()->first()`. The explicit-`$claimedCellId` branch keeps its own raw `where('id',
  ...)->where('character_id', ...)->where('status','active')->first()` — it is a different lookup
  (by id, not "the/any active base") not covered by either canon helper, and `ClaimedCellModel`
  itself is out of scope (Non-goal, story 01). `listActiveBases()` stays in the service — docblock
  added explaining why: enrichment from `MapModel` isn't `ClaimedCellModel`'s concern, and it's a
  one-screen list, not a reusable canon query.
- №11 — added a private `normalizeRow(array $row): array<string,mixed>` helper (local copy of the
  pattern in `ClaimedCellModel::normalizeKeys()` — Non-goal forbids touching that model) and
  `is_array()` narrowing at every raw `Model::first()/findAll()` result before offset access
  (`findBaseLocation()`'s explicit-id branch, `resolveMapRow()`, `listActiveBases()`). This
  eliminated all 7 phpstan-baseline entries that story 01 had added for
  `TeleportUseValidator.php` (`camp_name`/`coordinate_x`/`coordinate_y`/`id` offsetAccess on the
  union type, the `map_cell_id` offsetAccess, and `resolveMapRow()`'s return/argument type
  mismatches) — verified via `git show 95f0b9db -- phpstan-baseline.neon` to isolate exactly which
  entries were new vs. pre-existing, then confirmed via `phpstan analyse` reporting them as
  "not matched" (i.e. genuinely fixed, not just hidden) before deleting. Pre-existing entries for
  this file (from before story 01) were left untouched. `listActiveBases()`'s numeric casts use the
  existing file convention `is_numeric($x) ? (int) $x : 0` (not bare `(int) ($x ?? 0)`) to avoid
  triggering new `cast.int` baseline growth — the pre-existing `cast.int`/`cast.string` entries
  (count 4/1) are unrelated to this story and were not touched.
- №3 — `testListActiveBasesReturnsOnlyActiveOrderedById` extended with `coordinate_x`/
  `coordinate_y` assertions against the mock `MapModel`'s rows (previously only asserted `id`
  ordering).
- New test `testChooseBaseNamesTheSpendingMethodForEachKind` in the formatter test covers all 4
  kinds' labels.
- `vendor/bin/phpstan analyse --memory-limit=512M --no-progress` → 0 errors, 0 unmatched baseline
  entries. `vendor/bin/phpunit --no-coverage --no-progress` on both story-04 test files → 13
  tests, 98 assertions, green. `php -l` on the migration → clean.
- Files touched match `## Files` exactly; no other files edited.

## Findings

Нет — все 6 review findings (№3, 4, 7, 8, 11, 12) addressed within the declared contract; no
change to callback format, validator success-result shape, or 0/1/≥2-base behavior from story
01/02.
