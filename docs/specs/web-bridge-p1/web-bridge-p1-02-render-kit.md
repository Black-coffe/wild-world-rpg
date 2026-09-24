---
story: web-bridge-p1-02
spec: web-bridge-p1
status: todo
returned:
tier: 3
worker: worker-code
model: opus
tracer: false
wave: 1
blocked_by: []
---

# Screen render kit: Telegram markup → safe HTML, `/play` components in the UI kit

## Goal
Two things the `/play` views (story 06) need.

1. `App\Services\Web\TelegramMarkupRenderer::toHtml(?string $text, ?string $parseMode): string`
   turns bot text into safe HTML. It covers legacy Markdown (×619 in the code), HTML (×35) and
   MarkdownV2 (×3). It escapes everything first, then re-allows only `b i u s code pre`,
   `a href` with http(s) and `<br>` for newlines. It never throws: bad markup degrades to escaped
   plain text.
2. The flat components of the `/play` screen, in `wildworld-ui.css` and shown first in
   `public/ui-kit.html`:
   - the play shell (screen column + history strip, mobile-first);
   - a message block (text, figure with photo, caption always shown under it);
   - inline-button rows (2–3 per row, tap-sized);
   - the bottom dock (sticky, wraps, safe-area);
   - the history strip (compact, older screens dimmed);
   - the text input row (force-reply placeholder);
   - a bell with an unread counter;
   - an inbox list item with an unread state;
   - an alert/toast (answerCallbackQuery);
   - a lock/stub card.

## Requirements
> Ответ бота показывается как экран, а исправление сообщения заменяет экран.
> Фото идут с полной подписью, и экран понятен без картинки.
> Используются только токены `wildworld-ui.css`, новые компоненты заведены в `ui-kit.html`, в консоли нет ошибок.

## Files
- app/Services/Web/TelegramMarkupRenderer.php
- public/assets/css/wildworld-ui.css
- public/ui-kit.html
- app/Views/site/_layout/meta.php
- tests/unit/Services/Web/TelegramMarkupRendererTest.php

## Non-goals
- No view templates, controller or JS. Those belong to stories 06/07.
- No full CommonMark or MarkdownV2 edge-case perfection. Unknown entities stay as escaped text.
- No new fonts, colours, radii or shadows (ADR-062). Tokens only.
- Do not restyle existing components or the `/map` inline radii (report §6, Phase 2 N2).

## Map slice
- `memory/map/website.md`: ADR-062 flat rules and the `?v=` bump with the `ui-kit.html` sync.
- `memory/map/telegram.md`: legacy Markdown without escaping, the caption rule.
- UX reference `docs/specs/web-first-client/webgame-preview.html`: dock `:926-931`, bell
  `:2212`, right column `:1670`. It is a pattern source only, not code to copy.

## Acceptance criteria
- [ ] The worker runs its own new test file singly while iterating. The close-story gate is the
      full suite, phpstan and the migrations lint.
- [ ] XSS: `<script>`, `<img onerror>`, `javascript:` links, `"` inside `href`, and HTML-mode
      tags outside the allow-list all come out inert (tests). Markdown `*b*`, `_i_`, `` `code` ``
      and `[t](https://x)` render. A lone `*`/`_` does not break the output and does not throw.
- [ ] Ask 3: a caption over 1024 characters renders in full, with no truncation in the
      renderer.
- [ ] Ask 10: every component above is in `ui-kit.html` in its states (normal, unread,
      disabled/lock, alert). There are 0 `border-radius`/`box-shadow`/`text-shadow`, colours come
      only from CSS variables, and the fonts are Oswald/Manrope/JetBrains Mono. The kit page has
      no horizontal scroll at 375/768/1440 and a clean console.
- [ ] `?v=` in `meta.php` is bumped.
- [ ] The final class names are reported on the INTERFACES line of the return report. The Queen
      pastes them into plan `## Contracts` before wave 2.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
`git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`

## Implementation notes

## Findings
