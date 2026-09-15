---
story: multibase-picker-08
spec: multibase-picker
status: done
returned: DONE
tier: 3
worker: worker-code
model: sonnet
tracer: false
wave: 3
blocked_by: [multibase-picker-02, multibase-picker-03, multibase-picker-04, multibase-picker-06]
---

# Подтверждение апгрейда несёт базу; чистка phpstan-baseline

## Goal
Кнопка «Подтвердить» апгрейда (`confirm_upgrade_building_{id}`), которую строит `BuildingUpgradeMessageFormatter::askPrompt()`, несёт суффикс `_b<baseId>`, если запрос апгрейда пришёл с ним: `UpgradeBuildingAction::confirmUpgrade()` и `BuildingUpgradeValidator::validate(..., ?int $baseId)` (история 03) уже умеют его принимать, но сегодня клик по подтверждению теряет базу и уходит в легаси `resolve()` — под Вышкой апгрейд со второй базы применился бы к первой. Затем — единственная правка `phpstan-baseline.neon` за спеку: удалить записи, ставшие `ignore.unmatched` после историй 01–07, чтобы `phpstan` был зелёным.

## Requirements
> Кнопка базы в пикере и все кнопки, ведущие с экрана выбранной базы (карточки построек, «Поднять уровень», «🤖 Ангар», «Развитие базы», «Декор базы»), несут идентификатор базы в `callback_data` (≤64 байт, проверено тестом); обработчик заново проверяет, что база принадлежит персонажу, активна и доступна (игрок на ней или под её сигналом), иначе — честный отказ. Кнопка без идентификатора (из старых сообщений) работает по прежнему правилу `BaseScopeResolver`.
> Гейты зелёные: `vendor/bin/phpunit --no-coverage --no-progress` и `vendor/bin/phpstan analyse --memory-limit=512M --no-progress`, `php -l` миграций; tech-writing ноты в `mmorpg-vault/tech-writing/` обновлены для каждой тронутой сущности.

## Files
- app/Services/Player/BuildingUpgrade/BuildingUpgradeMessageFormatter.php
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/UpgradeBuildingAction.php
- phpstan-baseline.neon
- tests/unit/Camp/UpgradeConfirmBaseSuffixTest.php

## Non-goals
- Не менять `BuildingUpgradeValidator`, `BaseScopeResolver`, `BaseCallbackSuffix` — контракт уже есть.
- В baseline только удалять устаревшие записи (и обновлять счётчики у тех же сообщений); новых подавлений не добавлять.
- Не трогать карточки построек (история 03 закрыта).

## Map slice
`memory/map/bases.md` — живой путь апгрейда `CallbackPrefixDispatcher` → `UpgradeBuildingAction` → `BuildingUpgradeValidator`. История 03, `## Implementation notes` → `INTERFACES:`.

## Acceptance criteria
- [ ] `askPrompt()`, вызванный для запроса с базой, строит подтверждение `confirm_upgrade_building_<id>_b<baseId>` (через `BaseCallbackSuffix::append()`, ≤64 байт); без базы — байт в байт как раньше. `UpgradeConfirmBaseSuffixTest`.
- [ ] Клик по такому подтверждению доходит до `validate(..., $baseId)` и меняет `level` строки `character_buildings` базы `baseId`, не трогая строку другой базы; чужая/неактивная/недоступная база → текст `unavailable`, ничего не меняется. `UpgradeConfirmBaseSuffixTest`.
- [ ] `vendor/bin/phpstan analyse --memory-limit=512M --no-progress` — 0 ошибок; baseline изменён только удалением/уменьшением записей.
- [ ] Тест строит свою схему сам.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`

## Implementation notes

- `BuildingUpgradeMessageFormatter::askPrompt()` — added 9th optional param `?int $baseId = null`; when set, confirm button's `callback_data` is `BaseCallbackSuffix::append("confirm_upgrade_building_{$buildingId}", $baseId)`; `null` (or omitted) keeps the exact legacy string (`assertSame` on full payload proves byte-identity).
- `UpgradeBuildingAction::askForUpgrade()` — passes its already-parsed `$baseId` (from `BaseCallbackSuffix::split()`, story 03) straight into `askPrompt()`. `confirmUpgrade()` needed no change — it already parsed the suffix from `## Files` history (story 03).
- `phpstan-baseline.neon` — removed all 8 `offsetAccess.nonOffsetAccessible` entries for `CommunicationTowerCoverageService.php` (all reported `ignore.unmatched`); kept the still-matching `missingType.iterableValue` entry for the same file untouched. `phpstan analyse` → 0 errors.
- `tests/unit/Camp/UpgradeConfirmBaseSuffixTest.php` — new, own `ucbs_` schema (pattern from `BuildingCardBaseChoiceTest`). Two formatter-level tests (no DB) prove the suffix/no-suffix button contract; two DB-level tests drive the real `UpgradeBuildingAction::confirmUpgrade()` (own prefixed tables) to prove the confirm click reaches `validate(..., $baseId)` and only touches that base's row, and that foreign/inactive base suffixes get `BaseScopeResolver::TEXT_UNAVAILABLE` with no row change.
- Test buildings are seeded with `name_en=''` deliberately — `HandPump` is present in `Config\Endgame::$buildingFactionMap`, which would route `confirmUpgrade()`'s endgame hook into the real (unprefixed-in-this-schema) `faction_endgame_scores` table; empty `name_en` short-circuits `EndgameProgressionService::recordBuildingUpgrade()` before any query, keeping the test's `## Files` scope untouched.
- `PoolAdoptionRepairUpgradeTest` named in the task's verification list does not exist anywhere in the repo (`Grep`/`Glob` both empty) — skipped; not part of `## Files`, no code was written that would need it.

## Findings
