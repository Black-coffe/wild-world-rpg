# Флаги фич рецептов — в ядро крафта; CI на PHP 8.3 (plan)

**Tier:** 1 · **Spec slug:** `w2-n3-craft-flags` · **Brief:** [brief.md](brief.md)
**Governed by:** ADR-190 (нейтральное ядро), ADR-024 (флаги фич в GameSettings)
**Depends on:** w2-n3-craft-tails (v0.51.679): `WebNativeScreenService::recipeShown|recipeVisible`, лог отказов веба

## Goal
Список «рецепт → флаг фичи» (рыбные блюда, четыре дрона) переезжает из `WebNativeScreenService::recipeShown()`
в `CraftOrderService` как гейт в `gateError()`/`start()` с новым кодом (например `FEATURE_OFF`) и текстом
«Этот рецепт сейчас недоступен.». Бот получает отказ через тот же рендер гейтов, что и остальные
(`logRejected` с reason ядра); веб-фильтр `visibleRecipes()` зовёт публичный метод ядра. Сезон уже
проверяет гейт `season_inactive` — его не трогаем. `deploy.yml`: `php-version: '8.3'`.

## Assumptions
- Гейт встаёт первым после «нет рецепта» (до эксклюзива ADR-167), чтобы выключенная фича не
  отвечала текстом другого гейта; для видимых рецептов порядок прежний.
- Паритет бота для видимых рецептов подтверждают существующие снимки `CraftOrderServiceTest` без правок.
- composer.json `"php": "^8.2"` не меняем — это нижняя граница, не цель CI.

## Stories

**Wave 1**
- `w2-n3-craft-flags-01` — гейт флагов фич в ядре, веб использует его, CI на PHP 8.3

## Contracts
- none

## Integration gate
`vendor/bin/phpunit --no-coverage --no-progress && vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Descoped

*(empty)*

## Plan deltas

**Approved:** <owner, date>
**Briefed:** via mini-brief, Andrei, 2026-09-27
**Branch:** vulyk/w2-n3-craft-flags
**Checked:** <written by scripts/human-check.sh>
**Council:** GREEN round 1, 2026-09-27, at e1f74493, pack 3e8a44032f1b
**Shipped:** <written by scripts/ship-check.sh --record>
