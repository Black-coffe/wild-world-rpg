---
story: community-chat-bot-13
spec: community-chat-bot
status: done
tier: 2
worker: worker-code
tracer: false
wave: 5
blocked_by: [community-chat-bot-11]
---

# Локальная синхронизация: inbox приезжает при запуске Claude Code

## Goal
Ровно то, что просили дословно: при старте сессии Claude Code открытые вопросы из чата
сами приезжают на локальную машину. Никакого ручного шага. Вне сессии канал не работает —
это и есть «бот обновляет информацию мне только когда я запускаю Claude Code».

## Requirements
> Он должен работать только тогда, когда я запускаю cloud code.

> а мы, когда запускаем тут cloud code, чтобы шла автоматическая синхронизация

## Files
- .claude/hooks/community-pull.sh
- .claude/settings.json
- .gitignore
- tests/unit/Commands/CommunityPullHookTest.php

## Non-goals
- Не запускать пуш при старте сессии: пуш — осознанное действие в конце работы, не побочный
  эффект открытия терминала.
- Не трогать существующие хуки `session-start-brief.sh`, `vulyk-update-check.sh` и
  PowerShell-хук multi-machine git check — добавляется четвёртый, соседи не правятся.
- Не печатать содержимое чата в транскрипт: вывод идёт в файл. Всё, что попало в
  транскрипт, пересылается каждый следующий ход.
- Не хранить SSH-ключ и не встраивать пароли: ключ уже есть у пользователя.

## Map slice
`.claude/settings.json` → `hooks.SessionStart` — массив из трёх хуков, каждый
`{type: command, command: bash "$CLAUDE_PROJECT_DIR/.claude/hooks/<файл>", timeout, statusMessage}`.
Существующий образец — `.claude/hooks/session-start-brief.sh`.

## Contract (ADR-176)
- Хук дёргает `ssh -i ~/.ssh/wildworld_deploy wildworld-bot@bot.wildworld.fun
  "cd ~/htdocs/bot.wildworld.fun && php spark community:export --since=<id>"`.
- 🔴 **Вызов ОБЯЗАН включать `--no-header`.** Проверено на приёмке story 11: без него
  `php spark` печатает в stdout свой баннер («CodeIgniter v4.7.2 Command Line Tool —
  Server Time: …») ДО запуска команды, и stdout перестаёт быть валидным JSON. Ни юнит-тест
  команды, ни ревью её файла этого не видят — баннер печатает фреймворк, а не команда.
- 🔴 **Полученное проверяется как JSON перед записью в inbox, и хук падает громко, если
  это не JSON** — второй рубеж на случай, если stdout загрязнит что-то ещё. Тихо записать
  мусор хуже, чем не записать ничего.
- Курсор хранится в `.claude/community/state.json` (последний виденный `id`).
- Результат пишется в `.claude/community/inbox-<дата>.json`, **не в stdout хука**.
- В `additionalContext` хук возвращает **одну строку**: сколько новых вопросов приехало и
  где лежит файл. Не содержимое.
- Хук **никогда не валит старт сессии**: нет сети, нет ключа, прод недоступен — сообщает
  одной строкой и выходит с кодом 0. Массовый сетевой отказ не должен читаться как
  «прод умер» (`feedback_network_failure_is_not_evidence_of_death`).
- Таймаут не больше 20 секунд, как у соседних хуков.
- `.gitignore` получает `.claude/community/` — там сырой текст чата игроков.

## Acceptance criteria
- [ ] После старта сессии в `.claude/community/` лежит файл inbox, а в контексте — одна
      строка со счётчиком.
- [ ] Содержимое чата в транскрипт не попадает.
- [ ] При недоступном SSH хук отдаёт понятную строку и код 0; сессия стартует нормально.
- [ ] Повторный запуск не тянет уже виденное: `state.json` двигается.
- [ ] Повреждённый `state.json` не роняет хук — курсор сбрасывается в 0 с предупреждением.
- [ ] `.claude/community/` игнорируется git: `git check-ignore` подтверждает.
- [ ] Три существующих SessionStart-хука продолжают работать, порядок не сломан.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Commands/`

Плюс ручная проверка самого хука: `bash .claude/hooks/community-pull.sh` — валидный JSON
в stdout, файл на месте, код 0 при отключённой сети.

## Implementation notes

- `.claude/hooks/community-pull.sh` — курсор из `.claude/community/state.json` (повреждённый
  файл → сброс в 0 с предупреждением в stderr), затем `ssh -i ~/.ssh/wildworld_deploy … "cd
  ~/htdocs/bot.wildworld.fun && php spark --no-header community:export --since=<id>"`. Второй
  рубеж — валидация JSON через `php -r` перед записью (тихий мусор в inbox хуже, чем ничего);
  инвалид/сбой SSH/нет ключа → одна строка в stdout, код 0 всегда (`exit 0` на каждой ветке
  сбоя). Результат — `.claude/community/inbox-<дата>.json`; курсор двигается на max `id` из
  выгрузки. `timeout` оборачивает `ssh` только если команда есть в PATH (Windows Git Bash не
  гарантирует coreutils `timeout`) — иначе таймаут держит только `-o ConnectTimeout` ssh и
  внешний `timeout: 20` самого хука в `settings.json`.
- `.claude/settings.json` — четвёртый хук добавлен в существующий массив `SessionStart.hooks`
  (три соседних не тронуты, порядок сохранён).
- `.gitignore` — `.claude/community/` уже накрыт правилом `.claude/*` (проверено
  `git check-ignore` до правки), явная строка добавлена как задокументированное намерение на
  случай, если список исключений выше изменится.
- `tests/unit/Commands/CommunityPullHookTest.php` — реально исполняет bash-скрипт как процесс
  (`proc_open`), не грепает исходник (`feedback_source_scan_tests_are_not_coverage`): `ssh`
  подменяется фейковым исполняемым первым в `$PATH`, `$HOME`/`$CLAUDE_PROJECT_DIR` — временный
  каталог на тест. На Windows голое `bash` в `$PATH` резолвится в WSL (см. Findings) — явный
  путь `C:\Program Files\Git\bin\bash.exe`; передача `$PATH` через `env`-массив `proc_open()`
  не сработала (git-bash не подхватывает смешанный Windows/Unix формат) — вместо этого
  launcher-скрипт собирает `$PATH` изнутри уже запущенного bash.

## Findings

### PATH через `proc_open()`'s env-массив не работает на Windows/git-bash

При написании теста три подхода к подмене `ssh` не сработали, четвёртый сработал:
1. `proc_open(['bash', ...], ..., $env)` с модифицированным `$env['PATH']` — молча резолвит
   голое `bash` в `C:\Windows\System32\bash.exe` (WSL), а не Git Bash; PATH внутри WSL в другом
   формате (`/mnt/c/...`), фейковый unix-путь `/c/...` не совпадает.
2. Тот же `env`-массив, но с явным `C:\Program Files\Git\bin\bash.exe` — тоже не сработало:
   git-bash использует собственный `$PATH` не из переданного `env`, если он смешанного формата
   (Windows-style `C:/...` вперемешку с MSYS `/mingw64/...`) — резолвит системный `/usr/bin/ssh`.
3. Инлайн `PATH="/c/.../fakebin:$PATH"; ssh` внутри `-c` строки — сработало отдельно, но
   собирать весь вызов хука как одну `-c`-строку с несколькими экспортами и вложенным вызовом
   `bash "$script"` оказалось хрупким на кавычках.
4. **Рабочее решение**: launcher-скрипт на диске (`export HOME="$1"; export
   CLAUDE_PROJECT_DIR="$2"; export PATH="$3:$PATH"; exec bash "$4"`), вызванный через
   `proc_open` с явным `C:\Program Files\Git\bin\bash.exe` и позиционными аргументами (без
   `env`-массива вовсе). `$PATH`-компонент обязан быть в unix-виде (`/c/Users/...`) — иначе
   двоеточие после буквы диска (`C:/...`) рвёт список PATH на две части. `$HOME` и
   `$CLAUDE_PROJECT_DIR` от этой проблемы не страдают (это одиночные пути, не PATH-списки) —
   Windows-style с прямыми слэшами (`C:/Users/.../tmp`) работает как есть.

Актуально для любого будущего теста, реально исполняющего bash-хуки на этой машине через PHP.

### `shell_exec()` на Windows зовёт `cmd.exe`, не bash

Первая версия `testCommunityDirIsGitIgnored` использовала `shell_exec('... ; echo $?')` —
`;`/`$?` не значат ничего в `cmd.exe` (PHP на Windows шеллит через него по умолчанию), git
получил `; echo $?` как лишний позиционный аргумент и упал с «--quiet is only valid with a
single pathname». Исправлено на `proc_open(['git', 'check-ignore', '-q', ...])` с массивом
аргументов и чтением кода через `proc_close()` — без POSIX-конструкций в командной строке.

### Приёмка Queen — разрешение конфликта двух требований

Контракт требовал и «падать громко при невалидном JSON», и «никогда не валить старт
сессии». Воркер заметил противоречие и выбрал: видимое сообщение в контекст сессии
(`[community-pull] получен невалидный JSON — синхронизация пропущена`) + выход с нулём.
Принято: громкость нужна была для того, чтобы владелец узнал о поломке канала, а не
чтобы сломать ему терминал. Мусор в inbox при этом не попадает — ради чего рубеж и вводился.

