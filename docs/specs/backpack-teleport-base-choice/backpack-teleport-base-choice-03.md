---
story: backpack-teleport-base-choice-03
spec: backpack-teleport-base-choice
status: done
tier: 1
worker: worker-code
tracer: false
wave: 1
blocked_by: []
---

# Гайд, совет и tech-writing про выбор базы в телепорте

## Goal
`/guide` раздел `teleport` и новый совет объясняют: возврат «домой» при нескольких базах = выбор базы на экране телепорта; tech-writing ноты валидатора и хендлера отражают новый контракт.

## Requirements
> по какому принципу и на какую базу закидывает?

## Files
- app/Services/Onboarding/GuideCatalog.php
- app/Database/Migrations/2026-08-24-220000_SeedTeleportBaseChoiceTip.php
- C:/Projects/mmorpg-vault/tech-writing/services/TeleportUseValidator.md
- C:/Projects/mmorpg-vault/tech-writing/handlers/camp/TeleportUseAction.md

## Non-goals
- Не трогать код телепорта (story 01/02).
- Не писать числа баланса (стоимость, заряды) в guide/совет — только навигация и понятие.
- Не создавать новый раздел guide — расширить существующий `teleport` (`GuideCatalog.php:584-586`).
- Не создавать таблиц (WipeManifest не нужен: seed в существующую `game_tips`).

## Map slice
memory/map/onboarding.md (`GuideCatalog`, инварианты read-only/ключ `[a-z]`); образец seed — `app/Database/Migrations/2026-11-13-110000_SeedTeleportDevicesTip.php` (идемпотентность по `title_en`).

## Acceptance criteria
- [ ] Раздел `teleport` говорит: если баз несколько — телепорт (рюкзак / золото / портативный / опыт) сначала спрашивает, на какую; заброшенные базы целью не бывают.
- [ ] Совет `TeleportBaseChoice` (`title_en` уникален), категория `персонаж`, тон Роби, парные `*`, идемпотентен при повторном `migrate`.
- [ ] `php -l` миграции чист; `GuideCatalogTest` зелёный.
- [ ] Tech-writing ноты: «Публичный API» с новыми сигнатурами, `last_reviewed: 2026-08-24`; если ноты нет — создать по `_templates`.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Services/Onboarding/GuideCatalogTest.php && php -l app/Database/Migrations/2026-08-24-220000_SeedTeleportBaseChoiceTip.php`

## Implementation notes

- `app/Services/Onboarding/GuideCatalog.php` — расширен раздел `teleport`: новый абзац «Если баз
  несколько» между портативным телепортом и «Все три собираются в одном месте», объясняет выбор
  базы, исключение заброшенных, и что при одной базе экрана не будет. Ключ раздела не менялся
  (`teleport`, уже `[a-z]`).
- `app/Database/Migrations/2026-08-24-220000_SeedTeleportBaseChoiceTip.php` — новый совет
  `TeleportBaseChoice`, категория `персонаж`, идемпотентен по `title_en`.
- `C:/Projects/mmorpg-vault/tech-writing/services/TeleportUseValidator.md` — обновлён Public API:
  добавлен `listActiveBases()`, все 4 `validate*` получили `?int $claimedCellId` и новые ветки
  результата `reason:'choose_base'|'no_base'` (по `## Contracts` из `plan.md`). Код story 01 на
  момент этой задачи ещё не внедрён (`findBaseLocation` в файле — bare `->first()`), нота
  описывает целевой контракт, помечено явно в тексте.
- `C:/Projects/mmorpg-vault/tech-writing/handlers/camp/TeleportUseAction.md` — ноты не было,
  создана по `_templates/handler-doc.md`: текущее поведение (4 callback без суффикса) +
  отдельный раздел «Обновление backpack-teleport-base-choice» с целевым контрактом (callback
  `TeleportUse_<Kind>_<claimedCellId>`, экран выбора кнопками через `packButtonRows()`).
- Guide/tips-вердикт (обязательный по правилам): **да** на оба — раздел `teleport` расширен (не
  новый раздел), совет добавлен. Код телепорта не трогался (story 01/02 — вне scope).

## Findings
