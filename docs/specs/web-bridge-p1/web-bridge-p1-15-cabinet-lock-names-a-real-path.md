---
story: web-bridge-p1-15
spec: web-bridge-p1
status: done
returned: DONE
tier: 2
worker: worker-code
tracer: false
wave: 7
blocked_by: [web-bridge-p1-08, web-bridge-p1-13]
---

# The cabinet's no-character lock names a path that works

## Goal
Fix for council round 4, Ask 9 (seat opus, RED). The cabinet «Играть на сайте» block, in its
no-character state, says «сначала привяжи персонажа из бота кодом /web», and the cabinet notice says
«Привязать персонажа из бота» (`app/Views/site/account_cabinet.php`, around lines 55-70). Anyone who
sees the cabinet is logged in, and `LinkCodeService::link()` refuses a code while another account is
signed in (`MSG_OTHER`, F1, plan A15). So the lock sends the player down a path that fails. It also
never offers `/account/character`, which is the web-only player's real path (the `/play` stub
already offers it through `can_register`).

After this story, for a logged-in account with no character:
- when character registration on the site is open (the same condition `Play` uses for the stub's
  `can_register`), the lock offers a link to `/account/character`;
- for a player whose character lives in the bot, the lock and the notice say to log out of this
  login and enter the `/web` code («выйди из другого входа и введи код», the same instruction as
  `/web`, the tip and `/guide`), with the logout and `/account/link` as the way;
- no text in the cabinet tells a logged-in player to link or attach a bot character with the code
  while staying logged in.
The flag-off lock and the flag-on link states stay as they are.

## Requirements
> Вход в игру легко найти: «Играть» в шапке сайта и в кабинете. Пока флаг выключен, на этом месте видна понятная заглушка, а не пустота.
> «выйди из другого входа и введи код»

## Files
- app/Views/site/account_cabinet.php
- app/Controllers/AccountCabinet.php
- tests/database/PlayControllerTest.php

## Non-goals
- No change to `LinkCodeService`, its `MSG_*` texts or F1–F3 (plan A15). Do not make `link()` accept
  a logged-in account.
- No change to `/web`, the tip, `/guide`, the header or the `/play` stub (Ask 8 is green).
- Do not touch `app/Views/site/account_link.php`, although its lines 30 and 52 still say «код привяжет
  этот вход» (story 03 notes). That is surfaced to the owner as an open question, not fixed here.
- `AccountCabinet.php`: change it only to pass the registration condition to the view if the view
  does not already get it. Read the condition the way `Play` reads it; do not add a new setting.
- No new CSS or component. Use the cabinet's existing link, notice and lock classes.
- No change to the return-target logic from story 08.

## Map slice
- `memory/map/website.md`: the `/account` group, cabinet, the ADR-188 no-merge rule, "views work
  without JS".
- Story 03 Implementation notes (cabinet block, three states, `PLAY_FLAG`). Story 08 notes (cabinet
  consumes the return target; `PlayControllerTest` fixtures render `GET /account`).
- Plan `## Contracts` → View data (`site/play_stub` `can_register`); A15.
- `docs/specs/web-bridge-p1/council/round-4/opus.md` ASK 9.

## Acceptance criteria
- [ ] The worker runs its own test file singly while iterating. The close-story gate is the three
      commands below, run sequentially on the shared `wildworld_tests`.
- [ ] Rendered test (`GET /account`, not a source scan): logged in, no character, flag on,
      registration open → the page has a link to `/account/character`, and contains neither
      «привяжи персонажа из бота» nor «Привязать персонажа из бота».
- [ ] Same account, registration closed → no `/account/character` link. The lock still shows a
      readable reason, not an empty spot.
- [ ] In both cases the page carries the log-out-then-enter-the-code instruction for a bot player,
      and no text promises that the code links a bot character to this login.
- [ ] Flag off, logged in with and without a character: the lock line «🔒 Игра на сайте (скоро)» and
      its reason are unchanged. Flag on with a character: the `/play` link is unchanged.
- [ ] The existing `PlayControllerTest` cases stay green, unmodified in intent.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
`git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`

## Implementation notes
- `AccountCabinet::render()` passes `canRegister => AccountRegister::registrationOpen()` (same call `Play` uses for `can_register`); the existing `playEnabled` line is left byte-identical because `WebPlayTextsTest` source-scans it.
- `account_cabinet.php`, no-character: the notice no longer says «привязать»; it says «выйди из другого входа и введи код» and carries a logout form (button «Выйти, чтобы ввести код») + link to `/account/link`, plus «Создать персонажа» → `/account/character` when registration is open. The flag-on lock splits: open → link to `/account/character`; closed → reason «создание персонажей на сайте сейчас закрыто». Both carry the logout-then-code line. Flag-off lock and `/play` link untouched.
- Also removed the Telegram `<noscript>` «Или возьми код в боте командой /web» in the same view: it told a logged-in player to use the code while staying logged in (the story's third bullet). One sentence, same file.
- `PlayControllerTest`: 3 rendered `GET /account` tests (reg open / reg closed / flag-off + flag-on unchanged). Bodies must be `html_entity_decode`d — `esc(..., 'attr')` hex-escapes `/` in hrefs.
- Surprise: the first full-suite run went red with 76 schema errors (deadlocks, «table doesn't exist») from another worker's concurrent phpunit on the shared `wildworld_tests`; rerun after it finished.

## Findings
