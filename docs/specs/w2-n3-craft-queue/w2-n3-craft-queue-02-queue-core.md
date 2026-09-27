---
story: w2-n3-craft-queue-02
spec: w2-n3-craft-queue
status: done
returned: DONE
tier: 2
worker: worker-code
model: opus
wave: 2
blocked_by: [w2-n3-craft-queue-01]
---

# Очередь, отмена, продвижение и завершение в ядре

## Goal
`App\Services\Craft\CraftQueueService`:
- `forCharacter()` — активные (`ends_at`) и ожидающие с позицией и оценкой времени «≈» (остаток активного того же рецепта + `minutesForOne × qty` каждого впереди);
- `cancel()` — условный `DELETE … WHERE status='queued'` первым шагом, возврат сырья туда, откуда списано (разбивка из `task_settings` story 01; у старых строк — рюкзак), золото и предметы — условной записью;
- `promoteNext()` — `UPDATE … WHERE status='queued'` (гонка с отменой не даёт двойного старта).

`ShowCraftQueueAction`, `CancelQueuedCraftAction` — рендереры ядра. `GenericCraftCompletionHandler` зовёт `promoteNext()` вместо своего `activateNextQueuedTask`; выдача предмета и тексты завершения — прежние. `ActiveTasksService::getCraftQueue()` делегирует ядру.

Доставка у web-only: проверить на виртуальном id, доходят ли «📌 Крафт завершён!» и «▶️ Очередь активирована» (сейчас `notifyUser`/`notifyQueuedActivated` молча выходят без `telegram_users.telegram_id`); если нет — отправлять по chat_id персонажа, чтобы `WebDelivery` положил их во входящие /play.

## Requirements
> 1. Крафт — одно ядро для бота и веба: старт, очередь, отмена, завершение и длительность в сервисе; handler'ы бота становятся рендерерами, их сообщения и кнопки — прежние.
> 4. Очередь в /play рядом с крафтом: активные с живым таймером, ожидающие с позицией и оценкой времени, отмена ожидающих; завершение крафта видно в вебе, в том числе у игрока без Telegram.
> 5. Атомарность: золото и предметы-ингредиенты — условной записью, продвижение очереди — только из queued, отмена возвращает туда, откуда взяли; лимиты очереди и слотов — в GameSettings.

## Files
- app/Services/Craft/CraftQueueService.php
- app/Controllers/Telegram/Commands/Actions/Craft/ShowCraftQueueAction.php
- app/Controllers/Telegram/Commands/Actions/Craft/CancelQueuedCraftAction.php
- app/TaskHandlers/Craft/GenericCraftCompletionHandler.php
- app/Services/Tasks/ActiveTasksService.php
- tests/database/CraftQueueCoreTest.php
- tests/database/CraftQueueServiceTest.php
- phpstan-baseline.neon

## Non-goals
- Не менять выдачу предмета, баффы, износ и кнопку «🔄 Крафтить еще» (ADR-182).
- Не трогать веб (story 03) и старт (story 01).
- Не переводить completion-handler в транзакцию целиком — только продвижение и отмена.

## Map slice
`memory/map/craft.md` (Gotchas ADR-181 — отмена очереди); `memory/map/tasks-worker.md`.

## Acceptance criteria
- [ ] Снимок паритета бота: экран очереди, отмена, «📌 Крафт завершён!», «▶️ Очередь активирована» — до и после совпадают.
- [ ] Тест: отмена возвращает сырьё в рюкзак и на склад по разбивке; строка без разбивки — в рюкзак.
- [ ] Тест: отмена и продвижение одной строки — срабатывает ровно одно из двух.
- [ ] Тест: оценка времени ожидающих считается по формуле из Goal.
- [ ] Тест или проверка на виртуальном id: завершение и активация очереди доходят до `WebDelivery`.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress && vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes
- `app/Services/Craft/CraftQueueService.php` (new): `rows()` (the list without estimates, used by the bot), `forCharacter()` (adds `minutes_total` and `starts_in_seconds` to each queued row), `cancel()`, `promoteNext()`. Constructor `(?CharacterTaskModel, ?CraftDurationService)`.
- Refunds use relative writes (`ConditionalWriteService::increment` on the oldest row, otherwise insert): backpack/storage per `consumed`, items and gold per `consumed`. Rows without `consumed` get recipe × qty back into the backpack.
- `promoteNext()` does a conditional `UPDATE … WHERE status='queued'`. If the candidate was taken, it moves to the next queued row of the same recipe, so a cancelled head does not stall the queue.
- The handler and the cancel action pass their own `characterTaskModel` to the core. Without that, the snapshot seam of `CancelQueuedCraftConditionalDeleteTest` (race via `first()`) and the double in `CraftQuantityParityTest` would stop working.
- `ShowCraftQueueAction` → `CraftQueueService::rows()`. `ActiveTasksService::getCraftQueue()` delegates to `rows()` and keeps the old response shape. `CraftQueueServiceTest` passes unchanged.
- Web-only players: nothing had to change. `notifyUser` and `notifyQueuedActivated` check `empty(telegram_id)`, and a virtual id is negative, not empty. `testCompletionAndActivationReachWebOnlyInbox` shows both messages land in `web_inbox` (`virtual`) and nothing goes to Telegram.
- Parity: `BOT_BEFORE` was captured from the old code (queue empty/full, cancel ok/gone, completion + activation) before the edit, in a separate process with media off. After the edit it matches exactly; the time in «Завершится в» is masked.
- Guards: turning the `DELETE` status condition into a tautology turns `testCancelAndPromoteOfOneRowNeverBothApply` red. Doing the same to the `UPDATE` condition turns `testSecondPromoteOfTheSameRowDoesNothing` red. Each was checked separately, then restored.
- `phpstan-baseline.neon`: removed 8 stale entries (7 for the old `CancelQueuedCraftAction`, 1 cast in the handler); phpstan is clean.
- Surprise: `TelegramBridge::ensure()` installs its own HTTP client on first start. The test brings it up before the recorder, otherwise the handler's messages bypass the recorder.

## Findings
