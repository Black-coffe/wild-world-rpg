---
story: d-dead-res-02
spec: d-dead-res
status: todo
returned:
tier: 2
worker: worker-code
wave: 2
blocked_by: [d-dead-res-01]
---

# Арт, совет, канон и документы новых лекарств

## Goal
У четырёх лекарств есть арт в `Config\ImageRegistry` (стиль «Найденная фотоплёнка», `php spark images:generate`),
совет Роби о том, что сырьё пустошей идёт в лекарства, канон и tech-writing в синке, а ROADMAP записывает, почему
редкие остались трофеями и что хвост — следующая волна.

## Requirements
> самые редкие вывести из пула
> 3. Пул добычи не трогаем: ни у одного ресурса не меняются `biome_id`, `level_required`, `rarity`, босс-лут и коллекции; в `ROADMAP.md` у D-DEAD-RES записано, почему редкие остались трофеями.
> 7. Каждый новый рецепт заведён во всех местах сразу (`crafted_items`, `tasks`, `Config\CraftRecipes`, `Config\CraftCatalog`, меню бота), с артом в `Config\ImageRegistry` в стиле «Найденная фотоплёнка»; `CraftRecipesTest` и `CraftRecipeReachabilityTest` зелёные.
> 8. Вердикты: в `/guide` — нет, потому что раздел «🩺 Раны и лекарства» уже ведёт к лекарствам первого верстака, а новой механики нет; совет — да, одна идемпотентная seed-миграция совета «из чего ещё варят лекарства» тоном Роби без чисел; вход — существующее меню «⚕️ Медикаменты», при нехватке уровня — lock-строка с требованием.
> 9. Канон и документы: `GAME_DESCRIPTION.md` §«Система лечения и медицины» называет новые лекарства и их сырьё; tech-writing ноты тронутых сервисов, handler'ов и конфигов обновлены.

## Files
- app/Config/ImageRegistry.php
- public/uploads/telegram/craft/antivenom.jpg
- public/uploads/telegram/craft/chitin_patch.jpg
- public/uploads/telegram/craft/sulfur_ointment.jpg
- public/uploads/telegram/craft/mineral_tablets.jpg
- tests/unit/Config/CraftRecipesDeadResourcesTest.php
- app/Database/Migrations/2026-12-23-100020_SeedFieldMedicineTip.php
- GAME_DESCRIPTION.md
- ROADMAP.md

## Non-goals
- Не писать раздел `/guide` (вердикт «нет» в brief), не рассылать анонс.
- Не трогать лор других лекарств и чужие разделы канона.

## Map slice
`memory/map/onboarding.md` — «Совет дня» (категории ENUM, тон Роби); `memory/map/craft.md` — Vault.

## Acceptance criteria
- [ ] 4 картинки сгенерированы и зарегистрированы; `CraftRecipesDeadResourcesTest` дополнен проверкой `is_file` по `image_in_progress`/`image_completed` и ключу `ImageRegistry` каждого из 4 рецептов.
- [ ] Совет: идемпотентный ключ `title_en`, одна из 14 категорий ENUM, без чисел баланса, без магической лексики.
- [ ] `GAME_DESCRIPTION.md` §«Система лечения и медицины» называет четыре лекарства и их сырьё; ROADMAP D-DEAD-RES сужен до хвоста, причина про редкие записана.
- [ ] Tech-writing в `mmorpg-vault/tech-writing/` (CraftRecipes, CraftCatalog, MedicalCraft1Action, MedicalRecipePreviewT3Action, UsePharmacyAction) — `last_reviewed` 2026-11-01+.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Config/CraftRecipesDeadResourcesTest.php`
`git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`

## Implementation notes

## Findings
