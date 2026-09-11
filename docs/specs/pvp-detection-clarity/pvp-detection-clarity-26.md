---
story: pvp-detection-clarity-26
spec: pvp-detection-clarity
status: done
tier: 2
worker: worker-code
tracer: false
wave: 9
blocked_by: []
---

# Частота тревог и кулдаун атаки крутятся из админки, а не живут в коде

## Goal

После story у частоты пуш-тревог защитнику есть собственный потолок, настраиваемый из админки, и
он не зависит от кулдауна атаки; а сам кулдаун атаки перестаёт быть магическим числом в коде и
переезжает в `GameSettings` с полной рационализацией.

## Requirements

> BLOCK-3, minor 9 (записанное решение, не оплошность): после своей же отмены нападавший может переоткрывать окно каждые 30 секунд бессрочно, то есть слать выбранной жертве ~2 пуш-тревоги в минуту; единственный ограничитель — хардкод `$pvpAttackCooldownSec = 30` в `Config\GameBalance`, не ключ `GameSettings`. Частота уведомлений защитнику должна иметь собственный потолок, крутящийся из админки.

> Конституционное правило проекта (ADMIN-TUNABLE BALANCE): любой параметр, влияющий на баланс — цену, вероятность, длительность, кулдаун, лимит — регистрируется в `GameSettings` и правится из админки. Магическое число в коде — отказ в мердже.

## Решение по дизайну (принято главной сессией, обоснование — здесь)

Ключей два, а не один, потому что `pvpAttackCooldownSec` используется ДВАЖДЫ — в
`AttackPlayerAction` и в `DuelAction`. Поднять его ради тревог нельзя: замедлится весь PvP, включая
дуэли, к тревогам отношения не имеющие.

Когда интервал тревог не истёк — окно **открывается**, но повторная тревога не шлётся. Альтернативы
отвергнуты: «не открывать окно» превращает потолок в дыру (атака пройдёт мгновенно, без окна);
«отложить и схлопнуть» сложнее и ничего не добавляет. Цена принятого варианта названа честно: при
травле циклами защитник не увидит вторую и последующие тревоги — но первую он получил и уже знает,
что рядом враг, а защита базы при этом продолжает работать в полном объёме.

## Files
- app/Database/Migrations/2026-09-12-100000_Adr186AlertRateAndAttackCooldownSettings.php
- app/Services/PVE/PvpStandoffService.php
- app/Controllers/Telegram/Commands/Actions/PVP/AttackPlayerAction.php
- app/Controllers/Telegram/Commands/Actions/PVP/DuelAction.php
- app/Models/PvpStandoffModel.php
- tests/database/StandoffAlertRateTest.php

## Non-goals
- Не менять поведение окна: длительность, кулдаун защитника, состав армирующих статусов, порядок гейтов — всё это устоялось тремя проходами ревью и не трогается.
- Не удалять `Config\GameBalance::$pvpAttackCooldownSec` — он остаётся страховочным дефолтом третьим аргументом `GameSettingsService::get()`, как уже сделано для `world.move.*`. Двойной правды это не создаёт: источник истины — `GameSettings`, код держит safety net на случай пустой таблицы.
- Не трогать тексты игроку: ни `/guide`, ни советы, ни тревогу — формулировки прошли четыре круга правок и чисел не называют, а значит от новых ключей не зависят.
- Не вводить потолок «на пару»: он должен быть на ЗАЩИТНИКА, иначе трое согласованных нападающих обходят его втроём.
- Не запускать полный набор; не делать `DROP` / `migrate` на общей `wildworld_tests`; не делать `git stash` / `git checkout`.

## Map slice

`Config/GameBalance.php:57` — `$pvpAttackCooldownSec = 30`;
`AttackPlayerAction.php:167` и `DuelAction.php:107` — два его потребителя;
`AttackPlayerAction.php:228` — единственная точка отправки тревоги (`alertDefender()` при `justOpened`);
`app/Database/Migrations/2026-09-11-210100_Adr186SeedStandoffSettings.php` — образец seed-миграции
ключей `pvp.standoff.*` с полным набором обязательных полей;
`Config/WipeManifest.php` — `pvp_standoffs` уже классифицирована как `PLAYER_DATA`.

## Acceptance criteria
- [x] Ключ `pvp.attack_cooldown_sec` заведён в `GameSettings` (категория `combat`), значение по умолчанию байт-в-байт равно нынешним 30 секундам, и оба потребителя (`AttackPlayerAction`, `DuelAction`) читают его оттуда с безопасным литеральным дефолтом третьим аргументом.
- [x] Ключ `pvp.standoff.min_alert_interval_sec` заведён там же и задаёт минимальный интервал между тревогами ОДНОМУ защитнику независимо от того, кто нападает. Значение `0` выключает потолок целиком — это должно работать и быть покрыто тестом.
- [x] У обоих ключей заполнены `rationale_text`, `effect_text`, `above_effect_text`, `below_effect_text`, soft- и hard-границы, `default_value_text` — без них запись не сохраняется, это инвариант.
- [x] Когда интервал не истёк: окно открывается (строка в `pvp_standoffs` появляется, атака по-прежнему заморожена), а тревога защитнику НЕ уходит. Тест проверяет и то, и другое — иначе потолок превратится в дыру.
- [x] Когда интервал истёк или равен нулю: тревога уходит как раньше. Существующие тесты на тревогу остаются зелёными.
- [x] Момент последней тревоги хранится так, чтобы переживать перезапуск и не зависеть от кэша: колонка в `pvp_standoffs` (не `Cache`). Новая колонка добавлена миграцией и, если она player-связанная, сверена с `Config\WipeManifest` — прогони `WipeManifestCoverageTest`.
- [x] Сравнение времени идёт часами БД (`NOW() - INTERVAL … SECOND`), а не PHP-временем: в этой таблице уже смешаны источники часов, новых расхождений не добавляем.
- [x] Дефолт `min_alert_interval_sec` выбран осознанно и обоснован в `rationale_text` через реальную картину: окно живёт `window_sec` (300 с по умолчанию), кулдаун атаки 30 с, кулдаун защитника 900 с и не армируется отменой нападавшего. Назови в `## Findings`, какую максимальную частоту тревог даёт выбранное значение.
- [x] Миграция идемпотентна: повторный прогон не плодит дублей ключей и не падает. Тест прогоняет `up()` дважды.
- [x] Префикс миграции уникален — проверь `ls app/Database/Migrations/ | grep 2026-09-12`.

## Verification

`vendor/bin/phpunit --no-coverage --no-progress tests/database/StandoffAlertRateTest.php tests/unit/Config/WipeManifestCoverageTest.php`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
`git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`

## Implementation notes

- Миграция сдвинута на `2026-09-12-120000` (не `-100000` из плана): префикс `2026-09-12-100000`
  занят `S5FirstShelterLeanTo` — обнаружено `ls app/Database/Migrations/ | grep 2026-09-12` перед
  первым запуском, как требует AC.
- Один файл миграции несёт и ALTER (`pvp_standoffs.last_alerted_at DATETIME NULL`, через
  `SHOW COLUMNS`-проверку вместо `fieldExists()` — CI4 кэширует метаданные поля в рамках
  соединения, второй `up()` в одном процессе иначе видит устаревшее «колонки нет» и падает
  дублем), и seed двух ключей `pvp.attack_cooldown_sec` / `pvp.standoff.min_alert_interval_sec`.
- `PvpStandoffModel::hasRecentAlert()`/`markAlerted()` обёрнуты в try/catch (как `GameSettingsService::get()`):
  если колонка ещё не накатана (частичная тест-БД/рассинхрон миграции) — деградируют в «недавней
  тревоги не было», НЕ гасят уведомление. Без этого `AttackPlayerAction`/`StandoffAttackGateTest`
  и три других существующих DB-теста на окно ловили фатал `Unknown column 'last_alerted_at'`,
  потому что их собственный schema-bootstrap не тянет story-миграцию (она не в их `## Files`).
  Перепроверено: `StandoffAttackGateTest`, `PvpStandoffServiceTest`, `StandoffContentTest`,
  `StandoffDefenderMovesTest`, `StandoffExpiryHandlerTest` — все зелёные и до, и после фикса
  (52 теста, 210 assertions).
- `GameSettingsService::get()` в обоих потребителях вызывается third-arg-дефолтом
  `$this->cfg->pvpAttackCooldownSec` (не сырым литералом `30`) — Non-goals прямо требует, чтобы
  `Config\GameBalance` «оставался» страховочным дефолтом, а не дублировался числом.
- `DuelAction`: нет отдельного assertable текста на кулдауне (`alert()` шлёт только
  `answerCallbackQuery`, `ServerResponse::getText()` пуст) — override доказан функционально:
  `pvp.attack_cooldown_sec=0` пускает немедленный повторный тап (иначе хардкод 30 с заблокировал
  бы его тем же кулдауном). `cache()->getMetaData()` для этого не годится — `CodeIgniter\Test\
  Mock\MockCache::getMetaData()` в тестовом окружении возвращает `null` для ЖИВОЙ (неистёкшей)
  записи из-за инвертированного условия во фреймворке (`$this->expirations[$key] >
  Time::now()->getTimestamp()` вместо `<=`), проверено эмпирически.

## Findings

- Максимальная частота тревог одному защитнику при дефолте `min_alert_interval_sec=60`: **1
  тревога в минуту** (было до story — до 2 в минуту, ограничитель только `pvp_attack_cooldown_sec`
  = 30 с через цикл «атаковать → уйти → атаковать», `cancelled` не армирует
  `pvp.standoff.cooldown_sec`). Первая тревога всегда уходит независимо от ключа — потолок
  действует только на ПОВТОРНЫЕ тревоги тому же защитнику.
- Оба новых ключа хранятся в `game_settings`, категория `combat`: `pvp.attack_cooldown_sec`
  (default `30`, hard `[0, 600]`, recommended `[10, 120]`) и
  `pvp.standoff.min_alert_interval_sec` (default `60`, hard `[0, 3600]`, recommended `[30, 300]`).
- Момент последней тревоги — `pvp_standoffs.last_alerted_at` (DATETIME NULL, часы БД через
  `NOW()`/`NOW() - INTERVAL … SECOND`), не привязан к текущей открытой строке: потолок читает
  самую свежую отметку ЛЮБОГО (открытого или закрытого) окна этого защитника.
