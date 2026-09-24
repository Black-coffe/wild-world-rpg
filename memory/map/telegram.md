<!-- Срез-указатель, а не копия территории. Подробность — в mmorpg-vault; здесь только то,
     что нужно, чтобы понять, куда идти, и не вляпаться. Посеян обследованием дерева репозитория
     и конституцией проекта 2026-08-19; углубляется /vulyk-map <path> через drone-scout. -->
last-verified: 2026-09-24

# Scout report: Telegram-поверхность

## Purpose
Вход игрока в игру: webhook Telegram → команды и inline-кнопки → экраны. Здесь живёт весь
пользовательский UI бота; игровая логика — в `app/Services/*`, а не тут.

## Entry points
- `app/Controllers/Telegram/BotController.php` — приём webhook-апдейтов.
- `app/Controllers/Telegram/Commands/*Command.php` — слэш-команды (`StartCommand`, `MeCommand`,
  `MapCommand`, `CraftCommand`, `GuideCommand`, `TipsCommand`, `SettingsCommand`, `MenuCommand`,
  `MoreCommand`, `TasksCommand`, `GoCommand`, `NameCommand`, `StartrobotexplorerCommand`, `WebCommand`).
- `/web` (ADR-188): `WebCommand` → `Actions/WebLinkCodeAction::sendCode()` → `LinkCodeService::issue()`;
  та же кнопка «🌐 Играть на сайте» в `SettingsAction::buildScreen()` (callback `webLinkCode`,
  маршрут в `app/Config/CallbackRoutes.php`).
- `/start` создаёт персонажа через `App\Services\Player\CharacterProvisioningService::create()`
  (общий путь с сайтом); в `StartCommand` осталась только UI-часть.
- `app/Controllers/Telegram/Commands/Actions/` — ~54 action-handler'а (callback-кнопки).
- `app/Controllers/Telegram/Commands/BaseCommand.php` и `BaseShiftingCommand.php` — базовые классы.
- `app/Services/Notifications/MediaSender.php` — **единственная** точка отправки фото.

## Key types / contracts
Action-handler получает управление по `callback_data`; ответ строится как caption + клавиатура.
Постоянное reply-меню обновляется только на `/start` и `/menu` — само по себе оно не меняется.

## Dependencies
inbound: Telegram webhook, `Worker` (уведомления о завершении задач), broadcast-рассылки.
outbound: `Services/Player`, `Services/World`, `Services/Craft*`, `Services/Bases`, `Services/PVE`,
`Services/Onboarding`, `Services/Notifications`.

## Gotchas
- **(2026-09, ADR-181) Дедуп `update_id`.** `BotController::webhook()` вставляет `update_id` в
  `telegram_updates_seen` через `App\Services\Db\ConditionalWriteService::insertUnique()` сразу
  после разбора JSON, до community-gate/ADR-168 strip/firehose/E6-E8/диспетча. Дубль → тихий
  `200 OK`, обработка не идёт. Отказ хранилища — fail-open + `error`-лог с маркером
  `[Bot.webhook] dedup:`. До этого дедупа не было вовсе — повтор доставки вебхука проходил как
  новое действие игрока.
- **Фото только через `MediaSender`** (`sendPhotoOrText` / `editOrSend` / `editTextOrSend`). Прямой
  `Request::sendPhoto(` в app-коде запрещён: он ломает режим media-off.
- **Caption обязан нести весь смысл** — картинка это усиление, а не носитель (правило MEDIA-OFF).
- **Caption > 1024 символов** — Telegram возвращает `ok=false`, сообщение молча не уходит.
- **Legacy Markdown** без экранирования `*` / `_` даёт 400 и тоже тихий no-send.
- Ноль одиночных кнопок в ряду: 2–3 в строку, через общий нормализатор рядов.
- PHPUnit не видит ни один из этих отказов — нужен Tier-3 smoke в живом Telegram.
- **(2026-09-15) Мост поднимает точка отправки, не вызывающий.** `App\Services\Telegram\TelegramBridge::ensure()`
  — единый идемпотентный подъём (`getenv('telegram.*')` → `Request::initialize`), никогда не бросает,
  неудача = `false` + одна `error`-строка (не кэшируется). Сервисы вне webhook (крон/воркер) обязаны
  звать его сами перед `Request::send*`/`edit*` — гейт `tests/unit/Config/TelegramSenderBridgeCoverageTest.php`
  сканирует `app/Services/**` и роняет набор на новом сервисе без него. Подробности —
  `mmorpg-vault/tech-writing/services/TelegramBridge.md`.

- **(2026-09-24) Текст кода `/web` обещает привязку, которой нет.** `WebLinkCodeAction::codeMessage()`
  (и совет `SeedWebLinkTip`, и раздел `web` в `GuideCatalog`) говорят «вошёл почтой/Google/Яндексом —
  код привяжет этот вход», а `LinkCodeService::link()` при входе в другой аккаунт отказывает
  (`MSG_OTHER`, слияний нет, ADR-188).
- Бот не вешает второго персонажа на аккаунт: если аккаунт с этой telegram-identity уже владеет
  веб-персонажем, бот-персонаж получает свежий аккаунт без identity (`attachBotCharacter`).

## Vault
`mmorpg-vault/apps/telegram/index.md` · `tech-writing/services/CharacterProvisioningService.md`,
`tech-writing/services/LinkCodeService.md` · ноты handler'ов — `mmorpg-vault/tech-writing/handlers/`
