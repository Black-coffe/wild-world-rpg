---
story: craft-quantity-parity-02
spec: craft-quantity-parity
status: done
tier: 1
worker: worker-code
tracer: false
wave: 1
blocked_by: []
---

# «Скрафтить ещё» у T3-инструментов ведёт на выбор количества

## Goal
После завершения крафта сапёрной лопаты, алмазной кирки и золотой мотыги кнопка «скрафтить ещё» не
запускает молча одну штуку, а возвращает игрока на карточку рецепта, где он выбирает количество.
Сейчас `craft_again_callback` у этих рецептов зашит на `genericCraft_<Key>_1`.

## Requirements
> [06.09.2026 12:40] Arseny: Не баг конечно, но не удобно: почему саперные лопаты, алмазные кирки и тд нельзя крафтить по несколько штук, как обычные инструменты?

## Files
- app/Config/CraftRecipes.php

## Non-goals
- НЕ трогать другие рецепты в этом конфиге: только T3-утилиты, у которых `craft_again_callback` оканчивается на `_1`.
- НЕ менять состав рецептов, стоимость, время и требования — правка только в поле callback'а.
- НЕ заводить новый роут: карточка уже висит на существующем префиксном роуте `craftPreviewT3Utility`.

## Map slice
`memory/map/craft.md` — Entry points; `memory/map/telegram.md` — callback-роутинг и префиксные роуты.

## Acceptance criteria
- [ ] У сапёрной лопаты, алмазной кирки и золотой мотыги `craft_again_callback` ведёт на карточку рецепта,
      а не на `genericCraft_<Key>_1`.
- [ ] Значение callback'а совпадает с тем, что реально зарегистрировано в `Config\CallbackRoutes` для карточки
      T3-утилит (проверить по факту регистрации, а не по памяти) — иначе кнопка станет мёртвой.
- [ ] Ни один другой рецепт в файле не изменён.

## Verification
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes
- `app/Config/CraftRecipes.php`: `craft_again_callback` для `DiamondPickaxe`, `SapperShovel`, `GoldenHoe`
  сменён с `genericCraft_<Key>_1` на `craftPreviewT3Utility_<Key>`.
- Формат сверен по факту: `prefixRoutes['craftPreviewT3Utility']` в `CallbackRoutes.php` (строка 545) →
  `UtilityRecipePreviewT3Action`, чей `extractRecipeKey()` парсит префикс `craftPreviewT3Utility_` (строки 269-273).
  Тот же формат уже использует соседнее поле `info_callback` этих же трёх рецептов — совпало.
- Другие рецепты в файле не тронуты (diff — ровно 3 строки).

## Findings
