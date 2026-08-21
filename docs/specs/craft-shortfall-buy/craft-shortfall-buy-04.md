---
story: craft-shortfall-buy-04
spec: craft-shortfall-buy
status: done
tier: 1
worker: worker-code
tracer: false
wave: 2
blocked_by: [craft-shortfall-buy-01]
---

# Карточки Medical (8) — на помощника

## Goal
Восемь карточек медицины переводятся на помощника: пул вместо рюкзака и выход из тупика.

## Requirements
> для всего абсолютно крафта (ТОЛЬКО КОРАФТ) строения не входят сюда
> Недостаточно ресурсов, чтобы скрафтить хотя бы 1 шт.

## Files
- app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchGeneral/Medical/AntisepticCraft1Action.php
- app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchGeneral/Medical/BandageCraft1Action.php
- app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchGeneral/Medical/BasicMedKitCraft1Action.php
- app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchGeneral/Medical/PainReliefPowerCraft1Action.php
- app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchGeneral/Medical/RegeneratorCraft1Action.php
- app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchGeneral/Medical/SedativeCraft1Action.php
- app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchGeneral/Medical/StimulatorCraft1Action.php
- app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchGeneral/Medical/StrengthElixirCraft1Action.php

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
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchGeneral/Medical/`

## Implementation notes

- Все 8 карточек: подключён `App\Services\Craft\CraftCardHelper` (свойство + конструктор), вызов
  `available()` заменил локальный `checkResourcesAvailability()` по образцу `LumberjackAxeCraft1Action`
  (коммит `b9365c7d`), в ветке «нельзя собрать ни одной штуки» добавлен ряд с `fallbackButton('<RecipeKey>')`
  перед рядом «⬅️ Назад». Ключи рецептов взяты из существующих строк `callback_data`: Antiseptic,
  Bandage, BasicMedKit, PainReliefPower, Regenerator, Sedative, Stimulator, StrengthElixir.
- 7 карточек с плоским `requiredResources` — старый приватный метод `checkResourcesAvailability()`
  удалён как мёртвый код (как в образце).
- `BasicMedKitCraft1Action` — особый случай: требования состоят из `resources` (сырьё) и
  `crafted_items` (готовые компоненты, напр. Bandage). Через помощник переведена только сырьевая
  часть (`craftCardHelper->available($characterId, $requiredResources['resources'])`); часть
  `crafted_items` считается прежним путём через `CraftedItemsLogModel` — CraftCardHelper компоненты
  не поддерживает, и это вне Non-goals story. Метод `checkResourcesAvailability()` в этом файле
  оставлен (не удалён), т.к. по-прежнему нужен для сборки компонентной части.
- `characterResourceModel`/`resourceModel`, оставшиеся объявленными в конструкторах, но нигде
  больше не используемые (кроме `BasicMedKitCraft1Action`, где `resourceModel`/`characterResourceModel`
  теперь не используются вообще) — не удалял, чтобы не расширять диф сверх story (то же решение,
  что в образце `craft-shortfall-buy-01`).
- Формулировки текстов «недостаточно» и состав остальных кнопок не менялись (Non-goals).

## Findings

`phpstan-baseline.neon` содержит 14 записей (по 2 на файл × 7 из 8 карточек — Antiseptic, Bandage,
PainReliefPower, Regenerator, Sedative, Stimulator, StrengthElixir; `BasicMedKitCraft1Action` не
затронут, у неё метод остался) для `missingType.iterableValue` на удалённом методе
`checkResourcesAvailability()`. После удаления метода эти записи стали `ignore.unmatched` и валят
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchGeneral/Medical/`
(14 ошибок). По инструкции координатора baseline не трогал — правку нужно свести отдельно (строки
в текущем `phpstan-baseline.neon`: 3264/3270 Antiseptic, 3342/3348 Bandage, 3522/3528
PainReliefPower, 3600/3606 Regenerator, 3678/3684 Sedative, 3756/3762 Stimulator, 3834/3840
StrengthElixir).
