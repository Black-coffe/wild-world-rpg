---
story: community-chat-bot-13
spec: community-chat-bot
status: todo
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

## Findings
