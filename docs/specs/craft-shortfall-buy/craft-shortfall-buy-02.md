---
story: craft-shortfall-buy-02
spec: craft-shortfall-buy
status: done
tier: 1
worker: worker-code
tracer: false
wave: 2
blocked_by: [craft-shortfall-buy-01]
---

# Карточки Components (10 штук) — на помощника

## Goal
Десять карточек категории «Компоненты» считают наличие тем же пулом, что и старт крафта, и перестают быть тупиком при нулевом количестве.

## Requirements
> для всего абсолютно крафта (ТОЛЬКО КОРАФТ) строения не входят сюда
> Недостаточно ресурсов, чтобы скрафтить хотя бы 1 шт.

## Files
- app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchGeneral/Components/CharcoalBriquettes1Action.php
- app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchGeneral/Components/ElectronicComponentsCraft1Action.php
- app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchGeneral/Components/FabricCraft1Action.php
- app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchGeneral/Components/FertilizerCraft1Action.php
- app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchGeneral/Components/GlassBagsCraft1Action.php
- app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchGeneral/Components/MetalFragmentsCraft1Action.php
- app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchGeneral/Components/SoilCraft1Action.php
- app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchGeneral/Components/StoneBlocksCraft1Action.php
- app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchGeneral/Components/WiringCraft1Action.php
- app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchGeneral/Components/WoodMaterialsCraft1Action.php

## Non-goals
- Не менять формулировки существующих текстов карточек и состав служебных кнопок — только добавить выход из тупика и перевести подсчёт на помощника.
- Не унифицировать сигнатуры методов между файлами: цель story — поведение, а не рефактор слоя.
- Не трогать `Config\CraftRecipes` и не переносить туда захардкоженные требования.
- Не добавлять на карточку ни цен, ни наценки, ни блока докупки.

## Map slice
memory/map/craft.md — «Entry points», «Gotchas» (карточки считают только рюкзак; копии не идентичны).

## Acceptance criteria
- [ ] Каждая карточка story считает доступное через `CraftCardHelper::available()`, а не напрямую по рюкзаку.
- [ ] Каждая карточка при «нельзя собрать ни одной штуки» показывает кнопку-выход из помощника.
- [ ] Кнопка-выход не появляется, когда доступна хотя бы одна штука.
- [ ] Существующие тексты и остальные кнопки карточек не изменились.
- [ ] `phpstan` уровня 9 зелёный на всех затронутых файлах.

## Verification
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchGeneral/Components/`

## Notes
Три файла этой группы (`ElectronicComponents`, `WoodMaterials`, `Wiring`) имеют другую сигнатуру метода кнопок и другую структуру данных требований — их правка не копируется с остальных семи.

## Implementation notes
- 7 файлов (Charcoal/Fabric/Fertilizer/GlassBags/MetalFragments/Soil/StoneBlocks) — точное повторение приёма из `LumberjackAxeCraft1Action`: инъекция `CraftCardHelper`, замена локального `checkResourcesAvailability()` на `available()`, удаление ставшего мёртвым приватного метода, добавление ряда с `fallbackButton('<Recipe>')` перед «Назад» в ветке `maxCraftableItems < 1`.
- `ElectronicComponentsCraft1Action` и `WiringCraft1Action` — требования вложенные (`resources`+`crafted_items`); помощник применён только к секции `resources` внутри `checkResourcesAndCraftedItems()`, секция `crafted_items` не тронута (вне scope помощника). Fallback-кнопка добавлена тем же приёмом.
- `WoodMaterialsCraft1Action` — своя структура (`requiredResourcesBase` + `getResourcesInfo()` + `makeQuantityButtons()`, без `checkResourcesAvailability`/`calculateMaxCraftableItems`); помощник встроен внутрь `getResourcesInfo()`, fallback-кнопка — в ветку `empty($keyboardButtons)`.
- `phpstan-baseline.neon`: удалены 14 записей `ignoreErrors` для метода `checkResourcesAvailability()` в 7 «обычных» файлах — метод удалён из кода, старые baseline-записи стали unmatched и валили `ignore.unmatched`. Файл не назван в `## Files`, но правка чисто механическая (следствие удаления мёртвого метода) и понадобилась, чтобы сама story-verification команда была зелёной.
- Полный `vendor/bin/phpstan analyse` (без сужения на Components/) отдельно показывает 1 ошибку в `app/Services/Craft/CraftShortfallBuyService.php:246` — untracked-файл параллельной сессии (другая story этой же спеки), не создан и не тронут этой работой.

## Findings
