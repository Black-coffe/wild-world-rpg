---
story: pvp-detection-clarity-07
spec: pvp-detection-clarity
status: done
tier: 2
worker: worker-code
tracer: false
wave: 2
blocked_by: [pvp-detection-clarity-02]
---

# Обнаружение: короткий список, уровень соседа, признак брошенного, замок вместо отказа

## Goal

После story сообщение обнаружения никогда не упирается в лимит Telegram и не несёт сотни кнопок: оно
показывает настраиваемое число ближайших соседей с уровнем, помечает брошенных и сворачивает остаток
в одну строку. У соседа, которого атаковать нельзя, вместо кнопки «⚔️ Атаковать» стоит замок с
причиной — игрок узнаёт об отказе **до** тапа. Новые персонажи больше не получают имя «Unknown Hero».

## Requirements

> Всего в сообщении 123 строки обнаружения. Среди имён 24 раза «Unknown Hero». Сообщение ОБОРВАЛОСЬ на полуслове «Есть игрок» и продолжилось отдельным сообщением ниже.

> Есть игрок Unknown Hero на расстоянии 2 ячеек от тебя.

> Это что за пати такое😁

> Мелкие походу все

> Кладбище домашних животных?

## Files
- app/Services/Player/PlayerDetectionService.php
- app/Controllers/Telegram/Commands/StartCommand.php
- app/Database/Migrations/2026-09-11-235000_Adr186SeedDetectionSettings.php
- tests/database/PlayerDetectionRenderTest.php

## Non-goals
- Не переименовывать 68 существующих персонажей в БД: имя — идентичность (WipeManifest `KEEP`), правится только чеканка новых имён и отображение.
- Не трогать радиус обнаружения и его формулу (`Config\GameBalance:213-215`) и не переносить его в `GameSettings` — отдельная работа, брифом не заказанная.
- Не ставить `LIMIT` в сам SQL-запрос соседей: кулдаун `player_detection_history` ключуется парой, и обрезанный запрос заставил бы отрезанных соседей всплывать снова через час. Ограничивается **вывод**, не выборка.
- Не трогать `PvPRestrictionService` (его правит `-02`) и не менять его сигнатуру.
- Не добавлять фото: сообщение было и остаётся текстовым.
- Не запускать полный набор и не делать `DROP`/`migrate` на общей локальной тест-БД; не делать `git stash`/`git checkout`.

## Map slice
`app/Services/Player/PlayerDetectionService.php:84-89` (радиус), `:99-107` (запрос соседей — ни
`LIMIT`, ни `ORDER BY`), `:156-190` (заголовок, строка, кнопки, подвал), `:200-205` (голый
`Request::sendMessage`), `:226-256` (кнопка дуэли, кулдаун пары);
`app/Controllers/Telegram/Commands/StartCommand.php:93` (`'name' => $username ?: 'Unknown Hero'`);
`docs/specs/pvp-detection-clarity/recon-prod.md` §2 и §4 (68 безымянных; `last_update_time` пуста у
всех — активность считается по `action_log.created_at`); `## Contracts` плана (ключи `world.detection.*`,
коды причин отказа).

## Acceptance criteria
- [ ] Выводится не более `world.detection.max_listed` соседей; порядок детерминирован (сначала те, кто действовал недавно, затем по расстоянию, затем по id). Остальные сворачиваются в одну строку вида «и ещё N поблизости», которая при `world.detection.show_inactive_summary = false` не печатается.
- [ ] Строка соседа несёт уровень и признак брошенного (нет действий за `world.detection.inactive_days` — считается по `action_log.created_at`, **не** по `characters.last_update_time`: она пуста у всех 709 строк).
- [ ] Есть жёсткая граница длины: собранный текст гарантированно короче лимита Telegram, и это проверяется тестом на искусственных 200 соседях, а не заметкой в коде. Рендер вынесен так, чтобы текст и клавиатуру можно было собрать без отправки.
- [ ] Кнопок в клавиатуре не больше, чем рядов выведенных соседей, по 2–3 в ряд, ни одной одиночной; «🏃 Бежать» — одна на всё сообщение, а не на каждого соседа.
- [ ] Для соседа, по которому `checkPvPAllowed()` отказывает, вместо «⚔️ Атаковать» стоит «🔒» с коротким называнием причины (уровень / южная зона / молодой аккаунт), а тап по замку отвечает объяснением и путём, а не ошибкой. Кнопка входа не исчезает — она блокируется с объяснением.
- [ ] Три ключа `world.detection.*` засеяны идемпотентной миграцией с полной рационализацией и границами из `## Contracts` плана; в коде не остаётся ни одного из этих чисел литералом.
- [ ] `StartCommand` больше не чеканит литерал `Unknown Hero`: новому персонажу без `@username` достаётся имя, по которому его можно отличить от другого такого же. Существующие строки не трогаются; в выводе обнаружения пустое/легаси-имя показывается различимо (у сервиса для этого уже есть fallback `№{id}`).
- [ ] Тест строит схему из настоящих классов миграций и покрывает: потолок списка, свёрнутый остаток, метку брошенного, длину текста на 200 соседях, замок вместо атаки.
- [ ] В `## Implementation notes` сказано: PHPUnit не рендерит Telegram-сообщение, поэтому вид списка и работа замка доказываются Tier-3 на testbot'е.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/database/PlayerDetectionRenderTest.php`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes

- `PlayerDetectionService`: сборка сообщения вынесена в `renderDetectionMessage(array $attacker,
  array $detectedPlayers, int $maxListed, int $inactiveDays, bool $showInactiveSummary): array`
  (public, `['text' => string, 'keyboard' => array]`) — собирается без похода в Telegram API,
  поэтому тест зовёт её напрямую. `detectNearbyPlayers()` теперь только собирает данные
  (level/created_at в SQL-select соседей, `last_active_at` одним групповым запросом по
  `action_log.character_id/created_at` — `characters.last_update_time` не тронута, она пуста
  у всех строк на проде), читает три ключа `GameSettings`, зовёт рендер и шлёт через `Request::sendMessage`
  с `parse_mode=HTML` (было `Markdown`; имя — чужой ввод, экранируется `esc($name, 'html')`).
- Жёсткая граница длины реализована ДВУМЯ независимыми механизмами: `max_listed` капает
  список по настройке, а внутренний бюджет символов (`MAX_TEXT_CHARS=3800` с запасом под
  footer/overflow-строку) обрывает построение строк ДАЖЕ если `max_listed` подняли выше
  разумного — тест `testHardTextLengthBoundaryOnTwoHundredNeighborsRegardlessOfMaxListedSetting`
  доказывает это на 200 соседях с длинными именами и `max_listed=500`.
- Кнопки: `⚔️ Атаковать`/`🔒 <причина>` (по `PvPRestrictionService::checkPvPAllowed()`, коды
  `level`/`safe_zone`/`account_age` → короткая метка, иначе `Недоступно`) + опциональная
  `🤺 Дуэль` в плоский список, `🏃 Бежать` добавлена в конец один раз, весь список пакуется
  `App\Services\Telegram\ButtonPacker::pack()` (2–3 в ряд, без одиночных строк).
- Лок переиспользует существующий `callback_data = 'attackPlayer_<id>'` — тап уходит в уже
  работающий `AttackPlayerAction` (не в `## Files` этой story, трогать нельзя), который сам
  вызывает `checkPvPAllowed()` и отвечает игроку реальной причиной отказа (alert + сообщение
  в чат). Компромисс: сообщение в чате у `AttackPlayerAction::sendError()` начинается с
  «⚠️ Ошибка:», хотя формально это объяснённый отказ, а не сбой — переписать эту формулировку
  не позволяют границы `## Files`; сама причина (текст) в сообщении настоящая, не generic.
- `StartCommand`: `'name' => $username ?: 'Путник-' . $telegramId` — различимо на пользователя
  (telegram_id уникален), не требует id персонажа (появляется только после `insert()`).
  68 существующих строк не тронуты (WipeManifest KEEP — имя это идентичность).
- Tips/guide-вердикт (`.claude/rules/player-facing.md`): **нет** — это рендер-фикс уже
  существующего автоматического экрана обнаружения (не новая механика, не новая кнопка
  входа), discoverability/онбординг не меняются.
- PHPUnit не рендерит Telegram-сообщение: вид списка (сворачивание, метка брошенного,
  замок, упаковка кнопок) в реальном клиенте и корректность тапа по замку доказываются
  Tier-3 на testbot'е (MCP Chrome + Telegram Web), не этим тестом.

## Findings
