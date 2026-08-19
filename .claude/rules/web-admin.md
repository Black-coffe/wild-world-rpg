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
