---
story: web-bridge-p1-11
spec: web-bridge-p1
status: done
returned: DONE
tier: 3
worker: worker-code
model: opus
tracer: false
wave: 6
blocked_by: [web-bridge-p1-09]
---

# A background edit of a web-originated message reaches the player, and screen writes do not race

## Goal
Fix for manual review (round 3), major #1 and minor #6.

**#1.** An act on `/play` returns a synthetic `message_id` (≥ `Config\WebPlay::firstMessageId`).
Callers store it, for example the march `msg_id` (`MarchAction:260`), and later a Worker edits it
(`MarchingTaskHandler:775-783`, edit first, send on failure). Two things go wrong today:
- **Web-only player:** `WebDelivery` drops the virtual-chat edit and returns a fake `ok`. The
  fallback never fires, and march progress never reaches the screen or the inbox.
- **Linked player:** the edit reaches Telegram with an id Telegram never issued. It fails, the
  fallback sends a **new** Telegram message and does not store its id, and a mirror row is added.
  This repeats on every step.

After this story the seam has one rule (plan A16). A **background** `edit*` (not the capturing
actor's own chat) whose `message_id` is in the synthetic range, aimed at a chat that resolves to a
character, never reaches Telegram, virtual or linked. With the flag on:
- the stored copy on that character's screen or history is patched **in place**. There is no
  promotion, because the player did not act;
- the inbox gets **one** unread item for that `message_id` through `WebInboxService::upsertEdit`
  (payload replaced, `read_at` reset, never a second row).

The seam then returns a synthetic `ok:true`, so the caller's edit succeeds and no fallback send
fires. A background `delete*` of a synthetic-range id never reaches Telegram and returns ok. With
the flag off, nothing is written and nothing reaches Telegram. Real-range ids behave exactly as
today. The rule sits at the seam, so it covers every edit-first caller, not only the march.

**#6.** `WebScreenStore::applyCapture` (`:90-146`) reads and writes the state with no lock, so two
concurrent acts of one character lose one update. After this story, `applyCapture` and the new
`patchMessage` run their read-modify-write under one guard. The worker picks the guard: a
transaction with a locking read (`FOR UPDATE`) on the `web_play_state` row, or a version-checked
write with retry. `nextMessageId` is already atomic and stays as it is.

## Requirements
> manual-review major #1 (поход/edit-first для web-сообщений)
> Фоновые сообщения: привязанному игроку уходят в Telegram и копией во входящие на сайте, игроку без Telegram — только во входящие.
> На виртуальный id в Telegram ничего не уходит.
> Ответ бота показывается как экран, а исправление сообщения заменяет экран.
> Для игроков в Telegram бот работает как раньше.
> minor #5,#6,#7,#9,#10,#11

## Files
- app/Services/Web/WebDelivery.php
- app/Services/Web/WebScreenStore.php
- app/Services/Web/WebInboxService.php
- tests/database/WebDeliveryTest.php
- tests/database/WebInboxServiceTest.php
- tests/database/WebScreenStoreTest.php

## Non-goals
- Do not edit `MarchingTaskHandler`, `MarchAction`, `BaseTaskHandler`, `MediaSender` or any other
  edit-first caller. The seam covers them all.
- Do not map synthetic ids to real Telegram message ids, and do not add a table or column (see
  plan Tradeoffs, round 3).
- No change to the capturing actor's edit path: the lookup order and the `ok:false "message to
  edit not found"` fallback from story 04 decision (3), and the story-09 promotion.
- No change to real-range edits or deletes, to the mirror of `send*`, or to `Request.php` /
  `BridgeClient`.
- Do not stop `ensureRow` on the mirror path (finding #8 is accepted, see plan deltas).

## Map slice
- Plan `## Contracts` → Schema (`web_play_state`, `web_inbox`), `Msg`, `WebDelivery`,
  `WebScreenStore`, `WebInboxService` (round-3 lines), A4, A6, A16.
- Story 04 Implementation notes: decisions (1)–(4), the R5 line and the "Surprising" line.
- Story 09 Implementation notes (`applyCapture` promotion, helpers).
- `docs/specs/web-bridge-p1/council/manual-review.md` findings 1 and 6.
- `memory/map/telegram.md`: editOrSend, MediaSender.

## Acceptance criteria
- [ ] The worker runs its own test files singly while iterating. The close-story gate is the three
      commands below, run sequentially on the shared `wildworld_tests`.
- [ ] Web-only, flag on, no capture: `editMessageText` to the virtual chat with a `message_id` on
      the character's current screen returns `ok:true`. The screen copy carries the new text and
      is still the current screen. There is exactly one unread inbox row for that `message_id`.
      Three such edits in a row still leave one row, unread, with the last text.
- [ ] Linked site user (A5), flag on, no capture: the same edit never reaches `parent::send()`
      (the test spy records nothing). The result is the same `ok:true` and the same single inbox
      row. A march-shaped caller (edit, send only when the edit fails) therefore sends nothing new
      to Telegram on repeated steps.
- [ ] A background edit of a synthetic id that sits in history patches the history copy in place,
      and the current screen is unchanged.
- [ ] A background `deleteMessage` of a synthetic id never reaches `parent::send()`.
- [ ] Flag off: a synthetic-range background edit writes nothing and reaches nothing.
- [ ] Ask 5: a real-range `editMessageText` / `deleteMessage` to a linked or bot-only chat reaches
      `parent::send()` unchanged and writes no inbox row. The existing `WebDeliveryTest` and
      `WebInboxServiceTest` cases stay green, unmodified in intent.
- [ ] #6: a test in `WebScreenStoreTest` shows that two `applyCapture` calls for one character, the
      second interleaved between the first's read and write (through a test seam, or two
      connections if the guard is a lock), end with both captures' messages in screen + history.
      The test is red on the pre-fix store. `patchMessage` goes through the same guard.
- [ ] Implementation notes name the guard, and how a locked or conflicting write behaves (it
      waits, or it retries up to N times and then does what).

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
`git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`

## Implementation notes
- `WebDelivery::route`: new A16 branch after the capture case, before the virtual case. For `edit*`/`delete*` with `message_id` ≥ `WebPlay::firstMessageId`, the chat is resolved to a character through `characterForChat($chat, false)`. A synthetic id is proof enough that the player used the web, so the A5 login filter is not applied. No character → the old path. Otherwise nothing goes to Telegram: a delete or flag off returns `okTrue`; an edit applies to the base (screen/history copy → inbox copy → blank msg), then `patchMessage` + `upsertEdit` (`virtual` for a virtual chat, else `mirror`). An edit returns `messageResult` (ok:true with the message). A DB error is logged and still returns `okTrue`.
- Not covered: `deleteMessages` (plural, `message_ids`) and `inline_message_id` edits (no `chat_id`) keep the old path. A background delete does not remove the web copy; the story did not ask for that.
- `WebScreenStore::patchMessage(int, int, Msg): bool` patches in place in the screen and in every history entry, with no promotion. It returns false when the message or the row is missing, and creates no row.
- `WebInboxService::upsertEdit(int, int, Msg, string): void` uses `INSERT … ON DUPLICATE KEY UPDATE payload, read_at = NULL` on the existing `(character_id, message_id)` unique key, then prunes. On update the `source`, `created_at` and row id stay as they were, so the edited row does not jump to the top of `latest()`.
- **Guard (#6):** `WebScreenStore::guarded()` wraps the read-modify-write in a `transBegin` transaction and reads with `SELECT … FOR UPDATE` on the `web_play_state` row. A second writer for the same character **waits** on its read until the first commits, up to MySQL `innodb_lock_wait_timeout` (default 50 s), and then reads the new state. When the wait times out, CI4 does not throw inside a transaction and only clears `transStatus`. `guarded()` checks for that, rolls back, resets `transStatus` (only if it was true before), and throws `RuntimeException`. There is no retry. Inside an outer transaction, CI4 nests it and the lock is held until the outer commit. `applyCapture` still calls `ensureRow` first, outside the transaction.
- Test seam: `protected beforeWrite(int)`, a no-op between the read and the write. `WebScreenStoreTest` (new) runs the second writer on a second connection with a 1 s lock timeout. With the guard, that writer is blocked and repeats after the first commits. Mutation check: dropping `FOR UPDATE` alone turns both interleave tests red (lost update). Turning off the A16 branch in `route()` alone turns the 5 new A16 tests in `WebDeliveryTest` red.
- Surprising (env): other wave-6 workers ran full suites on `wildworld_tests` at the same time, and every overlap produced false errors ("table doesn't exist", deadlocks in migrations). I ran all my suites through a wait-for-no-other-phpunit wrapper.

## Findings
