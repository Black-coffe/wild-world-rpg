<!-- Срез-указатель, а не копия территории. Подробность — в mmorpg-vault; здесь только то,
     что нужно, чтобы понять, куда идти, и не вляпаться. Посеян обследованием дерева репозитория
     и конституцией проекта 2026-08-19; углубляется /vulyk-map <path> через drone-scout. -->
last-verified: 2026-09-24

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
- **Аккаунты (ADR-188, v0.51.672)** — группа `/account/*` в `app/Config/Routes.php` (`account` group):
  `AccountAuth` (login/attempt/logout), `AccountCabinet` (index/addEmail/unlink), `AccountLink`
  (код из бота), `AccountOAuth` (google|yandex start/callback), `AccountRegister` (register,
  character), `AccountPassword` (reset). Сервисы `app/Services/Web/`: `AccountService`,
  `AccountAuthService`, `AccountSession`, `LinkCodeService`, `PasswordResetService`,
  `OAuthProviderFactory` (+ `YandexOAuthProvider`). Фильтр `accountThrottle` →
  `app/Filters/AccountThrottleFilter.php` на каждом POST, кроме logout. Константы — `Config\Accounts`.
  Флаг `web.open_registration` (GameSettings, default false) гейтит `/account/register|character`
  и регистрацию через OAuth.
- Statable: `app/Views/site/_layout/statable.php`, из `meta.php`; env `STATABLE_SITE_HASH`, пусто — не рендерится.

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
- **Слияний аккаунтов нет (ADR-188 инв. 4).** Код из бота у вошедшего в чужой аккаунт — отказ до
  траты кода (`LinkCodeService::link`); чужая OAuth/Telegram-identity — отказ. Identity между
  аккаунтами не переезжает.
- Привязка Telegram-виджетом у вошедшего засчитывается только с одноразовым `tg_link_nonce`, который
  минтит кабинет (`AccountSession::mintTelegramLinkNonce`); без него `TelegramLogin::link` ничего не меняет.
- Один персонаж на аккаунт: `AccountService::characterForAccount` берёт первый по `id`;
  `AccountRegister::createCharacter` создаёт под `GET_LOCK('ww-acct-char-<id>')`.
- `/account/reset` отдаёт одну страницу при любом исходе (нет почты / ушло / SMTP упал) — иначе
  оракул адресов; реальный отказ — только `error`-лог `[PasswordReset]`.
- Сессия: ключи `account_id`, `character_id`, legacy `tg_user_id`; legacy-сессия апгрейдится в `current()`.

## Vault
`mmorpg-vault/apps/website/index.md` · ADR-062, ADR-052, ADR-188 ·
`tech-writing/controllers/AccountControllers.md`, `tech-writing/services/Account*.md`
