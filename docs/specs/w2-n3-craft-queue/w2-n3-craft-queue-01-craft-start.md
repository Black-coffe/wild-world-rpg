---
story: w2-n3-craft-queue-01
spec: w2-n3-craft-queue
status: todo
returned:
tier: 2
worker: worker-code
model: opus
wave: 1
blocked_by: []
---

# Ядро старта крафта, `GenericCraftActionStart` — рендерер

## Goal
`App\Services\Craft\CraftOrderService` делает всё, что сегодня делает `GenericCraftActionStart::handle()` до отправки:
- гейты в прежнем порядке (эксклюзив ADR-167 → лимит рецепта → слоты → база → здания/уровни → required_crafted_items → квест → фракция → сезон → золото → сила/ловкость/уровень);
- сырьё через пул рюкзак+склад, нехватка → код отказа с данными для экрана `CraftShortageService`;
- длительность `CraftDurationService`, строка `character_tasks` `in_work|queued`.

`preview()` отдаёт то же без записи, плюс `max_qty` («хватает сырья» ∧ лимит очереди) — для веб-карточки story 03.

Атомарность (Ask 5): золото — `decrementIfAtLeast` через `ConditionalWriteService`; предметы-ингредиенты (`subtractCraftedItems`) — условной записью; лимиты очереди и слотов проверяются под блокировкой строки персонажа (`SELECT … FOR UPDATE`) в той же транзакции, что и вставка. Разбивку списания рюкзак/склад пишем в `task_settings` (её читает отмена в story 02).

Лимиты: `craft.queue.max_per_recipe` (10) и `craft.queue.max_slots` (3) — в GameSettings, с rationale и границами, seed-миграцией; `Config\GameBalance` больше не читается.

Handler бота становится рендерером: те же тексты, фото и кнопки (очередь, старт, нехватка), в том же порядке. `checkCanStartWithoutMaterials()` (зовёт `CraftShortfallBuyAction`) делегирует ядру. Путь костра «своё число» (`GenericmessageCommand`) не трогаем — он зовёт тот же handler.

## Requirements
> 1. Крафт — одно ядро для бота и веба: старт, очередь, отмена, завершение и длительность в сервисе; handler'ы бота становятся рендерерами, их сообщения и кнопки — прежние.
> 5. Атомарность: золото и предметы-ингредиенты — условной записью, продвижение очереди — только из queued, отмена возвращает туда, откуда взяли; лимиты очереди и слотов — в GameSettings.

## Files
- app/Services/Craft/CraftOrderService.php
- app/Controllers/Telegram/Commands/Actions/Craft/GenericCraftActionStart.php
- app/Controllers/Telegram/Commands/Actions/Craft/CraftShortfallBuyAction.php
- app/Config/GameBalance.php
- app/Database/Migrations/2026-12-14-100000_SeedCraftQueueLimitSettings.php
- tests/database/CraftOrderServiceTest.php
- phpstan-baseline.neon

## Non-goals
- Не трогать очередь/отмену/завершение (story 02) и веб (story 03).
- Не менять гейты, их порядок и тексты; не менять формулу длительности.
- Не трогать ~86 штучных карточек и категорий бота и формат `genericCraft_<Key>_<qty>` (ADR-182).

## Map slice
`memory/map/craft.md` (Entry points, Gotchas: пул ADR-171, ADR-181, ADR-182).

## Acceptance criteria
- [ ] Снимок паритета: вывод бота (текст, фото, кнопки) для старта, постановки в очередь, нехватки, упора в лимит рецепта и в слоты — снят со старого handler'а до правки и совпадает после (отдельный процесс, если нужно, как в W2.N2).
- [ ] Тест: золото не уходит в минус при двух стартах подряд на остаток; нехватка золота — отказ без записи.
- [ ] Тест: лимит слотов/очереди не превышается при двух стартах подряд (блокировка строки персонажа в транзакции).
- [ ] `task_settings` нового старта несёт разбивку рюкзак/склад.
- [ ] Ключи `craft.queue.*` в GameSettings со значениями 10 и 3; миграция идемпотентна, `php -l` чистый.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress && vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes

## Findings
