---
id: craft-recipe-three-places
title: Новый рецепт заведён не во всех трёх местах
status: text
check:
fixtures: []
keys: [задача не найдена в базе]
paths: ["app/Config/CraftRecipes.php", "app/Database/Migrations/**"]
---

# Новый рецепт заведён не во всех трёх местах

Рецепт ухи засеяли в `crafted_items`, `Config\CraftRecipes` и GameSettings, но без строки `tasks` —
крафт падал с «Задача 'craftFishSoup' не найдена в базе». Unit-тесты конфига это не ловят.

## Нельзя
- Добавлять generic-craft рецепт без строки в `tasks` (`name` = `task_name` рецепта, `handler_key='generic_craft'`).

## Как надо
- Одна миграция сидит `crafted_items` и `tasks`, рецепт — в `Config\CraftRecipes`; для готовки — ключ в меню костра и GameSettings лечения/сытости. Эталон — миграция `V8AddCookingItems`.

## Source
- 2026-05-26 · auto memory reference_cooking_recipe_three_places · retold, not a quote
