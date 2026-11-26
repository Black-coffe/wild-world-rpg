# B16 — не слать заблокировавшим бота (plan)
**Brief:** [brief.md](brief.md) · **Branch:** `vulyk/b16-blocked-recipients-1126` · **Approved:** «Одобряю (Рекомендую)», 2026-11-26

## Goal
Фоновые отправки (кроны, задачи, админ-рассылка) перестают стучаться в Telegram к игроку, у которого
`telegram_users.blocked_at` стоит. Проверка ставится в одной точке — `App\Services\Telegram\Request`, **после**
`WebDelivery::route()` и **до** `transport()`: копия во «входящих» `/play` у привязанных игроков и доставка
веб-игрокам остаются как были, игровое действие крона не трогается, пропускается только вызов Telegram. Пропуск
возвращает узнаваемый ответ «не отправлено: заблокирован», который отправители не пишут в лог как ERROR и не
считают новой блокировкой. Сброс метки на входящем апдейте уже есть (`blocked-clear-1123`,
`UpdatePipeline.php:178-182`) — добавляем сквозной тест «заблокирован → апдейт → крон снова шлёт».

## Assumptions
- Проверка срабатывает только для фоновой отправки: `DeliveryContext::actor() === null`. В ответ на апдейт игрока
  (actor есть) шлём всегда — `UpdatePipeline` снимает метку уже после обработки (`:175-182`), и без этого условия
  ответ на первый `/start` вернувшегося игрока был бы проглочен.
- Виртуальные чаты (`VirtualChat::is()`) и id ≤ 0 проверкой не затрагиваются; до проверки их уже обработал `route()`.
- Набор заблокированных `telegram_id` читается одним запросом и держится в памяти процесса (крон — один процесс на
  прогон); метка, поставленная `BlockedRecipient` в этом же процессе, добавляется в набор сразу.
- `BroadcastService::broadcastToAll`: пропущенные считаются в счётчике `blocked` (они и есть заблокировавшие), без
  `log_message('error')`. Отдельного счётчика «пропущено» в сообщении админке не вводим.
- `DailyTipBroadcastHandler` уже фильтрует `blocked_at` в SELECT (`:124`); его 165 ERROR за 6 дней — первые отказы
  только что заблокировавших (метка ставится на первом 403). После спеки такой первый отказ остаётся одной строкой
  на игрока, поэтому ask 5 — «единицы», не ноль.
- Троттлинг рассылки — вне спеки (ask 6): чисел баланса не появляется.
- Вне спеки, но найдено: `SeasonalTransitionHandler` держит маркер «раз в сезон» в кэше
  (`.claude/rules/cron-and-notifications.md` это запрещает) — строкой в ROADMAP при закрытии.
- Goes live: мердж в `develop` ничего не выкатывает; push в `develop` → деплой на preprod; тег → прод. Замер ask 5 —
  `grep -c "bot was blocked"` в логе прода за первые полные сутки после тега, строкой в журнал §6.

## Stories
- [x] S1 Пропуск фоновой отправки заблокировавшему в `Request` - asks: 1, 3, 4
  - outside: `tests/database/CronSenderBlockedMarkTest.php` — два теста держали старый контракт
    «помеченного не пропускаем» (ask 1 его переворачивает): один переписан на «пропущен, дата метки та же,
    без лога», второй («успешная отправка помеченному метку не снимает») удалён — до помеченного отправка
    больше не доходит.
  - files: `app/Services/Telegram/Request.php`, новый `app/Services/Notifications/BlockedRecipientGate.php`,
    `app/Libraries/BlockedRecipient.php` (или где он лежит — добавлять в набор при `markBlocked`),
    `app/TaskHandlers/BaseTaskHandler.php` и `BaseObjectHandler` (не логировать узнаваемый пропуск как ERROR),
    `tests/database/BlockedRecipientGateTest.php`
  - test first: (1) фон, реальный чат с `blocked_at` → транспорт не вызван, ответ «пропущено»; (2) тот же игрок с
    привязанным и залогиненным веб-аккаунтом → строка во «входящих» `/play` записана, Telegram не вызван; (3)
    `VirtualChat` → поведение как раньше; (4) actor выставлен (ответ на апдейт) → отправка идёт; (5) пробный крон
    на базе `BaseTaskHandler` выполняет свою запись в БД и не пишет ERROR при пропуске. Падает до кода: сейчас
    транспорт вызывается всегда.
  - check: `vendor/bin/phpunit --no-coverage --no-progress tests/database/BlockedRecipientGateTest.php tests/database/CronSenderBlockedMarkTest.php tests/database/WebDeliveryTest.php`
- [ ] S2 Рассылка и сквозной путь «разблокировал → снова получает» - asks: 1, 2, 7
  - files: `app/Services/Notifications/BroadcastService.php`, `tests/database/BroadcastServiceBlockedTest.php`,
    `tests/database/UpdatePipelineTest.php` (сквозной случай), tech-writing: `services/BlockedRecipient.md`,
    `services/UpdatePipeline.md`, `services/WebDelivery.md`, `tasks/BaseTaskHandler.md`, `models/TelegramUserModel.md`,
    новая `services/BroadcastService.md` (+ строка в индексе нот) — в `C:\Projects\mmorpg-vault\tech-writing\`
  - test first: `broadcastToAll` по трём игрокам (обычный, заблокированный, виртуальный) → Telegram вызван ровно для
    одного реального, заблокированный в счётчике `blocked`, ERROR не пишется; сквозной: метка стоит → апдейт
    `/start` через `UpdatePipeline` → метка снята → фоновая отправка уходит в транспорт. Падает до кода:
    `broadcastToAll` шлёт всем.
  - check: `vendor/bin/phpunit --no-coverage --no-progress tests/database/BroadcastServiceBlockedTest.php tests/database/UpdatePipelineTest.php`

Вердикты (ask 7): `/guide` — нет, player-механики нет; совет — нет, игрок изменения не видит; WipeManifest — новых
таблиц и колонок нет; онбординг и discoverability — не затронуты.

## Test command
`vendor/bin/phpunit --no-coverage --no-progress tests/database/BlockedRecipientGateTest.php tests/database/BroadcastServiceBlockedTest.php tests/database/CronSenderBlockedMarkTest.php tests/database/UpdatePipelineTest.php tests/database/WebDeliveryTest.php`
плюс `vendor/bin/phpstan analyse --memory-limit=512M --no-progress`. До мерджа — полный набор (10 частей `## Команды`).

## Descoped
(none)
