---
story: d-dead-res-01
spec: d-dead-res
status: done
returned: DONE
tier: 2
worker: worker-code
wave: 1
blocked_by: []
red: required
red_reason: Рецепта Antivenom нет в Config\CraftRecipes
red_verified_at: 2026-11-01T20:34:04Z 7d1823d1d6eedca76c0c96a50825104717b885d028be4e9ab9fae3bd2acd567b
---

# Данные и рецепты четырёх лекарств из мёртвого сырья

## Goal
В игре есть четыре новых лекарства первого верстака (`Antivenom`, `ChitinPatch`, `SulfurOintment`,
`MineralTablets`, состав — таблица `plan.md`): строки `crafted_items` (`type='drug'`) и `tasks`, лечение и время
в `GameSettings`, рецепты в `Config\CraftRecipes`, категория «medicine» в `Config\CraftCatalog`, кнопки в меню
«⚕️ Медикаменты» бота; Аптечка и Провизия их применяют. Тест держит контракт и правило «не печатает золото».

## Requirements
> дать потребителя самым накопленным
> 1. Семь самых накопленных мёртвых ресурсов — Змеиный яд, Тропические насекомые, Редкие руды, Снег, Капельники, Сера, Вулканический пепел — получают потребителя: каждый входит хотя бы в один новый рецепт на существующем верстаке, и рецепт виден и в боте, и в `/play`.
> 5. Крафт не печатает золото: для каждого нового рецепта цена продажи предмета × 1.10 ≤ золото рецепта + стоимость сырья по `sell_price` — проверяется тестом.
> 2. Новые предметы дают уже работающий эффект: это лекарства `type='drug'`, их применяют Аптечка и Провизия, лечение и выносливость читаются из `GameSettings` (`medical.<snake>.heal_health` / `.heal_tired`, с rationale и границами); старые рецепты и постройки не меняются — существующие ключи `Config\CraftRecipes` и `buildings.required_resources` в диффе не тронуты.
> 6. Media-off: карточка нового рецепта и итог крафта читаются без фото — caption несёт имя, эффект с числами из `GameSettings`, сырьё с количеством и время.
> 4. Запасы игроков не трогаем: ни одна миграция спеки не пишет в `character_resources`, склад или `crafted_items_log`.

## Files
- app/Database/Migrations/2026-12-23-100000_DeadResAddFieldMedicineItems.php
- app/Database/Migrations/2026-12-23-100010_DeadResSeedFieldMedicineGameSettings.php
- app/Config/CraftRecipes.php
- app/Config/CraftCatalog.php
- app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchGeneral/Medical/MedicalCraft1Action.php
- app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchProfessional/MedicalRecipePreviewT3Action.php
- app/Controllers/Telegram/Commands/Actions/UsePharmacyAction.php
- app/Controllers/Worker.php
- tests/unit/Config/CraftRecipesDeadResourcesTest.php

## Non-goals
- Не трогать существующие ключи `CraftRecipes`, `buildings`, `resources` (биомы, уровни, редкость), босс-лут, коллекции.
- Не заводить legacy-экраны `*Craft1Action` под каждый рецепт; не вписывать новые лекарства в лечение ран.
- Не переносить количества рецептов в `GameSettings` (долг D8).

## Map slice
`memory/map/craft.md` — Entry points, Gotchas (ADR-182 `craft_again_callback`, `CraftCardHelper::available()`, «не печатает золото»).

## Acceptance criteria
- [ ] Миграции идемпотентны (skip по `name_eng` / `tasks.name` / ключу GameSettings), `php -l` чист, не пишут `character_resources`.
- [ ] Каждая из семи — во входах хотя бы одного нового рецепта; `CraftRecipesTest`, `CraftRecipeReachabilityTest` зелёные.
- [ ] `CraftRecipesDeadResourcesTest`: 4 ключа есть в `CraftRecipes` и `CraftCatalog`; `price × 1.10 ≤ gold + Σ qty × sell_price` по сиду миграции.
- [ ] Лечение читается из `medical.<snake>.heal_*` (rationale, soft/hard границы, default) — путь `UsePharmacyAction::gsHeal`.
- [ ] Карточка рецепта в боте: имя, лечение с числами, сырьё с количеством и наличием, время — без фото; «Назад» ведёт в «⚕️ Медикаменты».

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Config/CraftRecipesDeadResourcesTest.php`
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Config/CraftRecipesTest.php`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
`git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`

## Implementation notes
- **Отступление: время крафта — в `tasks.min/max_duration` (минуты), не в `GameSettings`.** Единственный
  GameSettings-путь времени — `duration_override_setting_key` в `CraftDurationService::baseMinutes()`; он хранит
  целые часы (у S19 hard_min 1 ч) и отключает разброс по статам. Полевое лекарство варилось бы ≥ 60 мин против
  3–19 мин у соседей по «💊 Лекарствам», и сырьё снова не тратилось бы. Так задано время у всех рецептов первого
  верстака, включая сезонные. Сыворотка 8–14, Пластырь 4–7, Мазь 6–10, Таблетки 8–14. Минуты в админке —
  отдельная правка `CraftDurationService`, вне файлов story.
- **Цены — по проду, preprod расходится:** «Вулканический пепел» sell_price на проде 3.80, на preprod 13.30
  (`preprod-settings-drift`). Тест держит прод-снимок 2026-11-01. Цены предметов: Сыворотка 70 (вход 77.90),
  Пластырь 30 (34.20), Мазь 50 (58.90), Таблетки 60 (70.30). `gold_required` нет, как у всей «💊 Лекарств».
- `UsePharmacyAction` не тронут: data-driven ветка S19 `resolveEffectsFromSettings()` уже применяет
  `medical.<snake>.heal_*` к любому `drug`. Ключи сидит `…100010` (combat, rec 0–40, hard 0–100).
- `MedicalRecipePreviewT3Action` стал нейтральным к верстаку: без `required_crafted_items` — без «(T3)», «Назад» →
  `medicinesCraft1`. Сырьё — пул рюкзак+склад через `CraftCardHelper::available()` (раньше считался только рюкзак,
  расходилось с гейтом старта; T3 тоже получает это исправление). Добавлены строка «Время крафта (1 шт.)» из
  `CraftDurationService::minutesForOne()`, кнопки количества `quantityRows()`, при нехватке —
  `fallbackButton()` («🛒 Чего не хватает?»). Строка «Золото» — только при `gold_required > 0`, при нехватке уровня — 🔒.
- Меню «💊 Лекарства» (`MedicalCraft1Action`): два ряда по 2 кнопки `craftPreviewT3Medical_<Key>`. Карточка
  `CraftCatalog` general/medicine — те же 4 ключа (`/play`). Worker: обе карты `craft<Key>`.
- Картинки `uploads/telegram/craft/<snake>.jpg` в рецептах уже прописаны, файлы делает d-dead-res-02. До них
  карточка падает на `professional_workbench.jpg` (`is_file`), caption полон в media-off.
- Локально: `php spark migrate` применил обе миграции (4 `crafted_items` drug, 4 `tasks`, 8 ключей).

## Findings

## Red receipt
- command: vendor/bin/phpunit --no-coverage --no-progress tests/unit/Config/CraftRecipesDeadResourcesTest.php
- exit: 1
- tests: 7d1823d1d6eedca76c0c96a50825104717b885d028be4e9ab9fae3bd2acd567b (tests/unit/Config/CraftRecipesDeadResourcesTest.php)
- impl: 91bb7f1521c12ec075ae99909df2db244997ae9f63f735c7cbadb2a1659c708c
- stamped: 2026-11-01T20:34:04Z

```
        $logEntry = $this->craftedItemsLogModel->getItemByNameEngAndCharacterId($itemNameEng, $characterId);\n
        return $logEntry ? (int) $logEntry['quantity'] : 0;\n
    }\n
}\n
' [UTF-8](length: 5784) contains "'craftPreviewT3Medical_Antivenom'" [ASCII](length: 33).

C:\laragon\www\mmorpg\tests\unit\Config\CraftRecipesDeadResourcesTest.php:171

[37;41mFAILURES![0m
[37;41mTests: 6[0m[37;41m, Assertions: 6[0m[37;41m, Failures: 6[0m[37;41m, PHPUnit Deprecations: 1[0m[37;41m.[0m
```
