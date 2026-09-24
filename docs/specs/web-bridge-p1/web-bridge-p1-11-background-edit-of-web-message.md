---
story: web-bridge-p1-11
spec: web-bridge-p1
status: todo
returned:
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

## Findings
