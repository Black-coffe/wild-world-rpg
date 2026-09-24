---
story: web-bridge-p1-08
spec: web-bridge-p1
status: done
returned: DONE
tier: 2
worker: worker-code
tracer: false
wave: 4
blocked_by: [web-bridge-p1-03, web-bridge-p1-07]
---

# «Играть» never dead-ends: flag checked before login, return to `/play` after login

## Goal
Fix for council round 1, Ask 9 (seat opus, RED). Today `Play::gate()` checks the login **before**
`web.play_enabled`. An anonymous visitor who clicks the header «Играть» while the flag is off
lands on `/account/login` instead of the stub. After login, nothing brings them back to `/play`.
After this story:
- every `/play*` route checks the flag first. With the flag off, **anyone**, logged in or not,
  gets the `flag_off` stub on `GET /play` and a stub or 403 on the other routes, with nothing
  dispatched;
- with the flag on, an anonymous `GET /play` stores a one-shot return target `/play` in the
  session and redirects to `/account/login`. The cabinet (`GET /account`, where the login doors
  land, see Q10) consumes that target once and sends 303 → `/play`.

## Requirements
> Вход в игру легко найти: «Играть» в шапке сайта и в кабинете. Пока флаг выключен, на этом месте видна понятная заглушка, а не пустота.

## Files
- app/Controllers/Play.php
- app/Services/Web/AccountSession.php
- app/Controllers/AccountCabinet.php
- app/Views/site/play_stub.php
- tests/database/PlayControllerTest.php

## Non-goals
- Do not touch the login doors (`AccountAuth`, `AccountOAuth`, `TelegramLogin`, `AccountLink`,
  `AccountRegister`). The only consumer of the return target is the cabinet. If Q10 shows a door
  that does not land on `/account`, report it on INTERFACES. Do not patch that door.
- No generic `?return=` / `redirect_to` parameter taken from the request. The target is set
  server-side and whitelisted to the exact path `/play`, so there is no open redirect.
- No change to the header, the cabinet play block or its lock texts (story 03 is judged fine by
  the seat). No new CSS. No bell in the header (plan A10).
- No change to the logged-in, flag-on behaviour of `/play` (throttle, CSRF, whitelist, dedup,
  bootstrap). Story 07's existing tests stay as they are and stay green.
- Touch `play_stub.php` only if it renders something that needs a session (character name,
  account data). The `flag_off` reason must read correctly for a logged-out visitor.

## Map slice
- `memory/map/website.md`: the `/account` group, session keys (`account_id`, `character_id`),
  the "views work without JS" rule.
- Plan `## Contracts` → Routes (the `/play` table) and View data (`site/play_stub`).
- Story 07 Implementation notes (gate, `PlayControllerTest` fixtures); story 03 notes (cabinet
  `PLAY_FLAG`, `GameSettingsReaderTrait`).

## Acceptance criteria
- [ ] Flag off, no session: `GET /play` → 200 with the `flag_off` stub, not a redirect to
      `/account/login`. The body has the readable explanation, not an empty page.
- [ ] Flag off, no session: `POST /play/act`, `GET /play/inbox` and `POST /play/inbox/read` each
      return the stub or 403, never a login redirect, and dispatch nothing.
- [ ] Flag off, logged in: unchanged, the `flag_off` stub.
- [ ] Flag on, no session: `GET /play` → redirect to `/account/login`, and the session holds the
      return target `/play`.
- [ ] Flag on, after login: the first `GET /account` with the target set → 303 `/play`, and the
      target is cleared. The next `GET /account` renders the cabinet. With no target set, the
      cabinet renders as before.
- [ ] `AccountSession` stores only the exact `/play` path. Any other value is ignored (test).
- [ ] The existing `PlayControllerTest` cases stay green, unmodified in intent.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
`git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`

## Implementation notes
- `Play::gate()` now checks the flag before the session. With the flag off, a guest gets the `flag_off` stub (200 on GET /play, 403 on act/inbox/read). Only `index()` stores the return target when the gate hands back the login redirect.
- `AccountSession::rememberReturnTarget(string)` / `consumeReturnTarget(): ?string`, key `play_return_to` (added to the logout `ALL_KEYS`). Only the exact string `/play` is stored or returned, and the key is removed on every consume.
- `AccountCabinet::index()` consumes the target after the login check and returns 303 `/play`. Any `?auth=` notice on that request is dropped: the player lands on /play instead.
- `play_stub.php` was not touched. It reads no session data and the `flag_off` text reads fine for a guest.
- Q10 door survey: AccountAuth, AccountLink, AccountRegister(char created) and TelegramLogin from /account/login (`next=/account`) all land on `/account`. AccountOAuth for a **new** account and AccountRegister land on `/account/character` and do not consume the target. That player has no character yet, so /play would only show the stub. The target stays in the session until the next `/account` visit (AccountRegister returns to `/account` after character creation, so it is consumed then).
- Tests: 4 new cases in PlayControllerTest. The 12 existing cases were not changed.

## Findings
