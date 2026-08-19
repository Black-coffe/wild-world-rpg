---
story: storage-craft-insurance-06
spec: storage-craft-insurance
status: done
tier: 3
worker: worker-code
tracer: false
wave: 2
blocked_by: [storage-craft-insurance-01]
---

# Ремонт и стройка — тот же класс бага, та же правка

## Goal
Ремонт предметов и апгрейд построек содержат независимые копии той же «только рюкзак» логики, что и
крафт. Они переводятся на `ResourcePoolService`, чтобы правило «на базе рюкзак и склад — один пул»
не оказалось верным ровно для одной механики из четырёх.

## Requirements
> #bug [19.08.2026 05:13] Анжела: 1. Крафт ингредиенты доступны только из рюкзака, даже когда я стою на складе, где их тысячи😳.

## Files
- app/Services/Player/BuildingUpgrade/BuildingUpgradeValidator.php
- app/Services/Player/BuildingUpgrade/BuildingUpgradeApplier.php
- app/Controllers/Telegram/Commands/Actions/Craft/Repair/RepairCraftedItemAction.php
- app/Controllers/Telegram/Commands/Actions/Craft/Repair/NpcRepairAction.php
- tests/unit/Player/PoolAdoptionRepairUpgradeTest.php

## Non-goals
- НЕ менять стоимость ремонта, формулы износа, требования апгрейда — только источник сырья.
- НЕ переводить на пул стройку с нуля и прочие механики, которых нет в списке файлов: если греп
  найдёт ещё один экземпляр того же класса бага, он идёт в отчёт story, а не в этот диff (Law 3).
- НЕ выносить общий базовый класс для трёх вызывающих: у них разный контекст и разный UX отказа,
  общий предок здесь — абстракция ради симметрии.

## Map slice
`memory/map/craft.md` (ремонт), `memory/map/bases.md` (постройки, апгрейд).

## Acceptance criteria
- [x] Ремонт на базе использует сырьё со склада; вне базы — прежнее поведение.
- [x] Апгрейд постройки на базе использует сырьё со склада (постройка на базе и стоит — этот случай
      самый очевидный и сегодня самый обидный).
- [x] Списание в тех же транзакционных границах, что были до правки.
- [x] Отказ по нехватке называет суммарный остаток, а не карманный — иначе число в отказе не сойдётся
      с тем, что игрок видит на складе.
- [x] В отчёте story перечислены все прочие места, где греп нашёл тот же паттерн и которые НЕ тронуты.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Player/PoolAdoptionRepairUpgradeTest.php`
→ зелёно (11 тестов). `vendor/bin/phpstan analyse --memory-limit=512M --no-progress` (полный прогон
проекта, paths=app) → No errors.

## Implementation notes

- `BuildingUpgradeValidator::validate()` шаг 8 (проверка ресурсов): `$this->characterResourceModel->where(...)->first()`
  заменён на `$this->resourcePool->available($charId, $resourceId)`. `NEEDS_CONTEXT` был из-за того,
  что `BuildingUpgradeApplier` (реальное списание) не было в исходном `## Files` — team-lead добавил
  файл в scope, дальше работа пошла без блокеров.
- `BuildingUpgradeApplier::apply()`: цикл списания вынесен в приватный `deductResources()` (тот же
  приём, что и `GenericCraftActionStart::subtractResources()` из story -05) — `$this->characterResourceModel->decreaseResources()`
  заменён на `$this->resourcePool->consume()` внутри `try/catch(\RuntimeException)` с логированием
  при гонке, без прерывания остального списания (поведение при отсутствии `resRow` — молчаливый skip —
  сохранено как было). Своей транзакции не было и не появилось — `apply()` как до правки не открывал
  `transStart()`.
- `RepairCraftedItemAction`: `checkResourceAvailability()` — то же самое, `available*ByName()` вместо
  прямого `where(id_characters,id_resources)->first()`. Деduct-цикл в `confirmRepair()` вынесен в
  приватный `deductResources()` для тестируемости (mirror repair↔craft паттерна), список `try/catch`
  идентичен `GenericCraftActionStart`.
- `NpcRepairAction.php` (из `## Files`) — **не тронут**: у него объявлено, но нигде не используется
  свойство `CharacterResourceModel $characterResourceModel` (мёртвый код, был мёртв уже до этой
  story). Действие полностью gold-only — ни чтения, ни списания `character_resources` нет, того
  класса бага там просто не существует.
- Оба сервиса (`Validator`, `Applier`) потеряли конструкторный параметр `CharacterResourceModel` —
  стал `only-written` после перехода на пул (phpstan level 9 гейт: `property.onlyWritten`), убран
  вместе со свойством, а не подавлен. Проверено grep'ом — ни один вызывающий не создавал эти сервисы
  с явными аргументами (только `new BuildingUpgradeValidator()` / `new BuildingUpgradeApplier()` без
  параметров в `UpgradeBuildingAction`), удаление параметра не ломает вызывающих.
- `phpstan-baseline.neon` подрезан на 2 записи, которые перестали воспроизводиться моей правкой
  (не подавление новых ошибок, а честное сокращение из-за исчезновения старых мест):
  `cast.int count:3 → удалена` для `BuildingUpgradeApplier.php` (все mixed-касты заменены на
  `is_numeric()`-narrowing), `offsetAccess.nonOffsetAccessible 'quantity' count:1 → удалена` для
  `BuildingUpgradeValidator.php` (доступ к `$charRes['quantity']` исчез вместе со старым кодом).
  Полный прогон `phpstan analyse` (без явного списка путей — paths=app из конфига) зелёный.
- Тест `PoolAdoptionRepairUpgradeTest.php` — 11 методов, паттерн `newInstanceWithoutConstructor` +
  рефлексия на приватные `resourcePool`-свойства (для Repair/Applier, копия приёма из
  `tests/unit/Craft/CraftPoolConsumptionTest.php`), для `Validator` — прямая конструкторная
  инъекция двойников моделей (его конструктор публичный и nullable-default). Двойники моделей
  (`CharacterBuildingModel`, `BuildingModel`, `ResourceModel`) переопределяют `where()`/`first()`/`find()`
  без похода в БД. `buildingInfo` в тестах намеренно без ключа `name_en`, чтобы обойти
  `GameSettingsReaderTrait::gsFloat()` (ADR-043 множитель) — не относится к предмету story
  (Non-goals: не менять формулы), не хотелось тянуть ещё один DB-двойник ради ветки, которую эта
  story не трогает.

## Findings

Отчёт по грепу: другие места того же класса бага («читает/списывает `character_resources` только из
рюкзака, не зная о пуле склада»), которые **НЕ тронуты** в этой story (не входили в `## Files`,
Law 3):

**Явные кандидаты того же класса — прямой `->decreaseResources()` в обход пула:**
1. `app/Controllers/Telegram/Commands/Actions/Camp/Buildings/RepairBuildingAction.php` (ADR-041,
   мгновенный ремонт **оборонной структуры**, отдельный от ремонта инструмента) — `computePlan()`
   читает остаток через `where('id_characters',...)->where('id_resources',...)->first()`,
   `confirmRepair()` списывает через `characterResourceModel->decreaseResources()`. Третий
   независимый экземпляр «только рюкзак» рядом с двумя, которые эта story уже закрыла.
2. `app/Controllers/Telegram/Commands/Actions/Camp/Buildings/Robots/RobotRepairConfirmAction.php`
   (ремонт робота) — читает `char_resources` через `where(...)->first()`, списывает через
   `->update($id, ['quantity' => max(0, $have-$need)])` внутри собственной `$db->transStart()`.
   Четвёртый независимый экземпляр, да ещё и в явной транзакции — если его когда-нибудь переведут
   на `ResourcePoolService::consume()`, транзакционная граница у него уже есть и её надо сохранить.

**Легаси-экраны крафта (WorkbenchStandard) — тот же паттерн чтения, но другой домен (не
repair/upgrade), отдельные от `GenericCraftActionStart`, который story -05 уже перевела на пул:**
`WeaponPipeGun2Action.php`, `WeaponWiredBat2Action.php`, `WeaponCrossbowMk1Action.php`,
`WeaponMetalSpear2Action.php`, `StartCraftPortableTeleport2Action.php`,
`StartCraftTeleportBackpack2Action.php`, `StartCraftTeleportBeaconBasic2Action.php`,
`StartCraftReinforcedLeather2Action.php`, `StartCraftLeatherJacket2Action.php`,
`ArmorLeatherJacket2Action.php`, `ArmorReinforcedLeather2Action.php` (все в
`app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchStandard/`) — читают `id_resources` напрямую
для гейта UI; ни у одного из них не нашлось прямого `decreaseResources()`-вызова в этих файлах
(грепнуто отдельно), похоже на display-only гейт с реальным списанием где-то ещё — не проверялось
глубже, чтобы не выходить за пределы story.

**Не того же класса (спенд идёт из рюкзака намеренно, не кандидат на пул):**
`BaseStorageDepositAction.php` (списывает из рюкзака при *переносе* в склад — по определению должно
брать только из рюкзака), `Games/ShuffleResourcesAction.php` (мини-игра ADR-093 «перемешать» —
оперирует именно тем, что ты несёшь), `Drone/CargoDroneSendAction.php` /
`Drone/CargoDroneAutoSendAction.php` (дрон отправляет то, что у тебя в рюкзаке, а не абстрактную
стоимость).
