# E-FIRST — Tier-3 cold-smoke на preprod-testbot (ask 9)

**Дата:** 2026-11-01 · **Код:** develop f7e0ebd7 (e-first + e-first-rollback), деплой Actions run 41321970059 зелёный
**Как:** автономный POST апдейтов на вебхук testbot'а с секрет-заголовком (`reference_autonomous_webhook_tier3_smoke`), от
реального тест-аккаунта tg25 (`telegram_id` 6995661239): сообщения реально ушли в его Telegram. Чтобы `/start` отработал как у
новичка, тест-чар 491 на время смоука отвязан на заглушку `telegram_users`, после — возвращён (проверено: `491 → tu 25`).
**Preprod parity:** один дрейф — `onboarding.cold_open_v2.first_step_keyboard` (preprod 0, на проде ключа ещё нет: миграция
приедет с тегом и посеет 0). Остальные настройки совпадают. Флаги: `logging.action_origin.enabled = 1`, `single_screen = 1` (как на проде).

| # | Шаг | Ожидание | Результат |
|---|---|---|---|
| 1 | флаг ON, свежий `/start` | новый персонаж, носитель с одной кнопкой | ✅ char 529 создан, firehose `command start ok` |
| 2 | тап «🧭 Сделать первый шаг» | компас + полное меню один раз | ✅ firehose `text … cold ok`, маркер `FirstStepMenuAttached` = 1 |
| 3 | повторный тап | меню не шлётся | ✅ маркер остался 1 |
| 4 | `/start` повторно | ветка существующего, полное меню | ✅ `command start ok` |
| 5 | inline `move~cold` (маркер снят) | меню после шага Роби | ✅ `callback move cold ok`, маркер = 1 |
| 6 | флаг OFF, inline `move~cold` (маркер снят) | меню НЕ шлётся (как до E-FIRST) | ✅ маркер = 0 |
| 7 | флаг OFF, тап нижней кнопки | работает (e-first-rollback) | ✅ `text … cold ok`, маркер = 1 |

Все 7 строк firehose — `status = ok` (нет `undelivered`: Telegram принял отправки, Markdown не словил 400). Новых строк
ERROR/CRITICAL в логе testbot за время смоука нет. Флаг после смоука = 0.

**Не проверено глазами:** рендер клавиатуры (одна кнопка внизу) в клиенте Telegram — состав клавиатуры закреплён unit-тестами
(`ColdOpenWelcomeServiceTest`, `StartCommandColdOpenParityTest`); сообщения лежат в чате tg25 для визуальной сверки.

## Прод (после тега v0.51.708, Actions run 41322886538 зелёный)
Проверки только на чтение, живой проход на проде не делаем. Код на месте. Миграция посеяла `first_step_keyboard = 0`.
`getWebhookInfo`: `pending_update_count = 0`, ошибок нет. В логе за 15 минут одна посторонняя строка
(`GreenhouseProductionHandler`: «bot was blocked by the user»). Флаг включает владелец через админку.

## Включение на проде

- 2026-11-01 20:41:23 (время БД прода): `onboarding.cold_open_v2.first_step_keyboard = 1` через `/admin/game-settings` (кэш сброшен админкой). `logging.action_origin.enabled = 1`.
- Когорта для порога из `plan.md` (`## Success threshold`) — новички, созданные после этого момента.
