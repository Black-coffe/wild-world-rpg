---
story: web-bridge-p1-17
spec: web-bridge-p1
status: done
returned: DONE
tier: 2
worker: worker-code
tracer: false
wave: 9
blocked_by: [web-bridge-p1-15]
model: opus
---

# `/account/link` and the `/play` no-character lock tell the truth under F1

## Goal
Fix for council round 6, Ask 9 (seat opus, RED) and review round 6 Major #1 (carried from round 5).
Under F1 (plan A15, ADR-188) `LinkCodeService::link()` refuses a code while another account is
logged in (`MSG_OTHER`). Two texts still promise the opposite:
- `app/Views/site/account_link.php:30` (logged-in notice «Код привяжет твой текущий вход к
  персонажу…») and `:52` («Вошёл почтой, Google или Яндексом — …привяжет…»);
- `app/Views/site/play_stub.php:35-36` (`no_character` lock, reachable only logged in): «отправь боту
  /web … войдёшь в своего персонажа» → «Ввести код», which then refuses.

After this story, both pages say what the code does: logged out, the code lets you into the
character's account; logged in to another login, first log out, then enter the code («выйди из
другого входа и введи код» — the same instruction as `/web`, the tip, `/guide` and the cabinet after
story 15). The `/play` `no_character` lock, when `can_register` is true, keeps «Создать персонажа»
and also gives the bot player the log-out-then-code path. Text only; no change to `LinkCodeService`.

## Requirements
> Вход в игру легко найти: «Играть» в шапке сайта и в кабинете. Пока флаг выключен, на этом месте видна понятная заглушка, а не пустота.
> «выйди из другого входа и введи код»
> Текст `/web`, совет и /guide говорят правду о привязке и об игре на сайте.

## Files
- app/Views/site/account_link.php
- app/Views/site/play_stub.php
- tests/unit/Views/PlayViewsTest.php
- tests/database/AccountLinkPageTest.php

## Acceptance criteria
- [ ] Rendered (not a source scan): `/account/link` logged in → no text says the code links/attaches
      the current login to a character; the page carries the log-out-then-enter-the-code
      instruction and a working logout. Logged out → the page says the code lets you into the
      character's account (unchanged meaning).
- [ ] Rendered `/play` stub `no_character`, `can_register` true and false: no text promises that the
      code works while logged in; the log-out-then-code path is present; with `can_register` the
      «Создать персонажа» link is kept.
- [ ] Flag-off stub and flag-on states are unchanged; existing `PlayViewsTest` cases stay green.
- [ ] Only `wildworld-ui.css` classes already in `public/ui-kit.html`; no inline styles; no balance numbers.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
`git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`

## Map slice
`memory/map/website.md` (account pages, F1 refusal)

## Implementation notes
- `account_link.php`: the logged-in notice now uses the story-15 instruction «выйди из другого входа и введи код» and comes with a CSRF logout form («Выйти, чтобы ввести код»). The right-card line for email/Google/Yandex users says log out, then enter the code. The guest meaning is unchanged. No text on the page says «привяж».
- `play_stub.php`: `no_character` in both `can_register` states now uses a logout form (the same pattern as the cabinet): «Создать персонажа» shows only with `can_register`, then «Выйти, чтобы ввести код», «Страница ввода кода» and «Открыть бота». The flag_off markup is unchanged. Only existing classes are used (`auth-form`, `auth-actions`, `btn`, `mt-2`).
- `tests/database/AccountLinkPageTest.php` is a new file: it renders `/account/link` over HTTP, logged in and logged out, and checks that a POST to logout ends the session. `PlayViewsTest` gains 2 stub tests. I checked all new tests against the HEAD views: they fail there (3 failures) and pass on the new ones.
- Surprise: logout redirects to `/account/login?auth=logged_out`, not to `/account/link`. The player has to open the code page themselves. Changing that is outside this story (it's in the `AccountAuth` controller).

## Findings
