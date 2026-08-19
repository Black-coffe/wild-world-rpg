---
story: storage-craft-insurance-06
spec: storage-craft-insurance
status: todo
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
- [ ] Ремонт на базе использует сырьё со склада; вне базы — прежнее поведение.
- [ ] Апгрейд постройки на базе использует сырьё со склада (постройка на базе и стоит — этот случай
      самый очевидный и сегодня самый обидный).
- [ ] Списание в тех же транзакционных границах, что были до правки.
- [ ] Отказ по нехватке называет суммарный остаток, а не карманный — иначе число в отказе не сойдётся
      с тем, что игрок видит на складе.
- [ ] В отчёте story перечислены все прочие места, где греп нашёл тот же паттерн и которые НЕ тронуты.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Player/PoolAdoptionRepairUpgradeTest.php`

## Implementation notes

## Findings
