---
story: web-accounts-p0-06
spec: web-accounts-p0
status: todo
returned:
tier: 3
worker: worker-code
model: opus
tracer: false
wave: 3
blocked_by: [web-accounts-p0-01, web-accounts-p0-05]
---

# Link code from the bot: /web, settings button, /account/link, guide, tip

## Goal
A bot player can find the code without being told where it is. The new slash command `/web` and a
button on the settings screen both issue a one-time link code through
`LinkCodeService::issue()`. The code is stored hashed in `account_link_codes`, and earlier unused
codes of that character are invalidated. The reply is plain text (no photo) and says:
- the code;
- where to enter it (`wildworld.fun/account/link`);
- how many minutes it lives (from `Config\Accounts`);
- that it works once.

On the site, `/account/link` accepts the code. `LinkCodeService::redeem()` claims it atomically
and then applies the plan A2 rule: log in, merge into the character's account, or refuse. It
finishes with `AccountSession::login()`. `/guide` gets a section about playing on the site and
linking the character. A daily tip about linking is seeded.

## Requirements
> сначала закрытая бета для текущих игроков по коду из бота
> в том числе и связка с телеграм

## Files
- app/Services/Web/LinkCodeService.php
- app/Controllers/Telegram/Commands/WebCommand.php
- app/Controllers/Telegram/Commands/Actions/WebLinkCodeAction.php
- app/Controllers/Telegram/Commands/SettingsCommand.php
- app/Services/Telegram/BotMenuService.php
- app/Services/Onboarding/GuideCatalog.php
- app/Database/Migrations/2026-12-10-100020_SeedWebLinkTip.php
- app/Controllers/AccountLink.php
- app/Views/site/account_link.php
- tests/database/LinkCodeServiceTest.php

## Non-goals
- No change to the reply keyboard or the ADR-150 main grid. The button goes on the settings
  screen only (plan Q4; if that screen is rendered elsewhere, stop and report).
- No photo or `MediaSender` for the code message, and no balance numbers in the guide or tip. The
  TTL belongs in the message, not in the guide.
- Do not change `Routes.php` (declared in story 05) or add CSS (story 03).
- No push of the code into the site and no QR code.

## Map slice
`memory/map/telegram.md` (media-off, Markdown escaping, 2-3 buttons per row, reply menu),
`memory/map/onboarding.md` (GuideCatalog rules, tip seed idempotency, 14-value `tip_type` ENUM);
recon.md §D (command auto-discovery, `BotMenuService::commandList()`).

## Acceptance criteria
- [ ] Worker runs its own new test file(s) singly while iterating; the close-story gate is the full suite + phpstan + migrations lint.
- [ ] Ask 11: `/web` is added to `BotMenuService::commandList()` (the command menu) AND a button opens it from the settings screen.
- [ ] Ask 11: the code message states, in text only (no photo, readable with media off): the code, where to enter it (`wildworld.fun/account/link`), and how long it is valid (from `Config\Accounts`).
- [ ] Ask 9: `/account/link` uses only `wildworld-ui.css` tokens; no horizontal scroll at 375 px.
- [ ] Ask 3 / Ask 15: a code works once. A second redeem, a redeem after `linkCodeTtlSeconds`, and
      an older code after a newer one was issued all fail with a readable reason. Only the sha256
      of the code is stored, and the redeem is atomic (affected_rows = 1).
- [ ] Ask 3: redeeming while logged out logs the visitor into the character's account and lands on
      `/account`. The merge and refuse branches of plan A2 each have a test.
- [ ] Ask 11: `web` is in `BotMenuService::commandList()`, with the commands version bumped if the
      service versions it. The settings screen shows the button in a 2-3-per-row layout through
      the shared row normalizer. The message text names the site path and the lifetime in minutes,
      is legacy-Markdown-safe, and is complete without images.
- [ ] Ask 12: `GuideCatalog` has a section with an `[a-z]` key (e.g. `web`). It is read-only,
      markdown-safe and has no balance numbers, and the existing guide tests stay green.
      `*SeedWebLinkTip.php` is idempotent on `title_en`, uses one of the existing ENUM categories
      (read existing tip seeds), and is written in Robi's voice with no balance numbers.
- [ ] Ask 15: `POST /account/link` goes through `accountThrottle` (declared in 05). Ask 9:
      `/account/link` uses story-03 components and has no horizontal scroll at 375/768/1440.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
`git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`


## Implementation notes

## Findings
