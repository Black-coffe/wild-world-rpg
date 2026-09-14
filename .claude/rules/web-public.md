---
paths: ["app/Views/site/**", "public/assets/css/wildworld-*.css", "public/assets/js/wildworld-*.js", "public/ui-kit.html"]
---
# Публичный сайт — flat-система (ADR-062)

- Только токены из `public/assets/css/wildworld-ui.css`. Inline-стили с прямыми цветами,
  радиусами и тенями запрещены.
- Запрещено всегда: `border-radius` ≠ 0, любой `box-shadow`, любой `text-shadow`,
  `backdrop-filter: blur`, цвета вне палитры, шрифты вне Oswald / Manrope / JetBrains Mono.
- Новый компонент появляется **сначала** в `public/ui-kit.html`, потом в production-вьюхе.
- Правка CSS требует бампа `?v=` в `app/Views/site/_layout/meta.php` — иначе у игроков останется
  старый файл из кэша.
- Каждая вьюха обязана работать без JS. A11y: `skip-link`, `aria-label` на nav и бургере,
  `:focus-visible` на интерактивных элементах.
- Тон — холодный, прямой, постапок. Маркетингового шума нет.
- Проверка — Tier-2 visual smoke через MCP Chrome на 1440 / 768 / 375; 768 и 375 задаются
  CDP-эмуляцией, а не ресайзом окна. Console должна быть чистой.
- Site-черновик публикуется только после скилла `/redkollegiya`.

---

<!-- Полный текст, перенесён дословно из CLAUDE.md 2026-09-14 (сокращение контекста субагентов). Инвариант остался в CLAUDE.md. -->

## 🎨 КОНСТИТУЦИОННОЕ ПРАВИЛО PUBLIC-WEB FLAT STYLE (зафиксировано 2026-05-27, ADR-062)

**Любой код для публичного сайта (`app/Views/site/*`, `public/assets/css/wildworld-*.css`, `public/assets/js/wildworld-*.js`) ОБЯЗАН использовать только дизайн-систему `wildworld-ui.css`.** Никаких inline-стилей с прямыми цветами / радиусами / тенями.

Стилистика — «Найденная фотоплёнка» (Metro-эстетика, flat-stencil). Полная аргументация — `mmorpg-vault/decisions/ADR-062-Public-web-flat-design-system.md`.

### 🔴 Запрещено (всегда)

- `border-radius` ≠ 0 (исключение — функциональные спиннеры в animation)
- `box-shadow` любой
- `text-shadow` любой
- `backdrop-filter: blur` любой
- Цвета вне палитры (используй CSS-переменные `--bg-*`, `--text-*`, `--accent`, `--danger`, …)
- Шрифты вне Oswald / Manrope / JetBrains Mono
- Inline-стили с magic numbers (используй классы/токены)

### ✅ Обязательно

- Любой **новый компонент сначала появляется в `public/ui-kit.html`**, потом используется в production view. Как handlers сначала в `tech-writing/handlers/`, потом код.
- **Tier-2 visual smoke через MCP Chrome** обязателен после правки публичного view: 1440 / 768 / 375 viewport.
- Tone of voice — холодный, прямой, постапок (см. ADR-062 §Tone). Никакого маркетингового шума.
- A11y: `skip-link`, `aria-label` на nav и burger, `:focus-visible` на все интерактивные элементы.
- Все view должны корректно работать **без JS** (degradation graceful) — JS добавляет интерактив, но не блокирует контент.

### Технический контракт

| Файл | Роль |
|---|---|
| `public/assets/css/wildworld-ui.css` | Дизайн-токены + компоненты (single source of truth) |
| `public/assets/js/wildworld-ui.js` | Минимальный JS: burger/drawer/dropdown/tabs/accordion |
| `public/ui-kit.html` | Living styleguide на `/ui-kit.html` — все компоненты |
| `app/Views/site/layout.php` | Главный CI4-layout — подключает только ww-ui.css и ww-ui.js |
| `app/Views/site/_layout/{meta,header,footer}.php` | Partials, includes из layout |

### Чек-лист «закрытой задачи» дополнен (3 новых пункта)

Перед `git push` любой правки публичного сайта обязательно ответить ДА на все 3:

1. ✅ **Style-чек:** использованы только токены из `wildworld-ui.css` (палитра, шрифты, 0 радиусов, 0 теней)? Нет ли `border-radius`/`box-shadow`/`text-shadow`/`backdrop-filter: blur` в новом коде?
2. ✅ **Styleguide-чек:** если добавлен новый компонент — отражён ли он в `public/ui-kit.html`? Это living документация, обязана быть синхронной с CSS.
3. ✅ **Tier-2 visual smoke:** проверен ли рендер через MCP Chrome на 3 viewport'ах (1440 / 768 / 375)? Console clean?

Без всех 3 — задача **не считается закрытой**.

### Где НЕ применяется

- **Admin UI** (`/admin/*`) + дашборд `/dashboard` — своя дизайн-система «Quiet Premium» (`admin-ui.css`, ADR-128 — правило ниже). Под это правило (flat-сайт) не подпадает.
- **Telegram-сообщения** — текстовая инфра, MediaSender, нет CSS.
- **Email-шаблоны** — пока отсутствуют. Если появятся — отдельный ADR.

---
