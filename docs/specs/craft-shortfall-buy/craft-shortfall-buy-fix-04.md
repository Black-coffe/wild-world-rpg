---
story: craft-shortfall-buy-fix-04
spec: craft-shortfall-buy
status: todo
tier: 3
worker: worker-code
tracer: false
wave: 1
blocked_by: []
---

# Две брони ищут компоненты по русским ключам и всегда видят ноль

## Goal
`StartCraftArmorDrifterClothes2Action` и `StartCraftArmorRaggedShirt2Action` передают компоненты
под русскими именами, а сервис ищет их по английским. На одном экране список говорит «есть 3», а
блок докупки ниже — «нужно 8, есть 0».

## Requirements
> для всего абсолютно крафта (ТОЛЬКО КОРАФТ) строения не входят сюда
> сделать возможность крафтить даже если недостаточно какого-то материала или компонента для крафта вещи

## Files
- app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchStandard/Armor/StartCraftArmorDrifterClothes2Action.php
- app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchStandard/Armor/StartCraftArmorRaggedShirt2Action.php

## Non-goals
- Не трогать две другие брони и телепорты — там ключи уже верные.
- Не менять требования рецептов и баланс.
- Не переносить общий `shortageScreen()` в помощник: дублирование метода в семи классах — отдельная задача.

## Map slice
memory/map/craft.md; `.claude/rules/db-schema.md` — карта конвенций именования.

## Acceptance criteria
- [ ] Оба класса передают компоненты под теми же ключами, по которым сервис их ищет.
- [ ] Наличие компонента в блоке докупки совпадает с наличием в списке требований выше на том же экране.
- [ ] Успешный путь старта этих рецептов не изменился.
- [ ] `phpstan` уровня 9 зелёный на обоих файлах.

## Verification
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchStandard/Armor/`

## Notes
Полный разбор находок — `docs/specs/craft-shortfall-buy/review-findings.md`. Читай его, прежде чем править. Крупная 7. Известный класс: «имя из БД не равно ключу конфига».

## Implementation notes

## Findings
