# W2.N3 — крафт на нейтральном ядре: верстаки, карточка, очередь с таймерами (plan)

**Tier:** 2 · **Spec slug:** `w2-n3-craft-queue` · **Brief:** [brief.md](brief.md)
**Governed by:** ADR-190 (нейтральное ядро), ADR-189 (мост как фолбэк), ADR-130 (верстаки), ADR-171 (пул рюкзак+склад), ADR-181 (условные записи), ADR-182 (craft_again = ключ рецепта), ADR-167 (эксклюзивные задачи), ADR-062, ADR-024, ADR-020
**Depends on:** w2-n2-live-map (v0.51.677): `WebNativeScreenService` VIEW_*/OP_*, `intentKey()`, `op=bridge`, `site/_play/{hud,dock,native_map}.php`, дедуп `web_play_intents`

## Goal
Крафт перестаёт жить в Telegram-handler'ах. Появляется ядро (старт с гейтами, очередь с оценкой
времени, отмена, продвижение, завершение) без `chat_id`; `GenericCraftActionStart`,
`ShowCraftQueueAction`, `CancelQueuedCraftAction` и `GenericCraftCompletionHandler` становятся его
рендерерами с прежним текстом. Заодно ядро закрывает найденные разведкой гонки. В вебе появляется
нативный «🔨 Крафт»: верстаки из нового индекса «верстак → категория → рецепты», общая карточка
рецепта с полем «своё число» и очередь с живыми таймерами. Плюс два хвоста W2.N2.

## Assumptions
- Tier 2 на верхней границе, как N1/N2: три review-единицы, story 01 и 02 переписывают крупные
  handler'ы (старт 847 строк, завершение ~700). Не влезает — делим story, а не расширяем.
- **Каталог бота не меняется** (ответ 1): ~86 штучных экранов категорий и карточек остаются как есть,
  включая их зашитые числа — это отдельная будущая спека. Веб-каталог строится из нового индекса
  (`Config\CraftCatalog`: верстак → категория → ключи `CraftRecipes`), наполненного по спискам кнопок
  нынешних экранов бота, чтобы деревья совпадали. Рецепт, которого нет в индексе, в вебе не виден —
  тест сверяет, что каждый ключ индекса есть в `CraftRecipes`.
- Замки верстаков — те же правила, что у бота: Верстак 1 без гейта, Стандартный — база
  (сейчас только текстом в боте), Проф. — `ProfessionalWorkbench` в `crafted_items_log`. В вебе замок —
  «🔒 Название (нужно: …)» с путём (UX-DISCOVERABILITY); в боте текст не меняется.
- **Атомарность (ответ 2)** меняет поведение только в гонках, не в обычном пути: золото —
  `decrementIfAtLeast`, предметы-ингредиенты — условной записью, продвижение очереди —
  `UPDATE … WHERE status='queued'`, лимиты очереди/слотов — проверка под блокировкой строк персонажа.
  Отмена возвращает сырьё туда, откуда списано: разбивку рюкзак/склад старт кладёт в `task_settings`;
  у старых строк без разбивки — рюкзак, как сейчас.
- `craftMaxQueuePerRecipe` (10) и `craftMaxConcurrentSlots` (3) переезжают из `Config\GameBalance`
  в GameSettings (`craft.queue.max_per_recipe`, `craft.queue.max_slots`, seed-миграция с прежними
  значениями) — поведение прода не меняется.
- «Своё число» только в вебе (ответ 3): поле `qty` от 1 до потолка «хватает сырья»
  (`CraftCardHelper::available`) и лимита очереди; бот — прежние кнопки, костёр — прежний путь.
  Фейковый `CallbackQuery` у костра не трогаем (это бот).
- Оценка времени ожидающих: остаток активного того же рецепта + `minutesForOne × qty` каждого
  впереди; помечается «≈», потому что при продвижении время пересчитывается статами в тот момент.
- Завершение у web-only: разведка нашла, что `notifyUser`/`notifyQueuedActivated` молча выходят без
  `telegram_users.telegram_id`. Story 02 проверяет это на виртуальном id и чинит так, чтобы сообщение
  ушло в outbox моста ADR-189 (входящие /play). HUD и очередь видят завершение опросом в любом случае.
- Мутации веба (`craft_start`, `craft_cancel`) дедуплицируются `intent_id` + суффикс экрана.
- Хвост W2.N2 №2 (`danger_tired_surcharge`) не берём: сверено 2026-09-27 — ключ переименован миграцией
  `2026-12-01-100000_RenameDangerTiredSurchargeToHealthSetting`, прод = код (`1.15`).
- `wave-check` даст `verify-gap` на все story (токен `vendor/bin/phpunit` читается как путь вне
  `## Files`), как в N1/N2; `empty-glob` — новые файлы, которых ещё нет.
- `trace-check` помечает строки `## Request` как непокрытые: это цитаты-источники (ROADMAP, report, daily), их содержание несут `## Asks` 1–7, которые story цитируют дословно. «Фильтры/поиск» из report §4 в Asks не вошли — не делаем. Asks 8 (вердикты) и 9 (живой проход) закрывает Queen в конце сборки.

## Stories

**Wave 1**
- `w2-n3-craft-queue-01` — ядро старта: `CraftOrderService::preview/start` (гейты, пул, атомарное списание, лимиты в GameSettings), `GenericCraftActionStart` — рендерер.

**Wave 2**
- `w2-n3-craft-queue-02` — очередь, отмена, продвижение, завершение в ядре; бот-экраны очереди/отмены и completion-handler — рендереры; доставка завершения web-only.

**Wave 3**
- `w2-n3-craft-queue-03` — веб: индекс каталога, `view=craft` (верстаки с замками, категории, карточка с «своим числом», очередь с таймерами и отменой), ui-kit; хвосты W2.N2 (HUD «готово» у Похода, клетка карты на 375).

## Contracts
- `CraftOrderService::preview(int $characterId, string $recipeKey, int $qty): array` —
  `{ok, code, recipe{key,name,icon,output_type}, resources[]{name,need,have}, gold, minutes_one,
  minutes_total, max_qty, queue_pos, gates[]}`; `start(int $characterId, string $recipeKey, int $qty): array` —
  `{ok, code, message, char_task_id, status: in_work|queued, ends_at}`; коды отказа — константы.
- `CraftQueueService::forCharacter(int $characterId): array` — `{active[]{char_task_id,recipe,name,qty,ends_at},
  queued[]{char_task_id,recipe,name,qty,position,eta_approx}}`; `cancel(int $characterId, int $charTaskId): array`;
  `promoteNext(int $characterId, int $taskId): ?array`.
- `POST /play/view` с `view=craft`: `op=workbench` (`wb`), `op=category` (`wb`, `cat`), `op=recipe` (`key`),
  `op=craft_start` (`key`, `qty`, `intent_id`), `op=craft_cancel` (`id`, `intent_id`). Ответ, как у N1/N2:
  `{html, hud, alert, csrf}`; без JS — PRG на `/play?view=craft`. Id персонажа — только из сессии.

## Integration gate
`vendor/bin/phpunit --no-coverage --no-progress`

## Descoped

*(empty)*

## Plan deltas

**Approved:** Andrei Andrievskii, 2026-09-27 — «да» (стадия 02)
**Briefed:** <written by scripts/cycle.sh briefed>
**Branch:** vulyk/w2-n3-craft-queue
**Checked:** <written by scripts/human-check.sh>
**Council:** GREEN round 1, 2026-09-27, at 9b6952e6, pack acbd19c31a59
**Shipped:** v0.51.678, 2026-09-27, at 10296ccc - merged to develop, publish pending (тег на develop после зелёного preprod-смоука)
