---
story: web-bridge-p1-02
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
- `app/Services/Web/TelegramMarkupRenderer.php` (new): static `toHtml()`; parse mode matched case-insensitively (`html`/`markdown`/`markdownv2`, anything else = plain). Legacy Markdown is flat (Telegram semantics, a lone `*`/`_` stays literal); V2 nests, honours `\` escapes, `||spoiler||` renders its content unwrapped. Whole body in `try/catch Throwable` → escaped plain.
- HTML mode tokenises raw tags: allow-list mapped (`strong→b`, `em→i`, `ins→u`, `del/strike→s`), `tg-spoiler/span/blockquote/tg-emoji` dropped with content kept, every other tag escaped as visible text; unbalanced tags closed/dropped. Text uses `htmlspecialchars(double_encode=false)` so Telegram's `&lt;`/`&amp;` show as characters.
- Links: only `https?://` without whitespace/quotes/`<>`/backtick; a rejected URL (incl. one with `"`) drops the `<a>` and keeps its text. Output `<a>` carries `rel="nofollow noopener noreferrer" target="_blank"` (not asked for; so a bot link does not navigate away from `/play`).
- CSS block `PLAY` inserted before FOOTER in `wildworld-ui.css`, mirrored inline in `ui-kit.html` (the kit keeps its own inline copy), new kit section `#play` (§ 03·B) before §4; photo demo uses tracked `public/og-default.jpg`. `?v=6`→`?v=7`.
- Tier-2 check done with headless Chrome over CDP (`php -S` on `public/`), not MCP: at 375/768/1440 there is no horizontal scroll, nothing in `#play` sits past the viewport edge, radius/shadow are 0, fonts are Oswald/Manrope/JetBrains Mono only, and the console is clean. The first pass caught `<code>` inside `figcaption` falling back to system monospace, so `.play-msg-caption` now shares the `.play-msg-text` inline rules.
- Surprising: the working tree held uncommitted edits to other files from parallel wave-1 stories. I did not touch them, and I did not run the full suite because it would share the test DB with them.

## Findings
