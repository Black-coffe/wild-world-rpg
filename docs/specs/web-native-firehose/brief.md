# Brief — web-native-firehose

**Date:** 2026-10-11 · **Tier:** 2 · **Roadmap:** трек W, хвост W2.N6 (до W-REG)

## Request (verbatim)

Запрос этой сессии (2026-10-11):

> А что у нас там по roadmap дальше? Бери волок-планы и запускай разработку.

Пункт плана, на который он указывает (`mmorpg-vault/wiki/hot.md`, фокусы 2026-10-08 и 2026-10-09):

> Хвост: нативные `/play/view` не пишут firehose → web-only игроки вне WAU.

> **Дальше:** жребий → firehose нативного веба → W-REG

## Разведка (drone-scout, 2026-10-11)

- Firehose — `player_action_log` (`PlayerActionLogModel.php:26-37`: `character_id`, `telegram_user_id`, `chat_id`, `source`,
  `action_name`, `raw_input`, `status`, `error_text`, `origin`, `created_at`). Пишет `PlayerActionLogger` только внутри
  `UpdatePipeline::run()` (`UpdatePipeline.php:122-124`, `:169`): вебхук бота и `POST /play/act` (`source='web'`).
- `POST /play/view` (`Play.php`) не пишет ни строки: ни рендер нативных экранов (`me`, `inventory`, `gear`, `map`,
  `craft`, `base`, `tasks`, `shop`, `storage`, `battles`, `battle`, `arena`, `ladder` — `WebNativeScreenService.php:154`),
  ни нативные операции (шаг, Поход, крафт, стройка, сделки, склад, дуэль, снаряжение). `GET /play?view=` — тоже.
- `WebNativeScreenService::logRejected` (`:1925`) зовёт `PlayerActionLogger::current()->markRejected()` без `begin()` —
  вызов немой.
- `op=bridge` в `view()` зовёт `WebActService::act` до 4 раз (`:card`, `:sN`, `:cb`, `WebNativeScreenService.php:1757-1791`) —
  каждый вызов — строка firehose: один тап пишет несколько строк.
- Battle-колбэки в `act()` (`battles`, `arena`, `pvpLadder*`, `battleLog_<id>`) уходят в `battleScreen()` раньше
  пайплайна (`Play.php:155-158`) — без строки.
- Метрики (`OnboardingCohortService.php:117-143`) считают `player_action_log` по `character_id`, `source` не фильтруют.
- Готовый образец автономной записи — `PlayerActionLogger::recordTaskCompletion()` (`:313`).

## Answers

1. Что писать — Экраны и действия (Рекомендую): строка на каждое открытие экрана и на каждое действие (шаг, крафт, сделка, дуэль…). Так же ведёт себя бот: там каждый тап по кнопке, включая открытие экрана, — строка. Веб-игрок в метриках выглядит как бот-игрок.
2. Мост — Одна строка на нажатие (Рекомендую): в журнал идёт только само действие игрока, служебные шаги моста не пишутся. Иначе веб-игрок выглядит до 4 раз активнее бот-игрока, а «/go» засоряет срезы по командам.
3. Требования — Верно, строй сразу (Рекомендую).

## Asks

1. Нативный `/play` пишет строку в журнал действий игроков на каждое открытие экрана и каждое действие, с персонажем и статусом (ok/отказ/ошибка), как бот.
2. Одно нажатие «мостом» — одна строка в журнале, служебные шаги моста не пишутся.
3. Веб-игроки без Telegram попадают в метрики активности (строка несёт `character_id`).
4. Выключатель журнала из админки (`logging.player_actions.enabled`) действует и на веб; сбой записи никогда не ломает игру.
5. Вердикты: guide — нет (игрок поведения не видит); tips — нет (невидимая аналитика); онбординг — нет; WipeManifest — без изменений (новых таблиц и колонок нет).
