<!-- Срез-указатель, а не копия территории. Подробность — в mmorpg-vault; здесь только то,
     что нужно, чтобы понять, куда идти, и не вляпаться. Посеян обследованием дерева репозитория
     и конституцией проекта 2026-08-19; углубляется /vulyk-map <path> через drone-scout. -->
last-verified: 2026-08-19

# Scout report: Публичный сайт wildworld.fun

## Purpose
Внешняя витрина: лендинг, статьи и гайды (CMS в БД), достижения, вход через Telegram, SEO.

## Entry points
- Контроллеры: `Front.php`, `Wiki.php`, `AchievementsController.php`, `ProfileController.php`,
  `Sitemap.php`, `TelegramLogin.php`, `Login.php` / `Signup.php` / `Password.php`.
- Вьюхи: `app/Views/site/` (layout + `_layout/{meta,header,footer}.php`).
- Дизайн-система: `public/assets/css/wildworld-ui.css`, `public/assets/js/wildworld-ui.js`,
  живой styleguide — `public/ui-kit.html`.
- SEO: `app/Services/Seo/GoogleSearchConsoleService.php`, команда `SeoGscPull`.
- Контент: таблица `site_posts` + pivot; импорт — `app/Commands/ImportWordPress.php`.

## Key types / contracts
Стиль — «Найденная фотоплёнка», flat-stencil (ADR-062): **ноль** `border-radius`, `box-shadow`,
`text-shadow`, `backdrop-filter: blur`; шрифты только Oswald / Manrope / JetBrains Mono; цвета —
только CSS-переменные. Все вьюхи обязаны работать без JS.

## Dependencies
inbound: браузер, поисковые роботы.
outbound: модели постов, `Services/Web/TelegramLoginVerifier`, `Services/Player` (свои данные).

## Gotchas
- Приватные поля персонажа показываются **только своему** персонажу.
- Правка CSS требует бампа `?v=` в `meta.php` и синхронного обновления `ui-kit.html`.
- Публикация site-контента идёт прямым INSERT в `site_posts`; статус `draft` гасит публикацию.
  Обновление живой страницы — не публикация новой.
- Любой site-черновик обязан пройти скилл `/redkollegiya` до публикации (PostToolUse-хук напоминает).

## Vault
`mmorpg-vault/apps/website/index.md` · ADR-062, ADR-052
