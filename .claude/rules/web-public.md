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
