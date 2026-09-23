---
story: web-accounts-p0-03
spec: web-accounts-p0
status: done
returned: DONE
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
- [ ] Worker runs its own new test file(s) singly while iterating; the close-story gate is the full suite + phpstan + migrations lint.
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
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
`git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`


## Implementation notes
- `wildworld-ui.css`: new classes `.auth-form`/`.auth-actions`, `.notice` (+`.error`/`.ok`, `.notice-title`), `.provider-list`/`.provider-btn` (+`.is-unavailable`, `.provider-mark`/`.provider-name`/`.provider-state`), `.provider-note`, `.identity-list`/`.identity-row` (+`.identity-main`/`.identity-provider`/`.identity-subject`/`.identity-action`). Fields reuse existing `.field`/`.label`/`.input`/`.error-msg` + `.field.has-error`. Tokens only, no radius/shadow/raw colour (all 14 vars used exist in `:root`).
- Unavailable provider = dashed border + muted colour + visible text "недоступно" + `.provider-note` under it — not colour-only; it is a `<span aria-disabled>`, not a link.
- `ui-kit.html`: section `#auth` demos all components; ui-kit mirrors the CSS inline (its existing convention), so the two copies must be kept in sync. `#auth` link added to the mobile drawer only — the desktop nav already has 10 items and hides <900px; an 11th risks overflow at ~900-1000px.
- `statable.php`: renders `<script src="https://statable.com/js/<hash>/s.js" defer>` only when `env('STATABLE_SITE_HASH')` is non-empty (hash escaped); included from `meta.php`. Per Q2 only `site/bot_stub.php` bypasses the layout (301 stub, intentionally no counter). CSP is off, no config change.
- `meta.php`: `?v=5` → `?v=6`. `.env.example`: `STATABLE_SITE_HASH=` empty with comment.
- Prototype: `docs/specs/web-first-client/webgame-preview.html` is byte-identical to `public/webgame-preview.html` (`cmp`).
- Not verified: no browser pass at 375/768/1440 for horizontal scroll, and no rendered-HTML check of `/` or a wiki page with the env set (logic read only). phpstan L9 green.

## Findings
