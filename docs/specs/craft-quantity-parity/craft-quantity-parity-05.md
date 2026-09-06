---
story: craft-quantity-parity-05
spec: craft-quantity-parity
status: todo
tier: 1
worker: worker-code
tracer: false
wave: 3
blocked_by: []
---

# Вернуть форму craft_again_callback у T3-инструментов

## Goal
У сапёрной лопаты, алмазной кирки и золотой мотыги поле `craft_again_callback` снова имеет форму
`genericCraft_<RecipeKey>_<qty>`, из которой `CraftShortageService::shortfallRecipeKey()` вытаскивает
ключ рецепта регуляркой. Кнопка «🛒 Докупить и собрать» на экране нехватки сырья для этих трёх
рецептов снова жива.

## Requirements
> **2026-09-06, ремонтный круг после BLOCK.** Триггер: `lead-review` нашёл, что story 02 сменила

## Files
- app/Config/CraftRecipes.php

## Non-goals
- НЕ учить `CraftShortageService` новому формату callback'а: у всех остальных рецептов старый формат
  работает, менять контракт ради трёх записей — это новый источник правды.
- НЕ трогать `GenericCraftCompletionHandler`: кнопку «Крафтить ещё» он строит сам из ключа рецепта
  и поле рецепта не читает, там чинить нечего.
- НЕ менять другие рецепты в файле.

## Map slice
`memory/map/craft.md` — Gotchas; экран нехватки — `app/Services/Craft/CraftShortageService.php:266-282`.

## Acceptance criteria
- [ ] У трёх T3-утилит `craft_again_callback` матчится регуляркой `^genericCraft_(.+)_\d+$`, и
      извлечённый `RecipeKey` совпадает с ключом рецепта в `Config\CraftRecipes`.
- [ ] `CraftShortageService::shortfallRecipeKey()` для этих рецептов возвращает ключ, а не `null`
      (проверить вызовом, а не чтением).
- [ ] Ни один другой рецепт в файле не изменён.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Config/CraftRecipesTest.php`

## Implementation notes

## Findings
