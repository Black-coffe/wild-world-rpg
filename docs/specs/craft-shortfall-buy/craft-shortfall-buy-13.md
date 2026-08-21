---
story: craft-shortfall-buy-13
spec: craft-shortfall-buy
status: done
tier: 3
worker: worker-code
tracer: false
wave: 4
blocked_by: [craft-shortfall-buy-08]
---

# Броня и маяки: семь рецептов вне общей цепочки получают экран нехватки

## Goal
Семь рецептов (четыре брони и три маяка/телепорта) стартуют крафт собственным кодом мимо общей
точки старта, и экрана нехватки у них нет вовсе. Заказ говорит «для всего абсолютно крафта»,
поэтому они получают тот же экран и ту же докупку. Владелец оставил story в объёме
(2026-08-21), то есть охват заказа закрывается полностью и оговорок про неполноту не нужно.

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
- Не переписывать эти семь рецептов на общую точку старта — это отдельный рефактор с другим риском.
- Не менять их баланс, требования и тексты сверх подключения экрана нехватки.
- Не дублировать логику расчёта: только вызов существующих сервисов.

## Map slice
memory/map/craft.md — «Gotchas» (эти файлы стартуют крафт своим путём).

## Acceptance criteria
- [ ] При нехватке каждый из семи рецептов показывает тот же экран нехватки, что и остальной крафт.
- [ ] Докупка на этих рецептах ведёт себя идентично общему пути, включая отказы и lock-состояния.
- [ ] Существующий успешный путь старта этих рецептов не изменился.
- [ ] `phpstan` уровня 9 зелёный на всех семи файлах.

## Verification
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchStandard/`

## Implementation notes

- Все семь файлов получили private-метод `shortageScreen()` (не общий класс — story
  запрещает трогать `CraftShortageService`/`GenericCraftActionStart`, каждый файл несёт
  свою копию, но она только собирает вход и зовёт `CraftShortageService::describe()` —
  расчёт нехватки/докупки не дублируется).
- Армор (4 файла): проверки компонентов/сырья переписаны с «return на первой нехватке»
  на «собрать все нехватки → один экран», иначе экран показал бы только одну позицию.
  Проверка золота осталась прежней (`sendError`) — story ограничивает охват материалом/
  компонентом, не золотом.
- `StartCraftPortableTeleport2Action`: `missingRequirements()` (список строк, включал
  золото) заменён на `missingResources()`+`missingComponents()` (типизированные массивы,
  без золота) + `componentsByEng()`. Золото по-прежнему гейтится атомарно ниже через
  `decreaseGold()` — поведение при нехватке золота не изменилось, просто перестало быть
  частью combined-list. Удалён осиротевший `goldOf()` (было unused после рефакторинга).
- `StartCraftTeleportBackpack2Action` / `StartCraftTeleportBeaconBasic2Action`: эти два
  класса и раньше НЕ проверяли сырьё (только компоненты) — новая проверка сырья не
  добавлена (добавление было бы новым гейтом сверх подключения экрана, вне Non-goals).
  `missingComponents()` сменил тип возврата с `list<string>` на типизированный массив;
  добавлен `componentsByEng()` для полной карты компонентов, нужной
  `CraftShortfallBuyService::quote()`.
- info_callback для экрана нехватки/кнопки «Назад» взят из `Config\CallbackRoutes` (шаг
  информации о рецепте): `armorDrifterClothes`, `armorRaggedShirt`, `armorLeatherJacket`,
  `armorReinforcedLeather`, `portableTeleport2`, `teleportBackpack2`, `teleportBeaconBasic2`.
- Верификация: `vendor/bin/phpstan analyse --memory-limit=512M --no-progress app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchStandard/` → 0 ошибок.
- НЕ проверено вживую (Tier-3): изменения видимые в Telegram (новый экран вместо текста)
  требуют MCP Chrome + Telegram Web смоука — не выполнялся в этой сессии (не запрашивался).
- Tips/guide-вердикт: изменение расширяет уже существующий UX паттерн (экран нехватки)
  на ещё 7 рецептов — не новая механика для игрока, отдельного tip/guide-раздела не
  требует (существующий раздел про докупку у торговца уже покрывает этот путь).

## Findings
