# Хвосты W2.N3: веб-старт крафта — флаги, лог отказов, номер в очереди (plan)

**Tier:** 1 · **Spec slug:** `w2-n3-craft-tails` · **Brief:** [brief.md](brief.md)
**Governed by:** ADR-190 (нейтральное ядро), ADR-148 (firehose), ADR-181 (условные записи)
**Depends on:** w2-n3-craft-queue (v0.51.678): `WebNativeScreenService::craftStart|craftModel|visibleRecipes`, `CraftOrderService`, `CraftQueueService::forCharacter`

## Goal
Три правки веб-пути крафта без изменений бота. `craftStart` отказывает рецепту, которого экран не
показал бы (тот же фильтр, что `visibleRecipes()`). Каждый отказ веб-старта пишется в `action_log`
по образцу бота (`CRAFT_<Key>` + reason/extra ядра). Номер в очереди в карточке и ответе считается
так же, как в списке очереди `/play` (порядковый номер среди ожидающих), а не `queue_pos` ядра
(число строк того же рецепта + 1) — его по-прежнему показывает бот.

## Assumptions
- Бот не трогаем: `queue_pos` ядра и `logRejected` бота остаются как есть; дыра прямого
  `genericCraft_` в боте — вне этой story (паритет с ботом, отдельное решение).
- Номер в карточке — прогноз: число ожидающих + 1, если старт уйдёт в очередь; если старт начнётся
  сразу, строки «Место в очереди» нет (или «сразу»). Номер в ответе — фактическая `position` новой
  строки из `CraftQueueService::forCharacter`.
- Запись отказа в `action_log` — тем же `ActionLogModel` и полями, что `BaseAction::logRejected`,
  вынесенными в общий хелпер или повторёнными в сервисе веба; firehose веба (`markRejected`) — если
  веб-запрос его ведёт.

## Stories

**Wave 1**
- `w2-n3-craft-tails-01` — флаги видимости в `craftStart`, лог отказов веба, номер в очереди = список

## Contracts
- none

## Integration gate
`vendor/bin/phpunit --no-coverage --no-progress && vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Descoped

*(empty)*

## Plan deltas

**Approved:** <owner, date>
**Briefed:** via mini-brief, Andrei, 2026-09-27
**Branch:** vulyk/w2-n3-craft-tails
**Checked:** <written by scripts/human-check.sh>
**Council:** GREEN round 1, 2026-09-27, at 005c3baa, pack 5b753339e458
**Shipped:** <written by scripts/ship-check.sh --record>
