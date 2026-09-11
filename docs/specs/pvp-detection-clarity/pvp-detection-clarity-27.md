---
story: pvp-detection-clarity-27
spec: pvp-detection-clarity
status: done
tier: 2
worker: worker-code
tracer: false
wave: 10
blocked_by: []
---

# Пинг «окно истекло» доходит до атакующего, а не умирает в кроне

## Goal

После story уведомление атакующего об истечении окна реально доставляется из крона, а если
доставка не удалась — право на повторную попытку не сгорает.

## Requirements

> Находка Tier-3 смоука на preprod-testbot (2026-09-11, окно id=4): защитник ничего не делал, окно
> истекло, `StandoffExpiryHandler` перевёл строку в `expired` и выставил `notified_expired=1`, но
> атакующий НЕ получил ни одного сообщения. В логе testbot'а:
> `ERROR - 2026-09-11 19:08:42 --> [StandoffNotifier] sendExpiredPing failed: Call to a member function getBotUsername() on null`

> Ключ `pvp.standoff.notify_attacker_on_expiry` на testbot'е включён (`1`), то есть пинг обещан
> настройкой и не приходит. Ветка «ничего не делать» — самая частая в реальной игре: защитник
> занят, окно истекает само.

## Диагноз (проверен на testbot'е, не по описанию)

`StandoffExpiryHandler` планируется через `app/Config/Tasks.php:361` (`$schedule->call(...)`), то
есть исполняется в CLI-процессе `spark tasks:run`. В этом процессе Telegram-мост не инициализирован
никем. Сам handler наследует `BaseTaskHandler`, у которого есть ленивый инициализатор
(`telegram()` → `new Telegram()` + `Request::initialize()`) и обёртки `safeSendMessage()`, — но
handler их не зовёт: он делегирует отправку в `App\Services\PVE\StandoffNotifier`, а тот бьёт
`Request::sendMessage()` напрямую (`StandoffNotifier.php:178`, `:194`). Отправка падает, `catch
(Throwable)` её проглатывает в лог, и наружу handler возвращается как будто всё хорошо.

Соседние крон-хендлеры доставляют нормально именно потому, что идут через базовый класс:
`Tips\DailyTipBroadcastHandler.php:159` зовёт `safeSendMessage()`, `Achievements\AchievementCheckCron`
в том же прогоне 2026-09-11 19:03 реально доставил сообщение о достижении на тот же аккаунт.
То есть сломан не крон и не Telegram, а конкретно этот путь отправки.

Вторая половина дефекта — порядок: `markExpiryNotified($id)` вызывается ДО отправки
(`StandoffExpiryHandler::handle()`), поэтому провалившийся пинг сжигает одноразовый флаг
безвозвратно — следующий тик крона строку уже не возьмёт.

Важно для оценки серьёзности: атакующий НЕ заперт. Заморозка снимается лениво по `expires_at` в
момент чтения, и тап «⏳ Проверить» честно отвечает «Окно закрылось — можно атаковать обычным
путём» (проверено живьём). Теряется ровно проактивное уведомление.

## Files
- app/TaskHandlers/PVP/StandoffExpiryHandler.php
- app/Services/PVE/StandoffNotifier.php
- tests/database/StandoffExpiryHandlerTest.php

## Non-goals
- Не менять правило истечения: ленивое вычисление по `expires_at` в момент чтения остаётся
  единственным источником правды, крон по-прежнему только уведомляет постфактум.
- Не менять тексты игроку: формулировка пинга прошла круги правок, трогать её нечего.
- Не переписывать `StandoffNotifier` на `BaseTaskHandler` — это сервис, а не handler, и его зовут
  ещё и из webhook-контекста (`AttackPlayerAction`), где `Request` уже инициализирован и повторная
  инициализация не нужна.
- Не проводить широкий аудит «кто ещё из `Config\Tasks` шлёт через сервис с сырым `Request::`» —
  это отдельная задача; здесь закрывается только путь окна противостояния (но см. AC про твина).
- Не запускать полный набор; не делать `DROP` / `migrate` на общей `wildworld_tests`; не делать
  `git stash` / `git checkout`.

## Map slice

`app/Config/Tasks.php:361` — планирование `StandoffExpiryHandler` (everyMinute, singleInstance);
`app/TaskHandlers/BaseTaskHandler.php:39` — ленивый `telegram()`, `:104` — `safeSendMessage()`,
который гарантирует инициализацию перед отправкой;
`app/TaskHandlers/PVP/StandoffExpiryHandler.php` — `handle()`, порядок `close()` →
`markExpiryNotified()` → `notifyAttackerExpired()`;
`app/Services/PVE/StandoffNotifier.php:100` — `notifyAttackerExpired()`, `:191` —
`sendExpiredPing()` с сырым `Request::sendMessage()` и `catch (Throwable)`;
`app/TaskHandlers/Tips/DailyTipBroadcastHandler.php:159` — образец правильного крон-пути.

## Acceptance criteria
- [ ] Пинг об истечении реально уходит из CLI-контекста: перед делегированием в `StandoffNotifier`
      Telegram-мост инициализирован (тем же ленивым приёмом, что у соседних крон-хендлеров), и
      `getBotUsername() on null` больше не возникает.
- [ ] Тест доказывает доставку **поведением, а не грепом**: подменённый транспорт/нотифайер
      фиксирует факт вызова отправки в прогоне через `handle()` целиком, а не через прямой вызов
      `notifyAttackerExpired()` в обход handler'а. Урок `feedback_transport_double_hides_dead_send_path`
      применим буквально: двойник, который не исполняет реальный путь, оставит баг зелёным.
- [ ] Одноразовость пинга сохранена, но флаг не сжигается провалом: `notified_expired` переходит
      в 1 только когда отправка сообщила об успехе. Провал отправки оставляет строку доступной
      следующему тику. Тест на обе ветки (успех → 1 и не шлём второй раз; провал → 0 и следующий
      тик пробует снова).
- [ ] `sendExpiredPing()` по-прежнему не роняет крон на сетевой ошибке (catch остаётся), но его
      булев результат теперь реально используется вызывающим, а не игнорируется.
- [ ] Существующие тесты окна остаются зелёными: `StandoffExpiryHandlerTest`,
      `StandoffAttackGateTest`, `PvpStandoffServiceTest`, `StandoffContentTest`,
      `StandoffDefenderMovesTest`, `StandoffAlertRateTest`.
- [ ] Твин-проверка в пределах этого же файла: у `StandoffNotifier` есть вторая точка отправки
      (`:178`). Назови в `## Findings`, из каких контекстов она вызывается, и если её тоже зовут
      из CLI/крона — она чинится этой же story; если только из webhook — так и напиши, с
      доказательством (кто вызывает), а не по догадке.
- [ ] `notify_attacker_on_expiry=false` по-прежнему полностью гасит пинг, а статус `expired`
      выставляется в любом случае — поведение ключа не меняется.

## Verification

`vendor/bin/phpunit --no-coverage --no-progress tests/database/StandoffExpiryHandlerTest.php tests/database/StandoffAttackGateTest.php`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes

- `app/TaskHandlers/PVP/StandoffExpiryHandler.php`: перед первой отправкой (если
  `notify_attacker_on_expiry=true`) зовёт унаследованный `$this->telegram()` — тот же
  ленивый init, что и у `safeSendMessage()`, чинит `getBotUsername() on null` в CLI.
  Порядок перевёрнут: `markExpiryNotified()` теперь идёт ПОСЛЕ `notifier->notifyAttackerExpired()`
  и только при `true`. Запрос строк расширен `OR`-группой (`status='expired' AND
  notified_expired=0`, гейтится тем же флагом notify) — иначе после `close()` строка
  уходила из-под условия `status='open'` и провалившийся пинг никогда не подхватывался
  повторно. Внутри цикла закрытие (`close()`) теперь условно на `status==='open'`, чтобы
  не дёргать `transitionIfCurrent()` второй раз на уже-`expired` строке.
- `app/Services/PVE/StandoffNotifier.php`: `notifyAttackerExpired()` возвращает `bool`
  (было `void`) — прокидывает результат `sendExpiredPing()` вызывающему.
- `tests/database/StandoffExpiryHandlerTest.php`: добавлен
  `testFailedPingKeepsFlagUnsetAndRetriesNextTick` + хелпер `failingNotifier()`
  (`sendExpiredPing` возвращает `false`), прогон целиком через `handle()` (без прямого
  вызова `notifyAttackerExpired()`) — twin-урок `feedback_transport_double_hides_dead_send_path`.
- Ревью (team-lead) вскрыло, что OR-группа без ограничения по времени даёт мусорную
  рассылку при флипе `notify_attacker_on_expiry` off→on (недельной давности строки
  разлетятся на первом тике) и вечный ретрай строки с постоянно падающей отправкой
  (заблокировавший бота игрок). Правка: `StandoffExpiryHandler::RETRY_HORIZON_SEC = 600`
  (константа класса, не `GameSettings` — это окно доставки, не баланс-параметр) добавлена
  третьим условием в OR-группу (`expires_at > NOW() - INTERVAL 600 SECOND`, часами БД,
  тем же приёмом, что и остальные сравнения в файле). Горизонт выбран по аналогии с
  `pvp.standoff.cooldown_sec` (дефолт 900 сек, ADR-186 §5) — тот же порядок величины
  «скоро неактуально», но короче, потому что там кулдаун защитника, а здесь окно, где
  пинг ещё что-то меняет для атакующего (реакция «⚔️ Атаковать ещё раз» ценна, только
  пока ситуация свежая). Добавлен тест
  `testStaleExpiredRowOutsideRetryHorizonIsNotPickedUp` (строка `expired`, `notified_expired=0`,
  истекла −700 сек — за горизонтом): убеждается, что `handle()` её не трогает и не шлёт.
  `testFailedPingKeepsFlagUnsetAndRetriesNextTick` сохранён без изменений (−60 сек — внутри
  горизонта, ретрай по-прежнему работает).
- CI-находка (run 34625203061, `Tests: 4083, Errors: 2` на обоих тестах пинга): на CI нет
  telegram-ключа вовсе (`.env` в репозиторий не попадает, `deploy.yml` его не создаёт), а
  `BaseTaskHandler::telegram()` ловит `TelegramException` только вокруг первой попытки —
  собственная аварийная ветка сама делает `new Telegram('invalid','invalid')`, который
  бросает то же исключение НЕПОЙМАННЫМ (мина из `feedback_transport_double_hides_dead_send_path`,
  не названная мне в брифе). Правка: голый `$this->telegram()` в `handle()` обёрнут в
  `try/catch (\Throwable)` — при провале инициализации логируем `error` (не `warning`: порог
  логирования на проде — 4, warning туда не попадает) и гасим `$notifyOnExpiry = false` только
  на этот тик; перевод `open→expired` не зависит от Telegram и идёт как обычно, строка остаётся
  `notified_expired=0` и подхватывается следующим тиком в пределах `RETRY_HORIZON_SEC`.
  `BaseTaskHandler::telegram()` не тронут — общий класс вне `## Files`, см. `## Findings`.
- Тесты переведены на env-независимый стаб `Telegram`, а не на реальный ключ из `.env`:
  добавлен `StandoffExpiryHandlerTest::stubTelegram()` — `new Telegram('123456:test-stub-format-only',
  'test_stub_bot')`, конструктор `Longman\TelegramBot\Telegram` только проверяет формат
  регуляркой `preg_match('/(\d+):[\w\-]+/')` и сети не трогает. `makeHandler()` теперь строит
  анонимный потомок `StandoffExpiryHandler`, переопределяющий `telegram()` на этот стаб —
  локальный `.env` (валидный ключ) раньше маскировал зависимость от окружения, воспроизвести
  провал CI (нет ключа вовсе) он не мог. Добавлен отдельный
  `testBrokenTelegramBridgeDoesNotCrashHandleAndStillClosesExpiredWindow` +
  `makeHandlerWithBrokenTelegram()` (переопределяет `telegram()`, бросает `RuntimeException`) —
  проверяет именно новую защитную ветку. Прогон подтверждён ДВАЖДЫ: с реальным ключом
  из `.env` и с `env "telegram.API_KEY=" "telegram.BOT_USERNAME="` (воспроизводит условия CI,
  где переменных нет вовсе) — оба раза 7/7 зелёных.

## Findings

Твин-проверка (AC): у `StandoffNotifier` есть вторая точка отправки —
`sendDefenderAlert()` (вызывается из `alertDefender()`). Единственный вызывающий —
`app/Controllers/Telegram/Commands/Actions/PVP/AttackPlayerAction.php:245`
(`(new StandoffNotifier())->alertDefender($justOpenedRow)`) — это webhook-контроллер,
исполняется в контексте живого Telegram-апдейта, где `Request`/`Telegram` уже
инициализированы обработкой входящего сообщения. `grep -rn "alertDefender("` по всему
`app/` не даёт других вызывающих. Из CLI/крона этот путь не зовётся — чинить его
этой story не нужно, дефект был только в паре `StandoffExpiryHandler` ↔
`sendExpiredPing()`.

Известное расхождение (CI-находка, не чинится этой story по прямому указанию): у
`BaseTaskHandler::telegram()` (`app/TaskHandlers/BaseTaskHandler.php:39`) аварийная
ветка (`catch (TelegramException)` → `new Telegram('invalid','invalid')`) сама бросает
то же исключение вторично и НЕПОЙМАННО, потому что литерал `'invalid'` не проходит
формат-регулярку конструктора `Telegram`. Любой другой handler, унаследованный от
`BaseTaskHandler`, который зовёт `telegram()`/`safeSendMessage()`/`safeSendPhoto()` в
окружении без ключа (как CI), получит тот же неперехваченный `TelegramException`.
`StandoffExpiryHandler` теперь защищён своим локальным `try/catch`; остальные ~70
handler'ов — нет. Правка `BaseTaskHandler` вне `## Files` этой story.
