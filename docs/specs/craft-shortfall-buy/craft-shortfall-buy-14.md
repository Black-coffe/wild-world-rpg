---
story: craft-shortfall-buy-14
spec: craft-shortfall-buy
status: done
tier: 1
worker: worker-code
tracer: false
wave: 4
blocked_by: [craft-shortfall-buy-01]
---

# Карточки брони и оружия второго верстака (8 штук) — на помощника

## Goal
Восемь карточек второго верстака — четыре брони и четыре оружия — устроены точно так же, как
двадцать семь карточек общего верстака: свой список требований, свой расчёт количества, и полная
пустота вместо кнопок, когда собрать нельзя ни одной штуки. Они переводятся на общий помощник,
и охват заказа «для всего абсолютно крафта» закрывается целиком.

## Requirements
> для всего абсолютно крафта (ТОЛЬКО КОРАФТ) строения не входят сюда
> Недостаточно ресурсов, чтобы скрафтить хотя бы 1 шт.

## Files
- app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchStandard/Armor/ArmorDrifterClothes2Action.php
- app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchStandard/Armor/ArmorLeatherJacket2Action.php
- app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchStandard/Armor/ArmorRaggedShirt2Action.php
- app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchStandard/Armor/ArmorReinforcedLeather2Action.php
- app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchStandard/Weapons/WeaponCrossbowMk1Action.php
- app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchStandard/Weapons/WeaponMetalSpear2Action.php
- app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchStandard/Weapons/WeaponPipeGun2Action.php
- app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchStandard/Weapons/WeaponWiredBat2Action.php
- tests/unit/Craft/CraftCardExitGuardTest.php

## Non-goals
- Не переводить эти рецепты на общую точку старта крафта: у брони и маяков свои стартовые классы, и это отдельная работа (story 13).
- Не менять требования, баланс и существующие тексты карточек.
- Не трогать `phpstan-baseline.neon`: файл общий, осиротевшие записи сводит Queen отдельным коммитом.
- Не расширять помощника на требования из готовых компонентов — он считает сырьё.

## Map slice
memory/map/craft.md — «Entry points», «Gotchas» (карточки считают только рюкзак, копии не идентичны).

## Acceptance criteria
- [ ] Каждая из восьми карточек считает доступное через `CraftCardHelper::available()`, а не напрямую по рюкзаку.
- [ ] Каждая при «нельзя собрать ни одной штуки» показывает кнопку-выход из помощника.
- [ ] Кнопка-выход не появляется, когда доступна хотя бы одна штука.
- [ ] Ключ рецепта для выхода взят из уже существующей строки `callback_data` в самом файле, а не придуман.
- [ ] Замок `CraftCardExitGuardTest` расширен с `WorkbenchGeneral` на весь `Craft/` и зелёный: пометка о заведомо непокрытых карточках из его док-блока снимается, потому что покрывать больше нечего.
- [ ] Существующие тексты и остальные кнопки карточек не изменились.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Craft/`

## Notes
Story рождена находкой воркера story 05: он проверил свой замок без ограничения по папке и
поимённо назвал восемь карточек, которых не было ни в одном `## Files`. Разведка при планировании
считала карточным слоем только `WorkbenchGeneral`.

## Implementation notes
- 6 из 8 карточек (`ArmorLeatherJacket2`, `ArmorReinforcedLeather2`, `WeaponCrossbowMk1`, `WeaponMetalSpear2`, `WeaponPipeGun2`, `WeaponWiredBat2`) реально читали сырьё напрямую через `CharacterResourceModel` — их прямой запрос заменён на `CraftCardHelper::available()`. 2 карточки (`ArmorDrifterClothes2`, `ArmorRaggedShirt2`) сырьё не считали вообще (только золото + готовые компоненты через `CraftedItemsLogModel`) — им добавлена только кнопка-выход, вызывать `available()` было не для чего.
- Ключ для `fallbackButton()`: у 4 карточек оружия (`WeaponCrossbowMk1`, `WeaponMetalSpear2`, `WeaponPipeGun2`, `WeaponWiredBat2`) callback_data кнопок количества уже `genericCraft_<Key>_{q}` — использован сгенерированный помощником callback как есть. У 4 карточек брони (`ArmorDrifterClothes2`, `ArmorLeatherJacket2`, `ArmorRaggedShirt2`, `ArmorReinforcedLeather2`) callback_data — `startCraft<Key>2_{q}` (свой стартовый класс `StartCraft*2Action`), поэтому `callback_data` у результата `fallbackButton()` вручную переписан на `startCraft<Key>2_1`, чтобы кнопка-выход вела туда же, куда обычная кнопка «Крафт 1шт».
- `ArmorReinforcedLeather2Action` и все 4 карточки оружия имеют ДВЕ разные "недостаточно" ветки (ранний `if (!empty($insufficientDetails))` для золота/силы/уровня/предметов/сырья и поздний `if ($maxCraftable < 1)`) — кнопка добавлена в обе.
- `CraftCardExitGuardTest`: путь сканирования расширен с `Craft/WorkbenchGeneral` на весь `Craft/`; оговорка в док-блоке про непокрытые 8 карточек снята. Красноту по имени проверил вручную (временно переименовал `fallbackButton(` в одном файле, тест указал файл по имени, вернул обратно).
- `phpstan-baseline.neon` не трогал.

## Findings
