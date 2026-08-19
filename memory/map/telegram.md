<!-- Срез-указатель, а не копия территории. Подробность — в mmorpg-vault; здесь только то,
     что нужно, чтобы понять, куда идти, и не вляпаться. Посеян обследованием дерева репозитория
     и конституцией проекта 2026-08-19; углубляется /vulyk-map <path> через drone-scout. -->
last-verified: 2026-08-19

# Scout report: Telegram-поверхность

## Purpose
Вход игрока в игру: webhook Telegram → команды и inline-кнопки → экраны. Здесь живёт весь
пользовательский UI бота; игровая логика — в `app/Services/*`, а не тут.

## Entry points
- `app/Controllers/Telegram/BotController.php` — приём webhook-апдейтов.
- `app/Controllers/Telegram/Commands/*Command.php` — слэш-команды (`StartCommand`, `MeCommand`,
  `MapCommand`, `CraftCommand`, `GuideCommand`, `TipsCommand`, `SettingsCommand`, `MenuCommand`,
  `MoreCommand`, `TasksCommand`, `GoCommand`, `NameCommand`, `StartrobotexplorerCommand`).
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
- **Фото только через `MediaSender`** (`sendPhotoOrText` / `editOrSend` / `editTextOrSend`). Прямой
  `Request::sendPhoto(` в app-коде запрещён: он ломает режим media-off.
- **Caption обязан нести весь смысл** — картинка это усиление, а не носитель (правило MEDIA-OFF).
- **Caption > 1024 символов** — Telegram возвращает `ok=false`, сообщение молча не уходит.
- **Legacy Markdown** без экранирования `*` / `_` даёт 400 и тоже тихий no-send.
- Ноль одиночных кнопок в ряду: 2–3 в строку, через общий нормализатор рядов.
- PHPUnit не видит ни один из этих отказов — нужен Tier-3 smoke в живом Telegram.

## Vault
`mmorpg-vault/apps/telegram/index.md` · ноты handler'ов — `mmorpg-vault/tech-writing/handlers/`
