---
story: web-accounts-p0-03
spec: web-accounts-p0
status: todo
returned:
tier: 3
worker: worker-code
model: opus
tracer: false
wave: 1
blocked_by: []
---

# Site UI kit for auth pages, Statable counter, prototype in repo

## Goal
Three things exist after this story:
- The public design system has the components the auth pages need: form, field with label and
  error, notice (info/error/ok), provider button with a visible unavailable state plus a note, and
  an identity row. Each one lives in `wildworld-ui.css` and is demonstrated in `public/ui-kit.html`.
- Every public page carries the Statable counter, rendered from the snippet in plan.md
  `## Contracts` with the hash taken from env `STATABLE_SITE_HASH`. When the env var is empty,
  nothing is rendered.
- The web-client prototype is versioned at `docs/specs/web-first-client/webgame-preview.html`.

## Requirements
> https://statable.com/sites Через Google MCP браузер войти и подключить наш сайт для сбора статистики и понимания трафика
> /vulyk-plan на Фазу 0 своими словами.
> Email + пароль (Рекомендую) + Google аунтификация + Yandex аунтификация

## Files
- public/assets/css/wildworld-ui.css
- public/ui-kit.html
- app/Views/site/_layout/meta.php
- app/Views/site/_layout/statable.php
- .env.example
- docs/specs/web-first-client/webgame-preview.html

## Non-goals
- No auth views, controllers or routes. Stories 05-08 build them on these components.
- Do not touch `header.php` or `layout.php`. The header auth state is story 08.
- Do not register the site in Statable or invent a snippet. If the Contracts line still says
  `<QUEEN: paste snippet here>`, return NEEDS_CONTEXT.
- Do not edit `.gitignore` or delete `public/webgame-preview.html` (plan A11).
- No admin views. `admin-ui.css` is a different design system.

## Map slice
`memory/map/website.md` (ADR-062 gotchas: 0 radius/shadow, fonts, tokens, `?v=` bump);
recon.md §D (site design system, prototype).

## Acceptance criteria
- [ ] Ask 9: the new ui-kit components introduce no `border-radius`/`box-shadow` ≠ 0 and no raw colours — tokens only (ADR-062).
- [ ] Ask 8: with `STATABLE_SITE_HASH` set, the counter markup appears in the HTML of `/` and of
      a wiki/article page. With it empty, the markup is absent and nothing breaks. The partial is
      included from `meta.php`. If plan Q2 names pages that bypass `site/layout.php`, report them;
      do not edit them unless the Queen adds them to Files.
- [ ] Ask 8: `.env.example` documents `STATABLE_SITE_HASH=` (empty value, no secret).
- [ ] Ask 7: `docs/specs/web-first-client/webgame-preview.html` exists as a byte-identical copy of
      the untracked `public/webgame-preview.html`.
- [ ] Ask 9: the new components use only `wildworld-ui.css` tokens: no radius, shadow, blur, or
      raw colour, and only the Oswald/Manrope/JetBrains Mono fonts. They work without JS and have
      no horizontal scroll at 375/768/1440 in `ui-kit.html`. The unavailable provider button is
      visibly different and carries readable text, so it is not signalled by colour alone.
- [ ] `?v=` in `meta.php` is bumped. The final class names are reported on the INTERFACES line,
      because stories 05-08 consume them.

## Verification
`curl -sS -o /dev/null -w '%{http_code}' http://mmorpg.test/`
`curl -sS -o /dev/null -w '%{http_code}' http://mmorpg.test/ui-kit.html`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes

## Findings
