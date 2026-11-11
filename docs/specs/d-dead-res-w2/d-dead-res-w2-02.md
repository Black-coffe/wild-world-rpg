---
story: d-dead-res-w2-02
spec: d-dead-res-w2
status: done
returned: DONE
tier: 2
worker: worker-code
wave: 2
blocked_by: [d-dead-res-w2-01]
---

# Арт, совет, канон и закрытие пункта

## Goal
У пяти лекарств есть арт (`Config\ImageRegistry`, `loot.medicine`, стиль «Найденная фотоплёнка», файлы на диске),
совет Роби о лекарствах из сырья льдов, пещер, пустыни и вулканов (не дубль `FieldMedicineFromWastelandStock`),
канон и tech-writing в синке, а `ROADMAP.md` закрывает D-DEAD-RES строкой журнала.

## Requirements
> **Редкие из пула не выводим** (решение владельца 2026-11-01)
> 6. Каждый рецепт заведён во всех местах сразу (`crafted_items`, `tasks` `craft<Key>` + `Worker`, `Config\CraftRecipes`, `Config\CraftCatalog`, меню бота), с артом в `Config\ImageRegistry` в стиле «Найденная фотоплёнка» и файлом на диске; `CraftRecipesTest` и `CraftRecipeReachabilityTest` зелёные.
> 7. Вердикты: в `/guide` — нет, потому что раздел «🩺 Раны и лекарства» уже ведёт к лекарствам первого верстака, а новой механики нет; совет — да, одна идемпотентная seed-миграция совета (ключ `title_en`, категория из ENUM, тон Роби, без чисел баланса, не дубль совета волны 1 `FieldMedicineFromWastelandStock`); вход — существующее меню, при нехватке уровня — 🔒 в строке уровня карточки.
> 8. Канон и документы: `GAME_DESCRIPTION.md` §«Полевые лекарства из сырья пустошей» называет пять новых лекарств и их сырьё; tech-writing ноты тронутых конфигов и handler'ов обновлены; в `ROADMAP.md` пункт D-DEAD-RES закрыт строкой журнала §6 и удалён из §2 и §4 (редкие трофеи остаются, причина записана).

## Files
- app/Config/ImageRegistry.php
- public/uploads/telegram/craft/seal_balm.jpg
- public/uploads/telegram/craft/thermal_culture.jpg
- public/uploads/telegram/craft/cave_tallow.jpg
- public/uploads/telegram/craft/desert_salt.jpg
- public/uploads/telegram/craft/painkiller_shot.jpg
- tests/unit/Config/CraftRecipesDeadResourcesWave2Test.php
- app/Database/Migrations/2026-12-24-100020_SeedTailMedicineTip.php
- app/Database/Migrations/2026-12-24-100010_DeadResW2SeedTailMedicineGameSettings.php
- GAME_DESCRIPTION.md
- ROADMAP.md

## Non-goals
- Не писать раздел `/guide`, не рассылать анонс; не трогать совет и арт волны 1.

## Map slice
`memory/map/onboarding.md` — «Совет дня» (ENUM, тон Роби); `memory/map/craft.md` — Vault.

## Acceptance criteria
- [ ] 5 картинок сгенерированы, просмотрены, зарегистрированы; тест проверяет `is_file` и запись реестра каждой.
- [ ] Совет: идемпотентный `title_en`, категория ENUM, без чисел и магической лексики, путь в меню сверен с кнопками.
- [ ] Канон называет пять лекарств и сырьё; ROADMAP: D-DEAD-RES удалён из §2/§4, строка §6 с тегом.
- [ ] Tech-writing (`mmorpg-vault/tech-writing/`): CraftRecipes, CraftCatalog, Consumables, MedicalCraft1Action, ImageRegistry, Worker — `last_reviewed` 2026-11-11+.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Config/CraftRecipesDeadResourcesWave2Test.php`
`git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`

## Implementation notes
- Арт: 5 записей `craft/<snake>` в `Config\ImageRegistry` (`loot.medicine`, V4), `php spark images:generate --key …` — 5 ок, просмотрены: стиль «Найденная фотоплёнка», текста нет, предмет в центре; статус `generated`. Тест `testEachRecipeHasArtOnDiskAndInImageRegistry` — путь, `is_file`, реестр, lexicon, status.
- Совет `2026-12-24-100020_SeedTailMedicineTip`: `title_en=TailMedicineFromBiomeStock`, «крафт», без чисел и магической лексики; путь «🔨 Крафт → 🔨 Общий крафт → 💊 Лекарства» и «💊 Аптечка» — те же, что сверены в волне 1. Локально применён.
- **Plan delta — имя меню:** меню называется «💊 Лекарства» (`MedicalCraft1Action.php:30`), не «⚕️ Медикаменты», как ошибочно стояло в brief/asks; `effect_text` десяти ключей в `…100010` и тексты теста исправлены (файл добавлен в `## Files` коммитом revised). Локальная БД держит старый `effect_text` — миграция уже прошла до правки; preprod/прод получат исправленный.
- Канон: `GAME_DESCRIPTION.md` — «Волна 2» под «Полевыми лекарствами из сырья пустошей» и строка про четыре редких трофея.
- ROADMAP: §2 п.1 → E-FIRST.M (6/20 новичков на 11.11); пункт D-DEAD-RES из §4 удалён; строка §6 с тегами v0.51.709 + v0.51.711 (тег волны 2 — при выпуске, сверить).
- Tech-writing (vault `b72a9e3`): CraftRecipes, CraftCatalog, Consumables, MedicalCraft1Action, ImageRegistry, Worker — `last_reviewed: 2026-11-11`.
- Вердикты: `/guide` — нет (раздел «🩺 Раны и лекарства» уже ведёт к лекарствам, механики новой нет); совет — да (выше); вход — существующее меню «💊 Лекарства», нехватка уровня — 🔒 на карточке.

## Findings
