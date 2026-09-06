---
story: craft-quantity-parity-07
spec: craft-quantity-parity
status: done
tier: 2
worker: worker-test
tracer: false
wave: 4
blocked_by: [craft-quantity-parity-05, craft-quantity-parity-06]
---

# Тесты на два дефекта, которые проехали зелёными

## Goal
Оба дефекта ремонтного круга закрыты тестами, которые краснеют при их возврате: конвенция
`craft_again_callback` проверяется для ВСЕХ рецептов (а не для списка, куда T3-утилиты не входили),
а отсечение ступеней проверяется на расчёте доступности из данных персонажа, а не на уже
посчитанном числе.

## Requirements
> **2026-09-06, там же.** Story 07 закрывает дыру в покрытии, из-за которой оба дефекта проехали

## Files
- tests/unit/Config/CraftRecipesTest.php
- tests/unit/Craft/CraftQuantityParityTest.php

## Non-goals
- НЕ менять production-код, в том числе временно и в том числе ради проверки чувствительности
  теста. Сомнение в чувствительности — словами в NOTES, не мутацией.
- НЕ переписывать существующие тесты в `CraftRecipesTest.php` — дополнить, не заменить.
- НЕ гонять полный набор: DB-тесты дерутся за общие таблицы. Только два своих файла.

## Map slice
`memory/map/craft.md`; `app/Services/Craft/CraftShortageService.php:266-282`.

## Acceptance criteria
- [ ] Тест проходит по ВСЕМ записям `Config\CraftRecipes` и требует, чтобы `craft_again_callback`
      (там, где поле есть) резолвился в существующий ключ рецепта через ту же логику, что
      `CraftShortageService::shortfallRecipeKey()`. Прежний дефект обязан валить этот тест.
- [ ] Тест на доступность: при сырье на складе и пустом рюкзаке персонажа на базе ступени НЕ режутся;
      при выключенном пуле — режутся. Проверяется возврат кода, а не текст исходника.
- [ ] Существующие тесты обоих файлов остаются зелёными.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Config/CraftRecipesTest.php tests/unit/Craft/CraftQuantityParityTest.php`

## Implementation notes

## Findings
