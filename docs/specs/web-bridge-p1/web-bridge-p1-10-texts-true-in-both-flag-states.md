---
story: web-bridge-p1-10
spec: web-bridge-p1
status: done
returned: DONE
tier: 2
worker: worker-code
tracer: false
wave: 5
blocked_by: [web-bridge-p1-03]
---

# `/web`, the tip and `/guide` stay true when the owner flips `web.play_enabled`

## Goal
Fix for council round 2, Ask 8 (seat opus, RED). Story 03's three texts are static and describe
web play as not yet open: «Когда игру на сайте откроют … Пока там заглушка — играй в боте/здесь».
They are `WebLinkCodeAction::PLAY_HINT`, the `GuideCatalog` section `web` (🎮 bullet near `:1023`)
and `NEW_CONTENT` of migration `2026-12-11-100010_UpdateWebLinkTipForWebPlay`. None of them reads
the flag. The owner flips `web.play_enabled` in the admin without a deploy (Ask 7), and from that
moment all three are false. A web-only player who opens `/guide` inside `/play` is told there is
only a stub.
After this story, every one of the three texts is **true in both flag states**. It describes web
play without claiming whether it is open now or later: the same character and the same game in
the browser, reached through «Играть» in the site header after logging in, and the `/play` page
itself says what to do if play there is still closed. The linking wording stays as it is: the code
logs you into the character's account, and a player signed in elsewhere first «выйди из другого
входа и введи код». The tip changes through a **new** UPDATE migration.

## Requirements
> Текст `/web`, совет и /guide говорят правду о привязке и об игре на сайте. Совет обновляется миграцией, пишется тоном Роби, без чисел.

## Files
- app/Controllers/Telegram/Commands/Actions/WebLinkCodeAction.php
- app/Services/Onboarding/GuideCatalog.php
- app/Database/Migrations/2026-12-11-100011_WebPlayTipTrueInBothFlagStates.php
- tests/unit/Web/WebPlayTextsTest.php

## Non-goals
- No flag reads in these texts, and no two variants per text. The fix is wording that holds in
  both states (see plan Tradeoffs). Do not add a `GameSettings` lookup to `GuideCatalog` or
  `WebLinkCodeAction`, and do not touch `TipService`.
- Do not edit `2026-12-11-100010_UpdateWebLinkTipForWebPlay.php` or `SeedWebLinkTip`. The new
  migration UPDATEs the same row by `title_en`.
- Do not change the linking instruction, the code, `wildworld.fun/account/link` or the lifetime
  in the `/web` message. Do not change `LinkCodeService` texts (F1–F3 stay, plan A15).
- Do not touch the header, the cabinet lock line, `play_stub.php` or `account_link.php`.
- No numbers in the tip or the guide.

## Map slice
- `memory/map/telegram.md`: `/web` gotcha 2026-09-24, legacy Markdown, media-off.
- `memory/map/onboarding.md`: GuideCatalog rules (read-only, `[a-z]` key, no numbers), tip UPDATE
  by `title_en`, 14-value ENUM.
- Story 03 Implementation notes (`PLAY_HINT`, `NEW_CONTENT`/`OLD_CONTENT`, `title_en='WebLinkCode'`).
- Council report `docs/specs/web-bridge-p1/council/round-2/opus.md`, line `ASK 8`.

## Acceptance criteria
- [ ] Ask 8: none of the three texts claims that web play is closed, coming, or behind a stub
      («откроют», «пока там заглушка», «скоро» and similar are absent). None claims it is open
      right now either. Each one names «Играть» in the site header as the way in.
- [ ] Ask 8: each of the three still carries the linking truth: the code logs you into the
      character's account, and «выйди из другого входа и введи код». No text promises that the
      code links or attaches another login.
- [ ] Ask 8: the new migration UPDATEs the tip by `title_en='WebLinkCode'`, is idempotent, and its
      `down()` restores the story-03 text (`UpdateWebLinkTipForWebPlay::NEW_CONTENT`). The text is
      in Robi's voice, has no numbers, and keeps its category («настройки»). `php -l` is clean.
- [ ] `WebPlayTextsTest` asserts the two points above on the `/web` message, the `GuideCatalog`
      `web` section and the new migration's text constant.
- [ ] The `/web` message stays legacy-Markdown-safe and text-only (media-off), and it still states
      the code, `wildworld.fun/account/link` and the lifetime in minutes.
- [ ] `/guide` `web` stays read-only and markdown-safe; the existing guide and tip tests are green.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
`git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`

## Implementation notes
- `WebLinkCodeAction::PLAY_HINT`, `GuideCatalog` `web` 🎮 bullet: rewritten to «Играть» в шапке + «Если играть на сайте сейчас нельзя, страница сама подскажет, что делать». No flag read.
- New migration `2026-12-11-100011_WebPlayTipTrueInBothFlagStates` (class `WebPlayTipTrueInBothFlagStates`): UPDATE by `title_en`; `OLD_CONTENT` is a verbatim copy of `UpdateWebLinkTipForWebPlay::NEW_CONTENT` (migration files are not autoloadable by class name, so no cross-reference) and the test asserts they are identical.
- `WebPlayTextsTest`: the story-03 «Когда игру на сайте откроют» assertion replaced by a banned-claims regex list (откроют/открыт(а|о)/заглушк/скоро/пока/ещё не/появится/уже можно…) + required «сейчас нельзя, страница сама»; the 100010 test now only checks linking truth there (its text is superseded). New test for 100011.
- Surprise: `/откро/` would have matched «Открой wildworld.fun…» in the `/web` message, so the regex names inflected forms only.
- Verification: full `phpunit` did not finish (>40 min) because another agent's full suite (PID 66500) ran concurrently on the shared `wildworld_tests` DB; killed my own run. Ran instead: tests/unit/Services/Onboarding (236 OK), tests/unit/Web, tests/unit/Content, CallbackRoutesResolveTest - green; tests/unit/Transport VehicleRepairTest 3 errors = FK drop on `resources` (DB state, unrelated). phpstan OK, migrations `php -l` clean.

## Findings
