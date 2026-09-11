---
story: pvp-detection-clarity-10
spec: pvp-detection-clarity
status: done
tier: 2
worker: worker-code
tracer: false
wave: 3
blocked_by: [pvp-detection-clarity-06]
---

# Истечение окна: одноразовый пинг нападавшему, что пять минут вышли

## Goal

После story существует фоновый хендлер, который помечает истёкшие окна и один раз сообщает
нападавшему, что ожидание кончилось и атака снова возможна. Крон при этом **не** является условием
правильности: открытость окна и без него вычисляется по времени в момент чтения. Опоздавший крон
делает сообщение поздним, а не правило неверным.

## Requirements

> Но ровно пять минут, потом атакующий может атаковать.

> Странно, что меня оповестили по факту что кто-то совершил нападение и опездюлился ... .

## Files
- app/TaskHandlers/PVP/StandoffExpiryHandler.php
- app/Config/Tasks.php
- tests/database/StandoffExpiryHandlerTest.php

## Non-goals
- Не переносить решение «окно закрыто» в крон: `expired` — это уведомление постфактум, а не источник истины.
- Не трогать `AttackPlayerAction`, `RunAwayAction` и сервис окна — они заняты соседними story.
- Не заводить новую инфраструктуру фоновой обработки: только строка в существующем `Config\Tasks`.
- Не слать больше одного пинга на окно и не слать его при выключенном `pvp.standoff.notify_attacker_on_expiry`.
- Не запускать полный набор и не делать `DROP`/`migrate` на общей локальной тест-БД; не делать `git stash`/`git checkout`.

## Map slice
`app/Config/Tasks.php` (формат записи, `everyMinute`, `singleInstance` у соседних задач);
ADR-186 §1 и §4 (пинг об истечении), «Инвариант» 1;
`## Contracts` плана — `activeAgainst()`, `markExpiryNotified()`, `notifyAttackerExpired()`, аудит-код
`pvp_standoff_expired`.

## Acceptance criteria
- [ ] Хендлер живёт в существующем каркасе task-handler'ов; если каталога `app/TaskHandlers/PVP/` нет — он создаётся, и это названо в отчёте.
- [ ] Задача зарегистрирована в `Config\Tasks` с минутным расписанием и защитой от параллельного запуска, как у соседних задач.
- [ ] Хендлер выбирает окна `status='open' AND expires_at <= NOW()`, переводит их в `expired` условным переходом (два одновременных тика не шлют двух писем) и шлёт нападавшему один пинг; `notified_expired` делает его одноразовым.
- [ ] При `pvp.standoff.notify_attacker_on_expiry = false` пинг не уходит, но статус всё равно переводится.
- [ ] При `pvp.standoff.enabled = false` хендлер выходит мгновенно и ничего не читает.
- [ ] Хендлер не инициализирует Telegram-клиент нетерпеливо — тесты не должны требовать живого бота.
- [ ] Тест строит схему из настоящих классов миграций, сеет время часами БД (`NOW() - INTERVAL`) и доказывает: истёкшее окно помечается один раз, повторный прогон не шлёт второй пинг, живое окно не трогается, выключенный килсвитч всё останавливает.
- [ ] Пинг — HTML, самодостаточный текст без фото. В `## Implementation notes` сказано: факт доставки проверяется Tier-3, PHPUnit исполняет только путь до отправки.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/database/StandoffExpiryHandlerTest.php`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes

- `app/TaskHandlers/PVP/StandoffExpiryHandler.php` — новый handler в уже существующем каталоге
  `app/TaskHandlers/PVP/` (создавать не пришлось, там уже жил `TributeExpiryHandler`). Никакой своей
  логики окна: killswitch — `PvpStandoffService::isEnabled()`, выборка — `status='open' AND
  expires_at <= NOW()`, закрытие — `PvpStandoffService::close($id, 'expired')`
  (`transitionIfCurrent`, гонки исключены на уровне БД), одноразовый пинг — свой условный переход
  `markExpiryNotified()` (0→1), гейтится `pvp.standoff.notify_attacker_on_expiry`. Пинг посылается
  ТОЛЬКО когда `markExpiryNotified()` вернул `true` — если два тика крона как-то оба прошли мимо
  `close()`-гонки (не должны, но защита в глубину), второй пинг всё равно не уйдёт.
- Конструктор принимает все 4 зависимости через nullable-DI (как у `TributeExpiryHandler`/
  `PvpStandoffService`) — телеграм-клиент нигде не создаётся в конструкторе, только
  `StandoffNotifier::notifyAttackerExpired()` внутри дёргает `Request::sendMessage()` через
  переопределяемый `protected sendExpiredPing()`.
- `app/Config/Tasks.php` — строка `pvp-standoff.expiry` рядом с `tribute.expiry`, `everyMinute()` +
  `singleInstance()`, как договорено в story.
- `tests/database/StandoffExpiryHandlerTest.php` — схема строится прогоном настоящих классов
  миграций (тот же приём, что `PvpStandoffServiceTest`), только нужные таблицы
  (`telegram_users`/`characters`/`game_settings`/`action_log`/`pvp_standoffs` + сид
  `Adr186SeedStandoffSettings`). Время сеется часами БД (`NOW() + INTERVAL ? SECOND` сырым SQL), не
  PHP `date()`. 4 теста: закрытие+одноразовый пинг (включая повторный прогон крона), живое окно не
  трогается, killswitch `pvp.standoff.enabled=false` не читает и не шлёт ничего, выключенный
  `notify_attacker_on_expiry` переводит статус, но не шлёт пинг. Доставка подменена (как в
  `PvpStandoffServiceTest::testAlertDefenderKeyboardHasExactlyThreeNamedMoves`) — PHPUnit исполняет
  путь ровно до `sendExpiredPing()`; факт живой доставки в Telegram — Tier-3.
- Прогонялось на одноразовой БД `wildworld_test_standoff_expiry_10`
  (`env "database.tests.database=..."`), созданной и удалённой этим воркером — общий локальный
  тест-стенд не трогался.
- Доводка: отбор просроченных окон изначально сравнивал `expires_at` со строкой из PHP `date()` —
  второй источник времени рядом с `PvpStandoffService::activeAgainst()`, который решает то же самое
  через `NOW()` БД. При дрейфе/разнице таймзон процесса и MySQL это развело бы «крон закрыл» и
  «сервис считает открытым» в разные стороны. Заменено на сырое SQL-сравнение
  `->where('expires_at <= NOW()', null, false)` — решение принимается теми же часами, что и у
  сервиса. Больше никакого PHP-времени handler в SQL не подставляет (только пишет
  `date('Y-m-d H:i:s')` нигде — весь остальной путь идёт через `PvpStandoffService`, который сам
  уже целиком на `NOW()`).

## Findings
