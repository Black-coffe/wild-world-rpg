---
story: multibase-picker-07
spec: multibase-picker
status: done
returned: DONE
tier: 3
worker: worker-code
model: sonnet
tracer: false
wave: 1
blocked_by: []
---

# `/guide` и совет дня про несколько баз

## Goal
В разделе `base` `GuideCatalog` есть абзац: при нескольких базах «🏠 База» вне базы предлагает кнопками те, до которых дотягивается их Вышка связи, остальные перечисляет с координатами и расстоянием; экран, Ангар, развитие и декор работают с выбранной базой; робот копает у базы, где стоит и где есть своя Мастерская. Идемпотентная seed-миграция добавляет совет дня про это же.

## Requirements
> Tips: да — идемпотентная seed-миграция `*Seed<Что>Tip.php`, категория `общие`, тон Роби, без чисел баланса. `/guide`: да — абзац о нескольких базах и Вышке связи в разделе `base` `GuideCatalog`.

## Files
- app/Services/Onboarding/GuideCatalog.php
- app/Database/Migrations/2026-12-07-110000_SeedMultibasePickerTip.php
- tests/unit/Services/Onboarding/MultibasePickerGuideTipTest.php

## Non-goals
- Не переписывать существующие абзацы раздела `base` (расширялся 04.09 и 10.09) — только добавить.
- Никаких чисел баланса (радиус, уровни) ни в абзаце, ни в совете.
- Не дублировать совет `DuplicateBuildingsAbsorbed` — ключ `title_en` новый.

## Map slice
`recon.md` — «Прочее» (категории `game_tips`, прецедент `общие`). Прецедент seed-совета — любая `*Seed*Tip.php` в `app/Database/Migrations/`.

## Acceptance criteria
- [ ] Раздел `base` содержит абзац о нескольких базах и Вышке связи; markdown-safe, без цифр баланса; существующий `GuideCatalogTest` (раздел `base`, number-gate) зелёный.
- [ ] Миграция вставляет совет с новым `title_en`, категорией `общие`, тоном Роби; повторный запуск не создаёт дубль; `down()` удаляет по `title_en`; `php -l` чист.
- [ ] `MultibasePickerGuideTipTest` проверяет наличие абзаца и идемпотентность сида на схеме, которую тест строит сам.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`

## Implementation notes

- `GuideCatalog.php`: раздел `base` расширен двумя параграфами (Вышка связи/пикер баз; робот у своей Мастерской), без цифр — number-gate `GuideCatalogTest::testBaseSectionMentionsSingleBuildingDemolishWithoutRefund` остался зелёным.
- `2026-12-07-110000_SeedMultibasePickerTip.php`: идемпотентный совет `title_en='MultibasePicker'`, категория `общие`, тон Роби, без чисел. `game_tips` уже в `WipeManifest` (KEEP) — новой таблицы/колонки нет, классификация не требуется.
- `MultibasePickerGuideTipTest.php`: своя приватная схема `game_tips` через `DatabaseTestTrait`/`Database::connect('tests')`; мигрирует явным `require_once` + `new SeedMultibasePickerTip()` (миграции исключены из composer classmap — обычный `use` без require не находит класс), проверяет идемпотентность двойного `up()` и `down()` по `title_en`.
- Не трогал `phpstan-baseline.neon`, `WipeManifest`, другие разделы `base` (04.09/10.09).

## Findings
