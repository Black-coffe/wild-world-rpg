---
story: craft-shortfall-buy-03
spec: craft-shortfall-buy
status: done
tier: 1
worker: worker-code
tracer: false
wave: 2
blocked_by: [craft-shortfall-buy-01]
---

# Карточки Tools (7) и Defense (1) — на помощника

## Goal
Семь оставшихся карточек инструментов и одна карточка обороны переводятся на помощника: пул вместо рюкзака и выход из тупика.

## Requirements
> для всего абсолютно крафта (ТОЛЬКО КОРАФТ) строения не входят сюда
> Недостаточно ресурсов, чтобы скрафтить хотя бы 1 шт.

## Files
- app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchGeneral/Tools/FishingRodCraft1Action.php
- app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchGeneral/Tools/FoldingKnifeCraft1Action.php
- app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchGeneral/Tools/HoeCraft1Action.php
- app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchGeneral/Tools/IronPickaxeCraft1Action.php
- app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchGeneral/Tools/IronShovelCraft1Action.php
- app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchGeneral/Tools/StonePickaxeCraft1Action.php
- app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchGeneral/Tools/TireIronCraft1Action.php
- app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchGeneral/Defense/MeteorShelterCraft1Action.php

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
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchGeneral/Tools/ app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchGeneral/Defense/`

## Notes
Топор дровосека уже переведён в story 01 и в этот список не входит.

## Implementation notes

- Восемь файлов (FishingRod/FoldingKnife/Hoe/IronPickaxe/IronShovel/StonePickaxe/TireIron/MeteorShelter) переведены на `CraftCardHelper::available()` вместо прямого чтения `CharacterResourceModel`; старый метод `checkResourcesAvailability()` удалён в каждом (не используется), как и в образце Lumberjack.
- В каждый keyboard-блок «нельзя собрать ни одной штуки» добавлена отдельная строка с `craftCardHelper->fallbackButton('<RecipeKey>')` перед кнопкой «Назад»; ключ взят из существующего `callback_data` кнопок количества (`genericCraft_<Key>_{q}`), для MeteorShelter — из `self::RECIPE_KEY`.
- `phpstan-baseline.neon`: убрано 16 устаревших ignore-записей `checkResourcesAvailability()` для 7 тронутых классов + `LumberjackAxeCraft1Action` (её метод уже был удалён story-01, но baseline не подчищен — иначе `reportUnmatchedIgnoredErrors` валил зелёный прогон). Записи для `Components/StoneBlocksCraft1Action` и `CraftShortfallBuyService::craftedRow()` — вне scope этой story, не трогал, полный `phpstan` без пути-фильтра там всё ещё красный (из другой story той же спеки).
- Тексты карточек и остальные кнопки не менялись; сигнатуры не унифицировались (Non-goals).

## Findings
