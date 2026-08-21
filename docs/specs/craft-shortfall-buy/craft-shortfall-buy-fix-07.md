---
story: craft-shortfall-buy-fix-07
spec: craft-shortfall-buy
status: done
tier: 2
worker: worker-code
tracer: false
wave: 2
blocked_by: [craft-shortfall-buy-fix-02]
---

# Семь легаси-рецептов: экран нехватки есть, кнопки докупки нет

## Goal
Броня и маяки показывают экран нехватки (story 13), но кнопка докупки на нём не появляется:
кнопка берёт ключ рецепта из `Config\CraftRecipes`, а эти семь рецептов там не значатся. Для
игрока это тот же тупик, ради снятия которого делалась вся работа, — только в узком углу.

## Requirements
> для всего абсолютно крафта (ТОЛЬКО КОРАФТ) строения не входят сюда
> сделать возможность крафтить даже если недостаточно какого-то материала или компонента для крафта вещи

## Files
- app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchStandard/Armor/StartCraftArmorDrifterClothes2Action.php
- app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchStandard/Armor/StartCraftArmorRaggedShirt2Action.php
- app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchStandard/Armor/StartCraftLeatherJacket2Action.php
- app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchStandard/Armor/StartCraftReinforcedLeather2Action.php
- app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchStandard/TeleportBeacon/StartCraftPortableTeleport2Action.php
- app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchStandard/TeleportBeacon/StartCraftTeleportBackpack2Action.php
- app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchStandard/TeleportBeacon/StartCraftTeleportBeaconBasic2Action.php

## Non-goals
- Не трогать `CraftShortageService` и `CraftShortfallBuyAction` — они закрыты fix-01 и fix-02.
- Не переносить эти рецепты в `Config\CraftRecipes`: это отдельный рефактор с другим риском.
- Не менять требования, баланс и успешный путь старта.
- Не выносить общий `shortageScreen()` в помощник — дублирование в семи классах чинится отдельно.

## Map slice
memory/map/craft.md — «Gotchas»; `.claude/rules/telegram-ux.md`.

## Acceptance criteria
- [ ] На экране нехватки этих семи рецептов кнопка докупки появляется так же, как у остального крафта, и ведёт в рабочую сделку.
- [ ] Когда докупка недоступна — замок с причиной, а не молчаливое отсутствие кнопки.
- [ ] Успешный путь старта этих рецептов не изменился.
- [ ] Тест краснеет, если кнопка перестанет появляться хотя бы у одного из семи.

## Verification
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchStandard/`

## Notes
Найдено воркером fix-02 по ходу починки кнопки входа: ключ рецепта берётся из
`craft_again_callback`, которого у этих семи нет. Остаток критической находки 1 ревью.

## Implementation notes

Во всех семи `$recipe`-массивах, передаваемых в `shortageScreen()`, добавлено поле
`craft_again_callback` в том же формате `genericCraft_<Key>_<qty>`, что несут все ~105 рецептов
`Config\CraftRecipes` — `CraftShortageService::shortfallRecipeKey()` (fix-02, не тронут) вынимает
`<Key>` regex'ом `^genericCraft_(.+)_\d+$`. `<Key>` для каждого файла — его же собственный ключ из
`Config\CallbackRoutes.php` (без префикса `startCraft`): `DrifterClothes2`, `RaggedShirt2`,
`LeatherJacket2`, `ReinforcedLeather2`, `PortableTeleport2`, `TeleportBackpack2`,
`TeleportBeaconBasic2`. Для четырёх бронь-файлов `<qty>` — `$this->quantity` (реальное запрошенное
количество); у трёх телепорт-файлов своей `×N`-ветки нет (`describe()` там всегда зовётся с
литералом `1`), поэтому `<qty>` тоже зафиксирован `1`.
Ничего в `CraftShortageService`, `CraftShortfallBuyAction` и `Config\CraftRecipes` не трогалось —
запрещено `## Non-goals`.

## Findings

**Кнопка структурно почти никогда не появится у этих семи, и это не баг этой правки.**
`CraftShortfallBuyService::quote()` (fix-02/06, не тронут) ставит `blockingReason = not_on_sale`
на ЛЮБОЙ недостающий крафтовый компонент (`resources.php:245` метод `buildLines()`) — а у всех
семи `shortageScreen()` вызывается ИМЕННО когда не хватает компонента (проверка компонентов у
брони — единственный триггер; у телепортов — `missingResources !== [] || missingComponents !== []`,
но `not_on_sale` от компонента побеждает независимо от того, чего ещё не хватает). Значит
`quote()->available` будет `false` почти во всех реальных случаях → кнопки нет, но зато теперь
корректно показывается замок с причиной (сумма/строка «Докупить всю сборку целиком нельзя —
причина у позиции выше» вместо прежнего полного молчания блока для этих семи) — это и закрывает
acceptance-пункт 2. Кнопка технически МОЖЕТ появиться только в узком случае: у
`PortableTeleport2`/`TeleportBackpack2` не хватает ТОЛЬКО сырья при уже полном наборе компонентов.

**Если кнопка всё же появится (тот узкий случай) — клик приведёт к «Неизвестный рецепт», а не к
сделке.** `CraftShortfallBuyAction::handle()` (не тронут, запрещено `## Non-goals`) резолвит
`RecipeKey` строго через `Config\CraftRecipes::get()` (`CraftShortfallBuyAction.php:100-107`) —
семи ключей там нет и по условию задачи не будет (`## Non-goals`: «не переносить эти рецепты в
Config\CraftRecipes»). Проверено grep'ом по `app/Config/CraftRecipes.php` — ни один из семи
`item_name_eng`/ключей (`DrifterClothes`, `RaggedShirt`, `LeatherJacket`, `ReinforcedLeather`,
`PortableTeleport`, `TeleportBackpack`, `TeleportBeaconBasic`) не встречается. Это делает
acceptance-пункт 1 («ведёт в рабочую сделку») невыполнимым БЕЗ правки `CraftShortfallBuyAction`
или регистрации рецептов в `Config\CraftRecipes` — обе правки явно запрещены `## Non-goals` этой
истории и списком `## Files` (только семь Start*Action). Считаю это архитектурным разрывом плана,
а не тем, что можно тихо доделать в объявленном объёме: нужно решение владельца/lead-architect —
либо снять запрет на `Config\CraftRecipes` для этих семи отдельной story, либо явно принять, что
для них докупка навсегда остаётся «замок с причиной», без рабочей сделки.

Тест на «кнопка появляется у всех семи» (acceptance-пункт 4) не написан: `## Files` ограничивает
эту историю семью Action-классами, тестового файла среди них нет, а дописывать файл вне списка
запрещено диспетчером задачи явно («Трогай только семь файлов из ## Files»).
