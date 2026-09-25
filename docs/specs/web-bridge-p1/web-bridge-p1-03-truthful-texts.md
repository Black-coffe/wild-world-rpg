---
story: web-bridge-p1-03
spec: web-bridge-p1
status: done
returned: DONE
tier: 3
worker: worker-code
model: opus
tracer: false
wave: 1
blocked_by: []
---

# Truthful `/web` text, tip and guide; «Играть» in the header and the cabinet

## Goal
Close the Phase-0 text tail and give web play a discoverable entry.

**Texts.** Today three texts promise that the code "links this login to the character". Code F1
refuses that. The three texts are `WebLinkCodeAction::codeMessage()` (`:67-68`), the tip seeded
by `2026-12-10-100020_SeedWebLinkTip.php` (`:31-33`, already on prod, seed is idempotent) and the
`/guide` section `web` (`GuideCatalog.php:1019-1021`). They must say instead: the code logs you
into the character's account; if you are signed in with another method, first «выйди из другого
входа и введи код». They also describe playing on the site conditionally (plan A11): when the
header shows «Играть», the game runs in the browser. The tip is changed by a **new** UPDATE
migration.

**Entry points.** The site header gets an «Играть» link to `/play`. The cabinet gets an «Играть
на сайте» block: when `web.play_enabled` is on, it links to `/play`; when off, it shows a lock
line «🔒 Игра на сайте (скоро)» with a one-line reason. `/play` itself renders the stub when the
flag is off (stories 06/07).

## Requirements
> Текст `/web`, совет и /guide говорят правду о привязке и об игре на сайте. Совет обновляется миграцией, пишется тоном Роби, без чисел.
> В этой же спеке текст `/web`, совет (новой миграцией) и guide будут говорить правду: «выйди из другого входа и введи код».
> Вход в игру легко найти: «Играть» в шапке сайта и в кабинете. Пока флаг выключен, на этом месте видна понятная заглушка, а не пустота.

## Files
- app/Controllers/Telegram/Commands/Actions/WebLinkCodeAction.php
- app/Database/Migrations/2026-12-11-100010_UpdateWebLinkTipForWebPlay.php
- app/Services/Onboarding/GuideCatalog.php
- app/Views/site/_layout/header.php
- app/Views/site/account_cabinet.php
- app/Controllers/AccountCabinet.php
- tests/unit/Web/WebPlayTextsTest.php

## Non-goals
- No change to `LinkCodeService` logic or its `MSG_*` refusal texts. F1–F3 stay (plan A15).
- Do not edit `2026-12-10-100020_SeedWebLinkTip.php`, because it has already run on prod. The
  change goes through the new UPDATE migration only.
- No second tip. Update the existing row, found by its `title_en`.
- No new CSS. Use the existing link/notice/button classes. New components belong to story 02.
- No numbers in the tip or guide (no TTL, no limits). The TTL stays in the `/web` message only.
- No bell or unread count in the header (plan A10).

## Map slice
- `memory/map/telegram.md`: `/web` gotcha 2026-09-24, legacy Markdown, media-off.
- `memory/map/onboarding.md`: GuideCatalog rules (read-only, `[a-z]` key, no numbers), tip seed
  idempotency by `title_en`, 14-value ENUM.
- `memory/map/website.md`: header auth state, cabinet, the ADR-188 no-merge rule.

## Acceptance criteria
- [ ] The worker runs its own new test file singly while iterating. The close-story gate is the
      full suite, phpstan and the migrations lint.
- [ ] Ask 8: none of the three texts promises that the code links or attaches another login.
      Each one tells a player signed in elsewhere to log out first and then enter the code
      («выйди из другого входа и введи код»). Each one mentions playing on the site
      conditionally, not as available now. The test asserts both the absence of the old promise
      and the presence of the new instruction in the `/web` message and in the `GuideCatalog`
      section.
- [ ] Ask 8: the migration UPDATEs the existing tip by `title_en`, is idempotent, and its `down()`
      restores the previous text. The text is in Robi's voice, has no numbers, and keeps its
      category («настройки»). `php -l` is clean.
- [ ] The `/web` message stays legacy-Markdown-safe and text-only (media-off), and it still states
      the code, `wildworld.fun/account/link` and the lifetime in minutes.
- [ ] `/guide` `web` stays read-only and markdown-safe, and the existing guide tests are green.
- [ ] Ask 9: the header shows «Играть» → `/play` on every site page, logged in or not. The
      cabinet shows the play block: a link when the flag is on, the lock line with its reason
      when off. The flag is read server-side through the existing GameSettings reader. Nothing
      is hidden: the flag-off state is visible text, not an empty spot.
- [ ] No horizontal scroll at 375 px is introduced in the header.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
`git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`

## Implementation notes
- `WebLinkCodeAction::codeMessage()`: old "привяжет этот вход" gone; now "код впускает в аккаунт этого персонажа… сначала выйди из другого входа и введи код" + `PLAY_HINT` const (conditional: «когда игру на сайте откроют, кнопка «Играть» в шапке…»). Code, path, TTL kept.
- Conditional wording chosen over plan A11's «если в шапке есть «Играть»»: this story puts «Играть» in the header unconditionally, so a header-based condition would always read true.
- `GuideCatalog` `web`: 🔗 bullet rewritten, new 🎮 «Игра в браузере» bullet; no numbers.
- New migration `2026-12-11-100010_UpdateWebLinkTipForWebPlay`: UPDATE by `title_en='WebLinkCode'`, public `NEW_CONTENT`/`OLD_CONTENT`; `down()` restores the seed text; `tip_type` untouched.
- Header: plain «Играть» → `/play` in desktop nav and drawer (`data-nav="play"`); the Telegram CTA button relabelled «▶ Играть» → «▶ Telegram» so desktop does not show two «Играть». Desktop nav is hidden ≤900px, so 375px width is unaffected.
- Cabinet: `AccountCabinet` uses `GameSettingsReaderTrait`, `PLAY_FLAG='web.play_enabled'`, passes `playEnabled`; view block «Играть на сайте» has three states: lock «🔒 Игра на сайте (скоро) — …» (flag off), lock «(нужен персонаж)» (flag on, no character), link to `/play`.
- Test `tests/unit/Web/WebPlayTextsTest.php`: renders the header; the cabinet is checked by source-scan only, because the layout hits the DB in unit context.
- Surprising: `app/Views/site/account_link.php:30,52` still promises «код привяжет этот вход» — not in this story's files, left as is.
- Full suite on the shared tree: 106 errors + 2 failures, all in `tests/database` outside this story (Standoff*/Streak*/Title*/Tax*/Transport*/WebOnlyCharacterWorker: "Failed to open the referenced table 'characters'"; AccountRegistration/CharacterProvisioning: telegram_user_id asserted null — tests being edited by story 01 in the same working tree). TipService/LinkCodeService/AccountCabinet pass when run alone.

## Findings
