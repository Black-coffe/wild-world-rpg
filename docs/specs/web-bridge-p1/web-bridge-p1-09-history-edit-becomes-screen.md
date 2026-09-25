---
story: web-bridge-p1-09
spec: web-bridge-p1
status: done
returned: DONE
tier: 2
worker: worker-code
tracer: false
wave: 5
blocked_by: [web-bridge-p1-04]
---

# An edit of a past screen's message becomes the current screen

## Goal
Fix for council round 2, Ask 3 (seat opus, RED). Today `WebScreenStore::applyCapture` (`:96-120`)
patches an edited message **in place wherever it is**. When the message sits in history, the
history entry changes and the current screen stays as it was. History items render live inline
keyboards (`_play/state.php:151`) and `callbackAllowed` accepts them. So a tap on a past screen
that the bot answers with an edit (the usual callback pattern) changes a dimmed entry, and the
player sees no reaction on the main screen. A stored-id edit (`last_map_message_id` fallback
paths) hits the same case. An edit of an **inbox** message is already promoted to the screen
(`WebDelivery:245-248`); this story gives history the same rule.
After this story, an edit whose target sits in history makes the edited message the current
screen. The previous current screen moves to history, capped at `historySize`, and the stale
copy of the edited message leaves its old history entry. An entry that becomes empty is dropped.
An edit of a message on the current screen still replaces it in place, as today.

## Requirements
> Ответ бота показывается как экран, а исправление сообщения заменяет экран.
> Исправление сообщения становится сменой экрана.

## Files
- app/Services/Web/WebScreenStore.php
- app/Services/Web/WebDelivery.php
- tests/database/WebDeliveryTest.php

## Non-goals
- Do not make history read-only: no stripping of history keyboards in `_play/state.php` and no
  narrowing of `callbackAllowed`. The views and the whitelist are not in this story.
- Do not change edit handling for the current screen, the inbox promotion, the virtual-chat drop
  or the `ok:false "message to edit not found"` fallback for unknown ids.
- Do not touch `Request.php`, `BridgeClient`, the view files or CSS.
- Touch `WebDelivery.php` only if the cleanest fix reuses the inbox-promotion path there. If
  `WebScreenStore` alone suffices, leave it as it is.

## Map slice
- Plan `## Contracts` → Schema (`web_play_state`: `screen`, `history`), `Msg`, `WebScreenStore`,
  `WebDelivery::endCapture` shape.
- Story 04 Implementation notes, decision (3) (edit lookup order and inbox promotion).
- Council report `docs/specs/web-bridge-p1/council/round-2/opus.md`, line `ASK 3`.

## Acceptance criteria
- [ ] Ask 3: the state holds screen S1 (message A) and then S2 (message B), so S1 is in history.
      A capture carrying only `editMessageText` of A yields: the current screen is A with the
      edited text; S2 is the newest history entry; A no longer appears in its old history entry,
      and an entry left empty is removed. No `message_id` appears twice across screen + history.
- [ ] Ask 3: an edit of a message on the current screen still replaces it in place, and history
      is unchanged (an existing test stays green).
- [ ] A capture that both edits a history message and sends new messages ends with all of them
      on the current screen, the edited one first, and one history push only.
- [ ] History stays capped at `historySize` after a promotion.
- [ ] `callbackAllowed` still accepts a button on the promoted message, and `findMessage` finds
      it by its original id.
- [ ] The existing `WebDeliveryTest` and `PlayControllerTest` cases stay green, unmodified in
      intent.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes
- `WebScreenStore::applyCapture`: an edit whose id is on the current screen replaces it in place; an
  edit whose id sits in history is removed from history (empty entries dropped) and collected as
  "promoted". New screen = promoted ++ sent, one history push, then cap. Deletions also apply to
  promoted. New private helpers `contains`, `removeFromHistory`. `WebDelivery.php` not touched.
- Decision: a promotion-only capture is a screen change, so `input` takes `capture['input']` exactly
  as a send does (a stale force-reply for the old screen is dropped).
- `testEditReplacesMessageWhereverItIsIncludingHistory` asserted the old behaviour this story
  reverses; replaced by `testEditOfHistoryMessageBecomesCurrentScreen`. Added 3 more tests (in-place
  current edit, edit+sends, cap after promotion). The 3 history tests go red on the HEAD store.
- Surprise (env): the first full-suite run hung for about 50 min alongside a second phpunit from
  another session. I killed my own run, which left an empty `resources_bank` in `wildworld_tests`,
  so the next run had 151 FK errors. I dropped that one table and reran: green.

## Findings
