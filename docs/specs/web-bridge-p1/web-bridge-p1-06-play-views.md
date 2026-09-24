---
story: web-bridge-p1-06
spec: web-bridge-p1
status: todo
returned:
tier: 3
worker: worker-code
model: opus
tracer: false
wave: 2
blocked_by: [web-bridge-p1-02]
---

# `/play` views: screen, dock, history, input, bell, inbox, stub; progressive JS

## Goal
The views render the plan's view-data contract with the story-02 components. Story 07 wires
them to the controller.

- `site/play` extends `site/layout`. It has:
  - a top bar with the character name and the bell + unread counter;
  - the `#play-state` container holding `site/_play/state`;
  - an inbox panel slot.
- `site/_play/state` renders:
  - the current screen: each `Msg` as text or photo + full caption through
    `TelegramMarkupRenderer::toHtml()`;
  - inline buttons as `<form method=post action=/play/act>` with `kind=callback`, `data`,
    `message_id`, a random `intent_id` and the CSRF field; `url` buttons are external links
    `rel="noopener"`;
  - the history strip: previous screens, compact; their buttons stay pressable;
  - the text input: `kind=text`, placeholder from `input`, `message_id` for a force-reply;
  - the bottom dock: each reply-keyboard button is a form posting `kind=text` with its label.
    An empty dock shows one «Меню» button posting `kind=command`, `/menu`, so navigation is
    never lost (constitution rule 5, ONBOARDING-COVERAGE);
  - the alert, when present.
- `site/_play/inbox` lists items newest first with unread marks. Their inline buttons post like
  screen buttons.
- `site/play_stub` covers `flag_off` («Игра на сайте скоро: включается после проверки» + a link
  to the bot and `/account`) and `no_character` (a link to `/account/character` when
  `can_register`, otherwise the `/web` code path).

`public/assets/js/wildworld-play.js` is enhancement only:
- it intercepts the play forms, posts them with `fetch` and `Accept: application/json`, swaps
  `#play-state` with `html`, updates the CSRF token and shows the alert;
- it polls `GET /play/inbox` every `poll_seconds` (never below the server minimum) for the bell
  counter;
- the bell opens the panel and posts `/play/inbox/read`;
- one request is in flight at a time, and taps are disabled while it runs.

Without JS every form still works through PRG.

## Requirements
> Меню бота становится нижним доком. Есть короткая лента предыдущих экранов.
> На сайте есть колокольчик со счётчиком.
> `/play` читается на ширине 375/768/1440 без горизонтальной прокрутки.
> Пока флаг выключен, на этом месте видна понятная заглушка, а не пустота.

## Files
- app/Views/site/play.php
- app/Views/site/play_stub.php
- app/Views/site/_play/state.php
- app/Views/site/_play/inbox.php
- public/assets/js/wildworld-play.js
- tests/unit/Views/PlayViewsTest.php

## Non-goals
- No controller, routes or services. Story 07 owns them. Test the views with fixture arrays via
  `view()`.
- No new CSS. If a component is missing, report it for story 02 rather than inlining styles.
- No HUD, map canvas, native screens, Web Push or SSE (plan A14).
- No telegram or chat id in markup or JS, not even in `data-*` (ADR-189 invariant 6).

## Map slice
- `memory/map/website.md`: views work without JS, flat rules, CSS `?v=`.
- `memory/map/onboarding.md`: the reply menu is re-attached only by `/start`//`/menu`.
- Plan `## Contracts`: View data, Routes, the CSS classes pasted after story 02.
- UX reference `docs/specs/web-first-client/webgame-preview.html`: dock `:926-931`, bell
  `:2212`.

## Acceptance criteria
- [ ] The worker runs its own new test file singly while iterating. The close-story gate is the
      full suite, phpstan and the migrations lint.
- [ ] Ask 3: with a fixture screen of a photo `Msg` with a 1000+ character Markdown caption and
      `photo_url=null`, the rendered HTML contains the whole caption as rendered HTML and no
      broken `<img>`. `<script>` in text renders inert.
- [ ] Ask 3: the dock renders one form per reply button with the button label as `data`. An empty
      dock renders the «Меню» fallback. History renders up to the fixture's length, newest
      first.
- [ ] Ask 4: the bell shows the unread count from view data, with no count shown at 0. The inbox
      fragment marks unread items.
- [ ] Ask 9: `play_stub` renders a readable explanation for both reasons, and neither renders an
      empty page.
- [ ] Every form carries the CSRF field and a unique `intent_id`. No rendered output contains the
      fixture's `telegram_id`.
- [ ] Ask 10: the views use only story-02 classes and tokens, with no inline `style` colours,
      radii or shadows. The Queen checks for no horizontal scroll at 375/768/1440 and a clean
      console (Tier-2) once story 07 lands.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
`git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`

## Implementation notes

## Findings
