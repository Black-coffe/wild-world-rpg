<!-- Срез-указатель, а не копия территории. Подробность — в mmorpg-vault; здесь только то,
     что нужно, чтобы понять, куда идти, и не вляпаться. Посеян обследованием дерева репозитория
     и конституцией проекта 2026-08-19; углубляется /vulyk-map <path> через drone-scout. -->
last-verified: 2026-10-05

# Scout report: Публичный сайт wildworld.fun

## Purpose
Внешняя витрина: лендинг, статьи и гайды (CMS в БД), достижения, вход (только OAuth/Telegram), SEO.

## Entry points
- Контроллеры: `Front.php`, `Wiki.php`, `AchievementsController.php`, `ProfileController.php`,
  `Sitemap.php`, `TelegramLogin.php`. `Login.php` / `Signup.php` / `Password.php` существуют (не
  `/account/*`, содержимое не сверено) — не путать с `AccountAuth`/`AccountRegister`.
- Вьюхи `app/Views/site/` (+ `_layout/{meta,header,footer}.php`); дизайн-система `wildworld-ui.{css,js}`
  в `public/assets/`, styleguide `public/ui-kit.html`. SEO: `Services/Seo/GoogleSearchConsoleService`,
  `SeoGscPull`. Контент: `site_posts` + pivot; импорт `app/Commands/ImportWordPress.php`.
- **Аккаунты (ADR-188, v0.51.672; oauth-only с 2026-09-30)** — группа `/account/*`, `Routes.php:237-251`:
  `AccountAuth` (login/logout; POST attempt УДАЛЁН), `AccountCabinet` (index/unlink; addEmail УДАЛЁН),
  `AccountLink` (код из бота), `AccountOAuth` (google|yandex start/callback), `AccountRegister`
  (index = страница «как попасть», character, createCharacter). Вход: Google, Яндекс, Telegram-виджет,
  код `/web`. Сервисы `app/Services/Web/`: `AccountService` (`PROVIDERS` = google|yandex|telegram),
  `AccountSession`, `LinkCodeService`, `OAuthProviderFactory` (+ `YandexOAuthProvider`; env
  `GOOGLE_/YANDEX_OAUTH_CLIENT_ID|SECRET`, пусто = «недоступно»). Фильтр `accountThrottle` →
  `app/Filters/AccountThrottleFilter.php` на POST `link`, `character`, `identity/N/unlink`. `Config\Accounts`.
  Флаг `web.open_registration` (default false) гейтит `/account/register|character` и создание аккаунта
  первым OAuth-входом.
- **Удалено (нет файлов):** `AccountPassword`, `Services/Web/AccountAuthService`, `PasswordResetService`,
  вьюха `site/account_reset.php`. **Редиректы:** `account/reset` и `account/reset/(:segment)` →
  `account/login` (`Routes.php:255-256`, `addRedirect`).
- **Игра на сайте (ADR-189)** — `/play`, `/play/act`, `/play/view`, `/play/inbox`, `/play/inbox/read`
  (`Routes.php:~262-266`) → `app/Controllers/Play.php`. Сервисы `Services/Web/`: `WebActService`,
  `WebDelivery`, `WebScreenStore`, `WebInboxService`, `VirtualIdentityService`, `SyntheticUpdateFactory`,
  `BridgeClient`. Инфра-числа — `Config\WebPlay`. Флаг `web.play_enabled` (default off).
  Нативные экраны (ADR-190): `POST /play/view` → `WebNativeScreenService`: «Я»/«Инвентарь»/«Снаряжение»
  (`Services/Player`, `player.md`), «Мир» `view=map` (`LiveMapService`+`MarchService::status`, `world.md`);
  `op=cell|step|march_*`. «Крафт» `view=craft` (`Config\CraftCatalog`, `CraftOrderService`/
  `CraftQueueService`, `craft.md`); `op=craft_start|craft_cancel`; крупная партия: `craftStartOutcome()` → `confirm=N` в nav (панель итога, POST
  `confirmed=1`; правило в ядре `CraftOrderService::start($confirmed)`, см. `craft.md`). «База» `view=base`
  (`BaseScreenService`, `BuildOrderService`/`BuildingUpgradeService`, `bases.md`); `op=build_start|upgrade`.
  «📋 Дела» `view=tasks` (w2-n5-deeds; `TASK_SECTIONS` hub|active|available|completed|events|quest,
  `tasksModel()`; `op=quest_start|quest_branch` — ядро бота под блокировкой персонажа; вьюха
  `native_tasks`; `quests-events-npc.md`, `services/WebNativeScreenService.md`).
  Вьюхи `site/_play/native_*`, `hud`, `dock`, `state`; JS `wildworld-play.js` (`[data-ends-at]`, тик 1 с).
- Публичная карта `/map` → `app/Controllers/Map.php`: цвета из `BiomePalette`, PNG `?v=filemtime`;
  вошедшему при `web.play_enabled` — «Играть отсюда» → `/play?view=map`.
- Бот: `app/Controllers/Telegram/Commands/Actions/WebLinkCodeAction.php` (callback `webLinkCode`,
  `/web`) → `LinkCodeService::issue`; `SITE_PATH = wildworld.fun/account/link`.

## Key types / contracts
Стиль — «Найденная фотоплёнка», flat-stencil (ADR-062): **ноль** `border-radius`, `box-shadow`,
`text-shadow`, `backdrop-filter: blur`; шрифты Oswald / Manrope / JetBrains Mono; цвета — CSS-переменные.
Все вьюхи работают без JS. Сообщения аккаунта — `?auth=<код>` (PRG), таблицы `AUTH_NOTICES` в
`AccountAuth`/`AccountCabinet`.

## Dependencies
inbound: браузер, поисковые роботы, Telegram-бот (код `/web`).
outbound: модели постов, `Services/Web/TelegramLoginVerifier`, `Services/Player`, `league/oauth2-client`.

## Gotchas
- Приватные поля персонажа показываются **только своему** персонажу.
- Правка CSS требует бампа `?v=` в `meta.php` и синхронного обновления `ui-kit.html`.
- Site-контент — прямой INSERT в `site_posts`; `draft` гасит публикацию; черновик — через `/redkollegiya`.
- **Слияний аккаунтов нет (ADR-188 инв. 4):** код из бота в чужом аккаунте — отказ до траты кода
  (`LinkCodeService::link`); чужая OAuth/Telegram-identity — отказ.
- **Почты и паролей на сайте нет.** Старые строки `account_identities.provider='email'` остаются в БД, но
  не в `PROVIDERS`: не видны в кабинете и не считаются в «≥1 способ входа». Отвязка последнего способа — отказ.
- Привязка Telegram-виджетом у вошедшего — только с одноразовым `tg_link_nonce`
  (`AccountSession::mintTelegramLinkNonce`); без него `TelegramLogin::link` ничего не меняет.
- Один персонаж на аккаунт: `AccountService::characterForAccount` берёт первый по `id`;
  `AccountRegister::createCharacter` под `GET_LOCK('ww-acct-char-<id>')`.
- `/play`: флаг проверяется **до** входа — при выключенном заглушку `site/play_stub` видит и гость.
  Персонаж только из сессии; из запроса — лишь `intent_id/kind/data/message_id` (+ у `/play/view`
  параметры экрана/операции; `b` — подсказка), id персонажа в HTML/JSON не выводятся. Callback — только
  если `data` на кнопке сообщения с тем `message_id`.
- CSRF `regenerate` включён: JSON-ответы `/play/*` несут `csrf`, JS обязан брать свежий токен.
- Return target после входа — только ровно `/play` (`AccountSession::RETURN_PLAY`).
- Мутации `/play/view` дедупятся в `web_play_intents` по `intentKey(intent_id, ':<op>')` (≤64); без JS —
  PRG. `qty > max_qty` — отказ. Переезд блокирует стройку/апгрейд (код `relocating`); устаревший `from` — `stale`.
- Поход из веба стартует без `msg_id` → прогресс тика идёт в Telegram новыми сообщениями. Кнопка
  без нативного экрана идёт `op=bridge` через мост (`/go`, `/craft`+`botRoute`, `Base_b<id>`).
- HUD: срок `Marching` — из `MarchService::status()['eta']`. Хуки шага/Похода с чатом — под `WebDelivery`-захватом.
- Сессия: ключи `account_id`, `character_id`, legacy `tg_user_id`; legacy-сессия апгрейдится в `current()`.

## Vault
`mmorpg-vault/apps/website/index.md` · ADR-062, ADR-052, ADR-188 (поправка 2026-09-30), ADR-189, ADR-190 ·
`tech-writing/controllers/{AccountControllers,Play,Map}.md`, `services/{Account*,Web*}.md`, `config/CraftCatalog.md`
