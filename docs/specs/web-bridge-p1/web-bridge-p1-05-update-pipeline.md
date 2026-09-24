---
story: web-bridge-p1-05
spec: web-bridge-p1
status: done
returned: DONE
tier: 3
worker: worker-code
model: opus
tracer: false
wave: 2
blocked_by: [web-bridge-p1-01]
---

# One update pipeline for the webhook and the web; synthetic updates

## Goal
The body of `BotController::webhook()` that runs **after** secret, JSON decode, the ADR-181
`telegram_updates_seen` dedup and the community/group gate (`:77`) moves into
`App\Services\Telegram\UpdatePipeline::run(array $update, string $source)` (ADR-189 §1). The moved
body covers:
- LastSeen extract;
- `ActionOrigin::stripUpdate/set` + `setCustomInput`;
- `PlayerActionLogger::begin/setOrigin` (with `'web'` passed as the source override);
- `TelegramDeliveryProbe::install()`;
- E6/E8 hooks (ReturnDigest, LoginStreak, DailyTask);
- the dispatch;
- `finally`: stamp, commit, `ActionOrigin::reset`.

The pipeline also sets `DeliveryContext::setActor(chat id of the update)` at the start and resets
it in `finally`. `BotController` keeps secret, decode, dedup and the gate, then calls the
pipeline with `'telegram'`. For Telegram players nothing observable changes.

`UpdatePipeline::telegram()` builds the `Telegram` instance exactly as `BotController` does
today (`:16-28`), so `/play` can reuse it.

`App\Services\Web\SyntheticUpdateFactory` builds `callback(...)` and `message(...)` arrays. They
are valid Longman input:
- `from`/`chat` come only from the identity argument; `chat.type='private'`;
- a callback carries `message` with the screen's `message_id`, chat, text or caption plus a
  photo marker, and `reply_markup`;
- a text/command reply carries `reply_to_message` when the screen asked for force-reply;
- commands get a `bot_command` entity;
- `update_id` is the negative number passed in, and it is never written to
  `telegram_updates_seen`.

## Requirements
> Вошедший на сайт игрок играет на `/play` через те же маршруты бота (кнопки, текстовые ответы, команды). По возможностям это то же, что бот, без второй реализации правил.
> Для игроков в Telegram бот работает как раньше.
> В журнале действий есть канал `web`.

## Files
- app/Controllers/Telegram/BotController.php
- app/Services/Telegram/UpdatePipeline.php
- app/Services/Web/SyntheticUpdateFactory.php
- tests/database/UpdatePipelineTest.php
- tests/unit/Services/Web/SyntheticUpdateFactoryTest.php
- phpstan-baseline.neon
- app/Services/Player/LastSeenService.php
- app/Services/Player/LoginStreakService.php
- app/Services/Player/ReturnDigestService.php
- app/Services/Quest/DailyTaskService.php

## Non-goals
- Do not change the webhook's secret check, dedup, group gate, or the order of steps inside the
  moved body. This is a move, not a redesign.
- No `setClient`, `BridgeClient` or capture calls here. Story 07 wraps the pipeline.
- No dedup for web updates in this story. Story 07 does it through `web_play_intents`.
- Do not touch handlers, `CallbackqueryCommand`, `GenericmessageCommand` or resolvers.
- If Q5 lists tests that spy on `BotController` private methods and they break, report
  NEEDS_CONTEXT; do not rewrite them outside `## Files`.

## Map slice
- `memory/map/telegram.md`: ADR-181 dedup gotcha, bridge `ensure()`, reply menu.
- Note `mmorpg-vault/tech-writing/services/PlayerActionLogger.md` (begin/setOrigin order).
- recon.md §A (`BotController.php:16-28,47-178,207-235`, `Telegram.php:595`, the markup
  shapes).
- ADR-189 §1 and invariants 2 and 5.

## Acceptance criteria
- [ ] The worker runs its own new test files singly while iterating. The close-story gate is the
      full suite, phpstan and the migrations lint.
- [ ] Ask 5: every existing `BotController` and webhook test passes **unchanged**. A Telegram
      update through the webhook produces the same firehose row (source, action, origin) and the
      same `last_seen` stamp as before the extraction.
- [ ] Ask 1: a synthetic callback and a synthetic text message for a character with a virtual row
      (story 01) run through `run($u, 'web')` and reach the same dispatcher as a Telegram update.
      The test asserts the routed action (not `unrouted`) with a real `callback_data` from
      `CallbackRoutes`.
- [ ] Ask 6: the resulting `player_action_log` row has `source='web'` and the origin from an
      `~label` tail when the data carries one. The E6/E8 hooks and the `last_seen` stamp ran for
      the virtual `telegram_id`.
- [ ] `DeliveryContext::actor()` equals the update's chat id during dispatch and is `null` after
      `run()` returns, including when a handler throws. `run()` never throws.
- [ ] `SyntheticUpdateFactoryTest`: the output parses as a Longman `Update` (callback →
      `CallbackQuery` with a message; `/cmd args` → a command); `from.id`/`chat.id` equal the
      identity's `telegram_id`; negative `update_id` is kept.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
`git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`

## Implementation notes
- `UpdatePipeline` (new): `__construct(?Telegram, ?callable $dispatch)`, `static telegram(): ?Telegram` (nullable, like the old constructor that left `$telegram` null on `TelegramException`), `run(?array $update, string $source): bool`. `null` update = undecodable body: dispatch only, same as the old `is_array` branches. Returns `false` on a swallowed error; `'telegram'` still rethrows a non-Telegram `Throwable` (Queen Q2).
- Dispatch: `BotController` passes `fn () => $this->dispatchToTelegram()`, so the five spy tests stay unchanged. With no callable, `'web'` dispatches through `Telegram::processUpdate(new Update(...))` (not php://input), and `'telegram'` through `handle()`.
- `DeliveryContext` actor = `LastSeenService::extractChatId($update)`, reset in an outer `finally` that also covers the pre-dispatch steps.
- `BotController::__construct` now calls `UpdatePipeline::telegram()`. The two phpstan-baseline entries for `string|false` → `Telegram` constructor no longer matched, so I removed them.
- Guards (Q1): `LastSeenService` extract and stamp, `LoginStreakService`, `ReturnDigestService`, `DailyTaskService` → `positive OR VirtualChat::is()`. I reverted each of the 5 guards separately, and `UpdatePipelineTest` went red each time.
- Surprise: the synthetic `guide` callback's chat-less `answerCallbackQuery` reached api.telegram.org once during iteration: fake token, 401 → `status=error`. The test now pre-installs the probe and swaps the Longman client for an "ok" stub. In `/play`, story 07's `BridgeClient` must catch this.
- `SyntheticUpdateFactory`: the embedded screen message has no `from`, because no handler reads it. Photo screens carry a 1x1 `photo` marker + caption. The `bot_command` entity length is in UTF-16 units.

## Findings
- **Q1 (blocks Ask 6): the hooks and the stamp reject a virtual id, and their files are not in `## Files`.**
  Each of these stops on a negative telegram id before doing anything:
  `LastSeenService::extractTelegramId()` (`$id > 0`, so the pipeline gets `null` and skips every hook
  and the stamp), `LastSeenService::stampByTelegramId()` (`$telegramId <= 0` return),
  `LoginStreakService::maybeReward()` (`:40`-ish, `$telegramId <= 0`), `ReturnDigestService::maybeSendDigest()`
  (`:40`, `$telegramId <= 0`) and `DailyTaskService::ensureForTelegramUser()` (`:50`, `$telegramId <= 0`).
  So "E6/E8 hooks and the `last_seen` stamp ran for the virtual `telegram_id`" cannot be met from the
  pipeline alone without re-implementing the stamp and the hooks, which Ask 1 forbids. Proposed fix:
  add `app/Services/Player/LastSeenService.php`, `app/Services/Player/LoginStreakService.php`,
  `app/Services/Player/ReturnDigestService.php`, `app/Services/Quest/DailyTaskService.php` to this
  story and change each guard to "positive OR `VirtualChat::is($id)`" (group ids are already cut by the
  webhook's community gate, and a real `from.id` is never negative). Or: accept Ask 6 without the
  hooks/stamp part and move that to a new story. Which one?
- **Q2 (Ask 5 vs "run() never throws"):** today `webhook()` rethrows a non-Telegram `Throwable`
  (framework → HTTP 500). If `run()` never throws, the webhook answers 200 on such errors instead.
  The firehose row (`status=error`) is the same; with ADR-181 dedup a Telegram retry would be dropped
  anyway. Is the 500→200 change accepted as "nothing observable changes", or should `run()` swallow
  only for `'web'` and keep rethrowing for `'telegram'`?
- **Re-dispatch 2026-09-24:** neither story nor plan.md/journal.md carries an answer to Q1, so it
  still blocks. The guards are still live (`LastSeenService.php:45,125`, `LoginStreakService.php:46`,
  `ReturnDigestService.php:40`, `DailyTaskService.php:50`). Q2 reads as settled by plan.md:234
  ("It never throws", no per-source split); I will take 500→200 for `'telegram'` as accepted unless
  told otherwise. Only Q1 needs an answer: widen `## Files` by the four services, or drop the
  hooks/stamp half of Ask 6 from this story.
- Not a question, noted for the planner: the five `BotController*Test` files override the protected
  `dispatchToTelegram()`; they stay unchanged only if the pipeline takes a dispatch callable from the
  controller (planned: `new UpdatePipeline($telegram, fn () => $this->dispatchToTelegram())`, `run()`
  signature as in the story).
- **Queen answer 2026-09-24 (plan delta):** Q1 — widen `## Files` by the four services (done above); in each, change the guard to "positive OR `VirtualChat::is($id)`" — nothing else in those files. Q2 — do **not** change the webhook's observable behaviour (Ask 5): `run()` keeps rethrowing non-Telegram `Throwable` for source `'telegram'` exactly as `webhook()` does today (HTTP 500 stays 500); it swallows (firehose `status=error`, returns a failure result) only for source `'web'`. Rejected: 500→200 for the webhook — a visible change the brief did not ask for.
