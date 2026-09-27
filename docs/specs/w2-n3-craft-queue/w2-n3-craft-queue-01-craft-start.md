---
story: w2-n3-craft-queue-01
spec: w2-n3-craft-queue
status: done
returned: DONE
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
- `CraftOrderService` (new): `preview/start/gateError` + `checkResources/checkCraftedItems/subtractResources/characterFactionId`; gates, texts and order moved 1:1 from the handler; refusal = `{code, message, log}`, the renderer writes action_log (it has the chat_id).
- Atomicity: `start()` runs `transBegin` → `SELECT id FROM characters … FOR UPDATE` → re-checks queue/slot limits → consumes from the pool → crafted items via `decrementIfAtLeast(deleteWhenEmpty)` → gold via `decrementIfAtLeast` → insert. A refusal inside rolls back everything. Before this, gold was an unconditional `decrement`, and items were read-then-written (a missing log row went through for free).
- `task_settings.consumed = {resources: {name: {backpack, storage}}, crafted_items: {eng: n}, gold}` is the breakdown that the cancel path in story 02 reads.
- Limits: `craft.queue.max_per_recipe`=10 and `craft.queue.max_slots`=3 are seeded by the idempotent migration `2026-12-14-100000_SeedCraftQueueLimitSettings` (existing keys are not overwritten). The `Config\GameBalance` fields are removed (no other readers).
- `GenericCraftActionStart` is now a renderer: user/recipe lookup plus rendering the outcome. `checkCanStartWithoutMaterials()` delegates to `gateError()`. `CraftShortfallBuyAction` is unchanged: it still calls this method.
- Surprise: `CraftPoolConsumptionTest` and `VehicleRecipesTest` call the handler's private `checkResources/subtractResources/characterFactionId` via reflection. They stay as thin `protected` wrappers over the core (they were `private` → phpstan `method.unused`).
- Parity: `CraftOrderServiceTest::BOT_BEFORE` has 10 cases (start, start with components, queue, shortage screen, shortage string, recipe limit, slot limit, exclusive, gold, unknown recipe). They were captured from the old handler BEFORE the edit and run in a separate process. Photo URLs are recorded through a stub `http` stream, media-off. After the edit all 10 match exactly.
- Behaviour change only in races, plus `have` of missing components is now int (it used to be the raw DB string) in the log/shortage screen.
- `phpstan-baseline.neon`: 13 entries for the handler that no longer matched are removed; phpstan is clean.
- Order dependence of the shared test DB (not mine): `VehicleRecipesTest` leaves `characters(id, level)`, so `CampfireCustomQuantityTest` goes red right after it and green on a clean schema.
- Tech-writing notes (vault: CraftOrderService, GenericCraftActionStart) are outside `## Files`; they go to drone-docs.

## Findings
