---
story: community-chat-bot-11
spec: community-chat-bot
status: done
tier: 3
worker: worker-code
tracer: false
wave: 4
blocked_by: [community-chat-bot-02]
---

# Канал прод ↔ локаль: две spark-команды (ADR-176)

## Goal
Появляются `php spark community:export` и `php spark community:import` — единственный
канал между боевым сервером и локальной машиной. Публичного HTTP-API не будет: `app/Commands/`
из деплойного rsync не исключён, удалённое исполнение через SSH уже есть.

## Requirements
> его можно настроить так, чтобы он автохуками принимал всю информацию с Telegram и на боевой сервер игры складывал в какую-то директорию

> а мы, когда запускаем тут cloud code, чтобы шла автоматическая синхронизация

## Files
- app/Commands/CommunityExport.php
- app/Commands/CommunityImport.php
- tests/unit/Commands/CommunityExportTest.php
- tests/unit/Commands/CommunityImportTest.php

## Non-goals
- **Не добавлять маршрутов и фильтров.** `Routes.php` и `Filters.php` в этой подсистеме
  не трогаются вовсе — это прямое следствие ADR-176.
- Не чинить легаси-ветку `BotController.php:47-54` (отсутствующий `WEBHOOK_SECRET`
  пропускает проверку). ADR объявляет шаблон запрещённым, но это отдельная задача (Закон 3).
- Не писать локальный хук — он в story 13.
- Не давать импорту публиковать что-либо в чат.

## Map slice
`memory/map/admin.md` §spark-команды; образец CI4-команды — `app/Commands/OnboardingCohorts.php`
или `NavMapDump.php`. Деплой: `.github/workflows/deploy.yml` (rsync исключает `scripts/`,
но не `app/`), SSH-ключ `~/.ssh/wildworld_deploy`, пользователь `wildworld-bot@bot.wildworld.fun`.

## Contract (ADR-176)
**`community:export --since=<id> [--limit=200]`**
- Курсор — `--since=<id>` по автоинкременту, **не offset**: offset при вставках пропускает строки.
- Лимит 200 на вызов, жёсткий потолок 1000.
- **stdout — только JSON.** Вся диагностика в stderr, ненулевой exit code при сбое.
  Иначе вывод склеится с данными и локальный парсер молча получит мусор.
- Отдаёт открытые вопросы и контекст треда; не отдаёт ничего сверх настроенного `chat_id`.

**`community:import`** (JSON со stdin)
- Не более 100 черновиков и 256 КБ на вызов; текст черновика до 3500 символов.
- **Создаёт строки только со `status='draft'`.** Это и есть защита push-канала: путь
  «черновик → сказано в чат» идёт исключительно через `/admin/community` с `audit()`.
- Идемпотентность по `client_key` (ULID, генерится локально): повторный импорт той же
  пачки не плодит строк.
- **Строку в статусе `approved` или `rejected` импорт изменить не имеет права** — иначе
  повторный push отменил бы решение владельца.

## Acceptance criteria
- [ ] `export` печатает в stdout валидный JSON и ничего кроме него; диагностика в stderr.
- [ ] `export --since=N` не возвращает строк с `id <= N`.
- [ ] `export --limit=5000` обрезается до потолка 1000, а не выгружает всё.
- [ ] `import` создаёт строки со `status='draft'` — попытка задать другой статус во входном
      JSON игнорируется, а не принимается.
- [ ] Повторный `import` той же пачки не создаёт дублей (UNIQUE `client_key`).
- [ ] `import` пачки, где `client_key` указывает на строку в статусе `approved`, эту строку
      не меняет и сообщает об этом ненулевым кодом или предупреждением в stderr.
- [ ] Вход больше 256 КБ или больше 100 черновиков отклоняется целиком, а не частично.
- [ ] Черновик длиннее 3500 символов отклоняется.
- [ ] Обе команды не падают на пустом входе.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Commands/`

## Implementation notes

- `CommunityExport::collectRows()` скоупит по `community.chat_id` (GameSettings, fail-closed:
  пусто/нечисло → `[]`, не ошибка), курсор `id >`, отбирает `is_question=1` ИЛИ строки треда,
  где в чате уже есть открытый вопрос (`orWhereIn(message_thread_id, ...)`), режет `--limit`
  через `clampLimit()` (default 200, hard cap 1000). `run()` пишет ровно один JSON в stdout,
  всё остальное — `fwrite(STDERR, ...)`.
- `CommunityImport::parseInput()` — чистая функция над строкой (без STDIN/БД): байтовый
  потолок 256 КБ и потолок 100 черновиков отклоняют пачку целиком; пустой вход — легитимный
  `ok=true, drafts=[]`. `applyBatch()` — построчная валидация (обязательные поля, 3500 симв.
  на `answer_text`) и запись: `insert()`/`update()` никогда не передают поле `status` —
  это и есть защита push-канала. Апдейт разрешён только когда существующая строка `draft`;
  `approved`/`rejected`/`revoked` — пропуск с предупреждением в stderr (не ошибкой, чтобы
  остальная пачка доходила).
- CI4 discovery (`Commands::discoverCommands()`) инстанцирует каждую команду позиционно как
  `new $class($logger, $commands)` — DI-параметр `$settings` в `CommunityExport` обязан идти
  третьим, иначе падает весь `php spark` (не только эта команда). Использованы
  `\Config\Services::logger()`/`::commands()` вместо хелпера `service()` — у них типизированный
  `@return`, `service()` даёт `object|null` и не проходит phpstan L9.
- Оба теста создают свои таблицы через реальные миграции (`Adr176CreateCommunity{Messages,
  Answers}Table`) на группу `tests`, паттерн `CommunityIngestServiceTest`; `GameSettingsService`
  в `CommunityExportTest` — через анонимный двойник `GameSettingsModel`.

## Findings

### Приёмка Queen — чистота stdout проверена реальным запуском

Воркер честно отметил, что чистота stdout не покрыта тестом и держится на ревью кода.
Проверено прогоном, и **дефект нашёлся** — причём такой, который ни юнит-тест, ни ревью
файла команды поймать не могли, потому что источник вне команды:

```
$ php spark community:export --since=0 --limit=5 >out 2>err
$ cat out
(пустая строка)
CodeIgniter v4.7.2 Command Line Tool - Server Time: ...
[]
```

Баннер печатает сам `php spark`, до запуска команды. Локальный парсер получил бы мусор
ровно так, как предупреждал контракт ADR-176. Штатное средство помогает:

```
$ php spark --no-header community:export --since=0 --limit=5 >out 2>/dev/null
$ cat out
[]
```

**Следствие, вынесенное в другие артефакты:** флаг `--no-header` обязателен в любом
вызове `community:export`. Записано в контракт story 13 (локальный хук) и в ADR-176.
Плюс story 13 обязана проверять, что полученное — валидный JSON, и падать громко:
второй рубеж на случай, если stdout когда-нибудь загрязнит что-то ещё.

