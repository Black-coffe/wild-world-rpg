---
story: web-bridge-p1-05
spec: web-bridge-p1
status: todo
returned:
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

## Findings
