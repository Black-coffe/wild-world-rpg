---
story: drone-discoverability-03
spec: drone-discoverability
status: done
tier: 1
worker: worker-code
tracer: false
wave: 1
blocked_by: []
---

# Ангар открывается владельцу техники без мастерской

## Goal
`HangarAction` перестаёт отдавать lock-state игроку, у которого техника УЖЕ на руках
(дрон куплен у каравана, получен иначе). Если `workshopLevel <= 0`, но у чара есть хотя бы
один предмет типа `drones` или `robots` с `quantity > 0` — рендерим обычный экран ангара.
Мастерской нет только у того, у кого и техники нет.

## Requirements
> **Купленный у каравана дрон = запертый ангар.** Рецепт `DroneScout` требует
> `RoboticsWorkshop` L1, но дрона можно купить у каравана
> (`CaravanBuyDroneAction`, offer_type `drone_scout`). У такого игрока
> `HangarAction` отдаёт lock-state «🔒 Ангар закрыт» — главный путь заперт при
> наличии рабочей техники в руках.

## Files
- app/Controllers/Telegram/Commands/Actions/Camp/HangarAction.php

## Non-goals
- НЕ снимать гейт мастерской с КРАФТА дронов — рецепт `Config\CraftRecipes['DroneScout']`
  не трогаем вовсе, гейт остаётся.
- НЕ менять `dronesBlock()`-гейты по уровню мастерской (`gate` 1–4): они честно говорят,
  что можно скрафтить. При `workshopLevel = 0` они просто показывают гейт как невыполненный.
- НЕ переписывать текст lock-состояния для тех, у кого техники правда нет.
- НЕ добавлять новых кнопок в ангар.

## Map slice
memory/map/ — секция про базу/постройки.

## Acceptance criteria
- [ ] Чар без мастерской и БЕЗ техники → прежний lock-экран «🔒 Ангар закрыт» (не изменился).
- [ ] Чар без мастерской, но с `DroneScout` qty>0 → полноценный экран ангара с кнопкой «🚁 Разведчик».
- [ ] Заголовок экрана не врёт про уровень: при `workshopLevel = 0` строка про мастерскую
      сообщает, что мастерской нет, а не «уровень 0».
- [ ] Проверка владения — один запрос по `crafted_items.type IN ('drones','robots')`,
      а не запрос на каждый тип.
- [ ] Экран остаётся самодостаточным в тексте (media-off).

## Verification
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress app/Controllers/Telegram/Commands/Actions/Camp/HangarAction.php`

## Implementation notes
- `handle()`: при `workshopLevel <= 0` теперь заходит в `renderLocked` только если новый
  `hasAutomationGear($charId)` тоже вернул false (один запрос `crafted_items.type IN (drones,robots)`
  → `findColumn('id')`, затем один `countAllResults()` по `crafted_items_log` с `whereIn` + `quantity > 0`).
- `renderHangar()` принимает `workshopLevel=0` как валидный кейс: заголовок теперь ветвится
  на честную строку «Мастерской робототехники нет — построй: ...» вместо «уровень 0»;
  `dronesBlock()`/гейты не трогались, как и требовал non-goal.
- phpstan L9 по файлу зелёный.
