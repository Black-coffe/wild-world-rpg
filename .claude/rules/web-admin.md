---
paths: ["app/Views/admin/**", "app/Controllers/Admin/**", "public/assets/css/admin-ui.css", "public/admin-redesign-preview.html"]
---
# Админка — «Quiet Premium» (ADR-128)

- Только токены `admin-ui.css` (`.aui-*`): тёплая бумага, hairline-границы, чернильный текст,
  один янтарный акцент `#E89B2E`, числовые метрики — Fraunces.
- Запрещено: цвет вне токенов, inline `#hex`, градиентные заливки как носитель смысла,
  глубокие тени, `text-shadow`, шрифты вне Fraunces / Hanken Grotesk / JetBrains Mono.
- Цвет не может быть единственным носителем статуса — дублировать текстом или иконкой.
- Новый компонент — сначала в `public/admin-redesign-preview.html`, потом в production-вьюхе.
- Tier-2 visual smoke (1440 / 768 / 375, console clean) обязателен после правки вьюхи.
- Правило UX-Discoverability на админку **не** распространяется: это не player-interaction.
- Shield-страницы (`/admin/login`, signup) — служебные, вне 42 redesign-вьюх.

---

<!-- Полный текст, перенесён дословно из CLAUDE.md 2026-09-14 (сокращение контекста субагентов). Инвариант остался в CLAUDE.md. -->

## 🎨 КОНСТИТУЦИОННОЕ ПРАВИЛО ADMIN-UI «QUIET PREMIUM» (зафиксировано 2026-06-16, ADR-128)

**Любой код админки (`app/Views/admin/*`, admin-layout'ы `app/Views/admin/layouts/*` + `templates/{admin,dashboard,sidebar,navbar_custome}.php`, `public/assets/css/admin-ui.css`, admin-JS funnel/craft-tree/nav-map/crafting-economy) ОБЯЗАН использовать дизайн-систему `admin-ui.css` (`.aui-*` токены и компоненты).** Стилистика — «Quiet Premium»: тёплая бумага + hairline-границы + чернильный текст + ОДИН янтарный акцент `#E89B2E`, числовые метрики набраны **Fraunces**. Живой north-star — `docs/admin-design/ADMIN_UI_CONSTITUTION.md`; эталон/UI-kit — `public/admin-redesign-preview.html`. Аргументация — `mmorpg-vault/decisions/ADR-128`.

### 🔴 Запрещено

- Цвет вне токенов (`--ink/paper/surface/line/accent/...`); inline `#hex` (кроме дата-кодирования).
- Градиентные заливки как носитель смысла (KPI / кнопки / шапки) — наследие Hyper.
- Глубокие drop-shadow / `text-shadow` (глубину даёт hairline-граница, не тень).
- Шрифты вне **Fraunces / Hanken Grotesk / JetBrains Mono**.
- Цвет как ЕДИНСТВЕННЫЙ носитель статуса (дублировать текстом/иконкой — a11y).
- Новый компонент сразу в production-view минуя UI-kit.

### ✅ Обязательно

- Любой новый компонент **сначала в `public/admin-redesign-preview.html`** (UI-kit), потом в production-view — как у публичного сайта.
- Иерархия через типографику (размер/вес), цвет — точечно (акцент + семантика).
- A11y: `:focus-visible`, `aria-label` на иконочных кнопках, контраст AA.
- **Tier-2 visual smoke через MCP Chrome (1440 / 768 / 375)** после правки admin-view, console clean.

### Чек-лист «закрытой задачи» дополнен (3 новых пункта)

Перед `git push` правки админки обязательно ДА на все 3:
1. ✅ **Style-чек:** только токены `admin-ui.css` (палитра/шрифты, без Hyper-градиентов и глубоких теней)?
2. ✅ **Styleguide-чек:** новый компонент отражён в `public/admin-redesign-preview.html`?
3. ✅ **Tier-2 visual smoke:** рендер на 3 вьюпортах (1440/768/375), console clean?

Без всех 3 — задача **не считается закрытой**.

### Где НЕ применяется

- **Публичный сайт** (`app/Views/site/*`) — своя flat-система `wildworld-ui.css` (ADR-062). Бренд-знак (компас) общий.
- **Telegram-сообщения** — текстовая инфра, нет CSS.
- **Shield-страницы** (`/admin/login`, signup) — служебные, вне 42 redesign-вьюх (legacy `_head_common`).
- **Легаси Hyper-CSS** (`app-saas`/`icons`) — ПОКА остаётся (нужен legacy-layout'ам через `_head_common.php`); удаляется отдельным шагом, когда layout'ы полностью съедут на `aui`.

---
