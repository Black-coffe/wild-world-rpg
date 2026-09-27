---
story: w2-n3-craft-tails-01
spec: w2-n3-craft-tails
status: done
returned: DONE
tier: 1
worker: worker-code
model: opus
wave: 1
blocked_by: []
---

# Веб-старт крафта: флаги видимости, лог отказов, номер в очереди

## Goal
`WebNativeScreenService::craftStart()` отказывает рецепту, скрытому фильтром `visibleRecipes()`
(ручной POST — отказ без старта и списания). Каждый отказ веб-старта (скрытый рецепт, потолок
количества, гейт/нехватка ядра) пишется в `action_log` так же, как бот через `BaseAction::logRejected`
(`CRAFT_<Key>`, reason и extra из `$out['log']` ядра). «Место в очереди» в веб-карточке —
число ожидающих + 1 (только если старт уйдёт в очередь), в ответе на старт — `position` новой строки
из `CraftQueueService::forCharacter()`; так номер совпадает со списком очереди `/play`.

## Requirements
> 1. Веб-старт крафта проверяет те же флаги видимости рецептов, что и экран (`cooking.fish_dishes.enabled`, флаг дронов и прочие из `visibleRecipes()`): ручной POST скрытого рецепта — отказ без старта и без списания.
> 2. Отказ веб-старта (гейт ядра, нехватка, потолок количества, скрытый рецепт) пишется в `action_log` так же, как бот пишет через `logRejected` (`CRAFT_<Key>` + причина ядра).
> 3. «Место в очереди» в веб-карточке и в ответе на веб-старт совпадает с номером, под которым этот крафт стоит в списке очереди `/play`; тексты и нумерация бота не меняются.

## Files
- app/Services/Web/WebNativeScreenService.php
- app/Views/site/_play/native_craft.php
- tests/database/WebCraftStartTailsTest.php
- phpstan-baseline.neon

## Non-goals
- Не менять бота: `queue_pos` ядра, тексты `GenericCraftActionStart`, `BaseAction::logRejected`.
- Не закрывать дыру прямого `genericCraft_` в боте.
- Не трогать CSS/JS и ui-kit.

## Map slice
`memory/map/website.md`, `memory/map/craft.md`.

## Acceptance criteria
- [ ] Тест: POST `craft_start` рецепта, скрытого флагом (например рыбное блюдо при `cooking.fish_dishes.enabled`=false) — отказ, `character_tasks` без новой строки, сырьё не списано.
- [ ] Тест: отказ веб-старта (скрытый рецепт и нехватка сырья) оставляет строку в `action_log` с `CRAFT_<Key>` и причиной.
- [ ] Тест: при одном идущем и одном ожидающем крафте того же рецепта карточка и ответ на старт показывают №2, и новая строка стоит в списке очереди под №2; при пустой очереди и свободном слоте строки «Место в очереди» нет.
- [ ] Бот-тесты паритета (`CraftOrderServiceTest`, `CraftQueueCoreTest`) зелёные без правок.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress && vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes
- WebNativeScreenService: `craftStart` отказывает рецепту, не прошедшему `visibleRecipes()` ни в одной категории каталога (`recipeVisible`), ответ «Этот рецепт сейчас недоступен.», после claim интента.
- WebNativeScreenService: `logCraftRejected` — копия `BaseAction::logRejected` (firehose `markRejected` + `ActionLogModel` REJECTED); `chat_id` 0 (у веба нет чата, как у других не-чатовых писателей); причины: `recipe_hidden`, `qty_over_max`, ядро — `log.reason` или `code`, если ядро `log` не дало (бот такие не пишет, веб пишет всё).
- WebNativeScreenService: `card.queue_pos` = ожидающих + 1, только если идёт крафт того же рецепта (сверка по `recipe` активных строк очереди), иначе null; ответ на старт — `position` новой строки из `forCharacter()`, fallback — ожидающих + 1.
- native_craft.php: строка «Место в очереди» только при `queue_pos` > 0.
- tests/database/WebCraftStartTailsTest.php: 3 теста на настоящем ядре; при выключенном guard'е скрытого рецепта тест красный (проверено).
- phpstan-baseline.neon не понадобился.

## Findings
