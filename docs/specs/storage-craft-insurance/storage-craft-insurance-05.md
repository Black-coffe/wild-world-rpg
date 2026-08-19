---
story: storage-craft-insurance-05
spec: storage-craft-insurance
status: todo
tier: 3
worker: worker-code
tracer: false
wave: 2
blocked_by: [storage-craft-insurance-01]
---

# Крафт считает и тратит из пула

## Goal
`GenericCraftActionStart` проверяет достаточность сырья и списывает его через `ResourcePoolService`.
Игрок, стоящий на базе, где на складе тысячи ресурсов, запускает крафт, не перекладывая ничего руками.
Экран нехватки перестаёт советовать «добыть/купить» то, что уже лежит на складе.

## Requirements
> #bug [19.08.2026 05:13] Анжела: 1. Крафт ингредиенты доступны только из рюкзака, даже когда я стою на складе, где их тысячи😳.

## Files
- app/Controllers/Telegram/Commands/Actions/Craft/GenericCraftActionStart.php
- app/Services/Craft/CraftShortageService.php
- tests/unit/Craft/CraftPoolConsumptionTest.php

## Non-goals
- НЕ трогать `GenericCraftCompletionHandler` — сырьё списывается на старте, завершение его не читает.
- НЕ трогать 40+ превью-экранов `*Craft1Action.php`: они показывают выбор количества и остаток не
  проверяют. Соблазн «заодно обновить все» — это диff на сорок файлов ради нуля поведения.
- НЕ менять рецепты, цены, длительность и `RequiredResourcesParser`.
- НЕ ломать инвариант «крафт не печатает золото» — проверка стоимости не меняется, меняется источник сырья.

## Map slice
`memory/map/craft.md` (крафт, нехватка), `memory/map/bases.md` (склад).

## Acceptance criteria
- [ ] Проверка достаточности и списание идут через `ResourcePoolService`, а не напрямую по
      `CharacterResourceModel` — обе точки, не одна из двух.
- [ ] На базе крафт запускается, когда сырья хватает суммарно, даже если в рюкзаке пусто.
- [ ] Списание внутри существующей транзакции старта; при откате не уходит ни рюкзак, ни склад.
- [ ] Вне базы поведение прежнее — только рюкзак.
- [ ] Экран нехватки, когда игрок не на базе, а недостающее лежит на складе, говорит об этом прямо:
      «столько-то ждёт на складе базы» — вместо совета добывать заново.
- [ ] Тексты самодостаточны (MEDIA-OFF), Markdown с парными `*`.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Craft/CraftPoolConsumptionTest.php`

## Implementation notes

## Findings
