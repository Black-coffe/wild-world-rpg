---
story: web-bridge-p1-13
spec: web-bridge-p1
status: todo
returned:
tier: 3
worker: worker-code
model: opus
tracer: false
wave: 6
blocked_by: [web-bridge-p1-08]
---

# `/play` error path: rejected taps answer in place, a failed bootstrap still renders, photo `src` is safe

## Goal
Fix for manual review (round 3), major #2 and minors #9 and #10.

- **#2.** Today `wildworld-play.js` (`:77,101`) falls back to `form.submit()` on **any** non-2xx
  reply, and it sends the form's old token. CSRF `regenerate=true` has already rotated that
  token, so a designed 400 («Эта кнопка уже недоступна») or a throttle 429 ends on the framework
  403 page or on raw JSON. After this story:
  - **Server:** the JSON 429 from `AccountThrottleFilter` with the `play` or `inbox` argument
    carries `{alert, csrf}`. The `GET /play/inbox` JSON carries `csrf`. The 400 act reply keeps
    `{html, unread, alert, csrf}`. The shapes are in plan Contracts → Routes.
  - **JS:** any response with a JSON body, whatever its status, is handled in place. The JS updates
    every token field from `csrf`, swaps `#play-state` when `html` is present, shows `alert`,
    updates `unread` when present, and re-enables the taps. Only a network failure (fetch
    rejects) or an unparseable body falls back to PRG. Before that submit, the JS takes a current
    token from `GET /play/inbox`. If that request fails too, it reloads `/play` and does not submit
    a stale token.
- **#9.** `Play::index` (`:56`) degrades only on `InvalidArgumentException`. After this story,
  any `Throwable` from the first-visit bootstrap is logged (`error`). `/play` still renders
  `site/play` with the stored state, or an empty one, and with the failure alert. There is no 500
  page.
- **#10.** The photo guard in `_play/state.php` (`~^(https?://|/)~`) also accepts
  protocol-relative `//host/...`. After this story an `<img src>` is rendered only for `http(s)://`
  or a site-relative path whose second character is not `/`. Anything else gets the caption
  alone, as A12 describes.

## Requirements
> #2 (JS 400/429 → 403 CSRF)
> minor #5,#6,#7,#9,#10,#11
> Действия с сайта защищены (CSRF, лимит частоты)
> Вошедший на сайт игрок играет на `/play` через те же маршруты бота (кнопки, текстовые ответы, команды).
> Фото идут с полной подписью, и экран понятен без картинки.

## Files
- app/Controllers/Play.php
- app/Filters/AccountThrottleFilter.php
- app/Views/site/_play/state.php
- public/assets/js/wildworld-play.js
- tests/database/PlayControllerTest.php
- tests/unit/Views/PlayViewsTest.php

## Non-goals
- No change to `WebActService` (story 12) or to the store, inbox or delivery (story 11).
- No change to the P0 `accountThrottle` behaviour with no argument (the `/account/*` POSTs), nor
  to its limits.
- Do not relax CSRF, turn off `regenerate`, or exempt `/play/*` from the global filter.
- No new CSS, no new view file, no change to the no-JS PRG path of a successful act.
- Do not add a JS test framework. The JS is proved by the Queen's Tier-2 below.

## Map slice
- Plan `## Contracts` → Routes (round-3 JSON shapes), View data; Q6 answer (CSRF names,
  `regenerate`).
- Story 06 Implementation notes (JS token handling, `data-csrf-name`, photo guard, DOM-based view
  tests). Story 07 notes (the "Surprise for story 06's JS" line, the 400 body, `FAILED_ALERT`).
  Story 08 notes (`gate()` order).
- `docs/specs/web-bridge-p1/council/manual-review.md` findings 2, 9 and 10.

## Acceptance criteria
- [ ] The worker runs its own test files singly while iterating. The close-story gate is the three
      commands below, run sequentially on the shared `wildworld_tests`.
- [ ] #2 server: in `PlayControllerTest`, a JSON act past `actsPerMinute` → 429 with a non-empty
      `alert` and a `csrf`. A rejected callback → 400 with `html`, `alert` and `csrf`.
      `GET /play/inbox` JSON has `csrf`. A no-argument `accountThrottle` 429 is unchanged
      (existing tests).
- [ ] #2 JS: the code has one handler for JSON bodies of every status, and a PRG fallback that is
      reached only from the fetch-rejected / parse-failed branch, and only after a token refresh
      or with a reload. Implementation notes quote the two branch conditions. The Queen's Tier-2
      then taps a button made stale by a second tab, sees the alert in place (no 403 page), and
      exceeds the throttle to see the alert in place.
- [ ] #9: with the bootstrap seam throwing `RuntimeException`, `GET /play` → 200, `site/play`
      rendered with the failure alert, and an `error` log line.
- [ ] #10: `PlayViewsTest` renders `photo_url` values `//evil.example/x.png` → no `<img>`, caption
      in full; `/uploads/x.png` → `<img>`; `https://…` → `<img>`.
- [ ] The existing `PlayControllerTest` and `PlayViewsTest` cases stay green, unmodified in intent.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
`git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`

## Implementation notes

## Findings
