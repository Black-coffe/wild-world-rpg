<!-- Срез-указатель, а не копия территории. Подробность — в mmorpg-vault; здесь только то,
     что нужно, чтобы понять, куда идти, и не вляпаться. Посеян обследованием дерева репозитория
     и конституцией проекта 2026-08-19; углубляется /vulyk-map <path> через drone-scout. -->
last-verified: 2026-09-27

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
- **Игра на сайте (ADR-189)** — `/play`, `/play/act`, `/play/view`, `/play/inbox`, `/play/inbox/read`
  (`Routes.php:263-268`) → `app/Controllers/Play.php`. Сервисы `Services/Web/`: `WebActService`
  (одно действие), `WebDelivery` (seam отправки), `WebScreenStore` (экран), `WebInboxService`
  (колокольчик), `VirtualIdentityService`, `SyntheticUpdateFactory`, `BridgeClient`. Инфра-числа —
  `Config\WebPlay` (не баланс). Флаг `web.play_enabled` (GameSettings, default off).
  Нативные экраны (ADR-190): `POST /play/view` → `WebNativeScreenService` рендерит «Я»/«Инвентарь»/
  «Снаряжение» из `Services/Player` (см. `player.md`) и «Мир» (`view=map`) из `LiveMapService` +
  `MarchService::status` (см. `world.md`); `op=cell|step|march_*`. «🔨 Крафт» (`view=craft`,
  `bench/cat/recipe`) — `Config\CraftCatalog` + `CraftOrderService`/`CraftQueueService` (см. `craft.md`);
  `op=craft_start|craft_cancel`. «🏠 База» (`view=base`, `b/section/key/id`) — `BaseScreenService` +
  `BuildOrderService`/`BuildingUpgradeService` (см. `bases.md`); `op=build_start|upgrade`. Вьюхи
  `site/_play/native_*`, `hud`, `dock`, `state`; JS `wildworld-play.js` (таймеры `[data-ends-at]`, тик 1 с).
- Публичная карта `/map` → `app/Controllers/Map.php`: цвета легенды из `BiomePalette`, PNG с
  `?v=filemtime`; вошедшему при `web.play_enabled` — «Играть отсюда» → `/play?view=map`.

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
- Site-контент — прямой INSERT в `site_posts`; `draft` гасит публикацию; черновик — через `/redkollegiya`.
- **Слияний аккаунтов нет (ADR-188 инв. 4):** код из бота в чужом аккаунте — отказ до траты кода
  (`LinkCodeService::link`); чужая OAuth/Telegram-identity — отказ.
- Привязка Telegram-виджетом у вошедшего засчитывается только с одноразовым `tg_link_nonce`, который
  минтит кабинет (`AccountSession::mintTelegramLinkNonce`); без него `TelegramLogin::link` ничего не меняет.
- Один персонаж на аккаунт: `AccountService::characterForAccount` берёт первый по `id`;
  `AccountRegister::createCharacter` создаёт под `GET_LOCK('ww-acct-char-<id>')`.
- `/account/reset` отдаёт одну страницу при любом исходе (нет почты / ушло / SMTP упал) — иначе
  оракул адресов; реальный отказ — только `error`-лог `[PasswordReset]`.
- `/play`: флаг проверяется **до** входа — при выключенном заглушку `site/play_stub` видит и гость.
  Персонаж только из сессии; из запроса — лишь `intent_id/kind/data/message_id` (+ у `/play/view`
  `view/op/item/x/y/dir/n/bench/cat/recipe/qty/task/b/section/key/id`; `b` — подсказка, ядро перепроверяет),
  id персонажа в HTML/JSON не выводятся. Callback — только если `data` на кнопке сообщения с тем `message_id`.
- CSRF `regenerate` включён: JSON-ответы `/play/*` несут `csrf`, JS обязан брать свежий токен.
- Return target после входа — только ровно `/play` (`AccountSession::RETURN_PLAY`).
- Мутации `/play/view` дедупятся в `web_play_intents` по `intentKey(intent_id, ':gear'|':step'|':<march_op>'|
  ':craft_start'|':craft_cancel'|':build_start'|':upgrade')` (≤64); без JS — PRG. Веб-крафт: `qty > max_qty` — отказ.
- Веб-стройка/апгрейд не зовут `ActiveTasksService::checkRelocationAndBlock()` (бот зовёт) — переезд не блокирует.
- Поход из веба стартует без `msg_id` → прогресс тика приходит в Telegram новыми сообщениями.
- Кнопка нативного экрана без своего экрана идёт `op=bridge` через мост (карточка «Я» / у карты `/go` /
  у нехватки крафта `/craft` + `botRoute` / у базы «🏠 База» → `Base_b<id>` → `construction_b<id>`|`Build_b<id>`).
- HUD: срок строки `Marching` — из `MarchService::status()['eta']`. Хуки шага/Похода с чатом — под `WebDelivery`-захватом.
- Сессия: ключи `account_id`, `character_id`, legacy `tg_user_id`; legacy-сессия апгрейдится в `current()`.

## Vault
`mmorpg-vault/apps/website/index.md` · ADR-062, ADR-052, ADR-188, ADR-189, ADR-190 ·
`tech-writing/controllers/{AccountControllers,Play,Map}.md`, `services/{Account*,Web*}.md`, `config/CraftCatalog.md`
