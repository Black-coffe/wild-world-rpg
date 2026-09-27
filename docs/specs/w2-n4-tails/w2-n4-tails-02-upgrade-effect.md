---
story: w2-n4-tails-02
spec: w2-n4-tails
status: todo
returned:
tier: 2
worker: worker-code
model: opus
wave: 2
blocked_by: [w2-n4-tails-01]
---

# Эффект уровня в ядре: веб-карточка и запрос апгрейда бота

## Goal
Расчёт строки эффекта постройки на уровне N (`EFFECTS`, `fmtEffect`, `nonMultiplierLine` из
`BaseDevelopmentAction`) живёт в нейтральном сервисе. Превью апгрейда отдаёт `effect_now` и
`effect_next`. Веб-карточка апгрейда и экран бота «Подтвердите апгрейд?» показывают «сейчас → после»,
а «🏗 Развитие базы» строит свой текст на том же расчёте без изменения вывода.

## Requirements
> 3. Эффект «сейчас → после» считается в ядре и показывается в веб-карточке апгрейда и в запросе апгрейда бота. «Развитие базы» берёт тот же расчёт, её текст не меняется. Всё читается без картинок.

## Files
- app/Services/BuildingEffects/BuildingEffectLines.php
- app/Controllers/Telegram/Commands/Actions/Camp/BaseDevelopmentAction.php
- app/Services/Buildings/BuildingUpgradeService.php
- app/Services/Player/BuildingUpgrade/BuildingUpgradeMessageFormatter.php
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/UpgradeBuildingAction.php
- app/Views/site/_play/native_base.php
- tests/unit/Camp/BuildingEffectLinesTest.php
- tests/unit/Camp/BaseDevelopmentBaseScopeTest.php
- tests/unit/Camp/BuildBotParityTest.php
- tests/database/BuildingUpgradeServiceTest.php
- tests/unit/Views/PlayViewsTest.php
- phpstan-baseline.neon

## Non-goals
- Не менять формулы `BuildingEffectsService` и числа множителей.
- Не менять текст «Развитие базы» — ни символа (снимок до/после в тесте).
- Не добавлять эффект в карточку стройки (там уже `info_text`).

## Map slice
`memory/map/bases.md` — «Три ядра» (BuildingUpgradeService, превью); `memory/map/player.md` — BuildingUpgrade.

## Acceptance criteria
- [ ] `BuildingEffectLines` отдаёт строку эффекта по `name_en` и уровню, `null` для зданий без эффекта; «Развитие базы» даёт тот же текст, что до правки (снимок).
- [ ] Превью апгрейда несёт `effect_now`/`effect_next`; веб-карточка показывает строку «✨ Эффект: <сейчас> → <после>», когда оба не `null`.
- [ ] Экран бота «Подтвердите апгрейд?» содержит ту же строку; caption/текст без картинки полон, Markdown-безопасен.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress && vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes

## Findings
