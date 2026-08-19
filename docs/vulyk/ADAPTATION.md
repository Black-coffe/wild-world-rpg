# VULYK: чем эта установка отличается от ванильной

Установлено 2026-08-19, версия **0.9.5**, источник — `https://github.com/Black-coffe/vulyk`.
Штамп версии — `.claude/vulyk-version`. Обоснование решения — `mmorpg-vault/decisions/ADR-170`.

Файл существует ради одной задачи: `install.sh --upgrade` и `/vulyk-update` **заменяют**
framework-owned файлы (`.claude/agents`, `.claude/commands`, `.claude/hooks`,
`.claude/skills/_meta`, `bootstrap/`, `templates/`, `scripts/`) на версию из новой поставки.
Всё, что перечислено ниже как «правка внутри рамки», после апгрейда откатится к ванильному
состоянию, и это надо будет заметить и повторить. Всё, что перечислено как «за пределами рамки»,
апгрейд не трогает никогда.

---

## 1. Удалено: дубль сторожа контекста

**Что.** `.claude/hooks/handoff.sh` и `.claude/hooks/handoff.py` удалены из установки.

**Почему.** На этой машине уже работает тот же самый сторож — `~/.claude/hooks/context_guard.py`,
подключённый **глобально** ко всем пяти событиям (`SessionStart`, `Stop`, `UserPromptSubmit`,
`PreCompact`, `SessionEnd`). Это форк того же скрипта: тот же режим `dump`, тот же формат
handoff-документа, и — главное — та же директория назначения `<project>/.claude/handoff/`
(см. `handoff_dir()`: «Prefer `<cwd>/.claude/handoff`»). Глобальная копия настроена на окно 1M
(`context_limit: 1000000`, пороги 200k/300k/400k), поставочная — на 200k.

Две копии дали бы: два дампа на каждое событие, два эскалационных баннера пользователю и две
вставки «найден хендофф предыдущей сессии» при старте сессии. Отключить глобальный хук
из проектных настроек нельзя — хуки складываются, а не перекрывают друг друга.

**Как это работает сейчас.** `/vulyk-handoff` переписан: он по-прежнему сначала регенерирует
`scripts/state.sh`, а затем вызывает `python "$HOME/.claude/hooks/context_guard.py" dump`.
Интерфейс совпадает — первая строка вывода это путь, вторая `context_tokens=<N>`.

**После апгрейда.** Оба файла вернутся, а `wire_session_hook` может дописать их в
`.claude/settings.json`. Удалить снова, из `SessionStart` вычистить. Проверка: в
`.claude/settings.json` не должно быть ни одного вхождения `handoff.sh`.

> Правка внутри рамки: `.claude/commands/vulyk-handoff.md`.

---

## 2. Хуки: обёртка `bash` и слияние с проектными

**Что.** `.claude/settings.json` собран заново, а не взят из поставки.

- Каждый `.sh`-хук вызывается как `bash "$CLAUDE_PROJECT_DIR/.claude/hooks/<script>"` — на Windows
  голый путь к `.sh` не исполняется. Совпадает с конвенцией глобальных настроек, где Python-хуки
  тоже вызываются через явный интерпретатор.
- Из `SessionStart`/`SessionEnd`/`Stop`/`UserPromptSubmit` убраны записи про `handoff.sh` (см. §1).
- В тот же файл **перенесены три проектных хука**, которые раньше жили в
  `.claude/settings.local.json`: multi-machine git-check на `SessionStart`, напоминание
  Редколлегии на запись в `inbox/site-drafts/`, напоминание WIPE-COVERAGE на запись в
  `app/Database/Migrations/`. Они дословно те же.

**Почему перенос.** `settings.local.json` игнорируется git'ом, поэтому эти правила не доезжали
на вторую машину — ADR-087 прямо фиксирует «local-only, продублирован в ADR для ноутбука».
Теперь они в трекаемом `settings.json` и едут с репозиторием. В `settings.local.json` осталось
только машинно-локальное: `autoMemoryDirectory` и список разрешений.

> За пределами рамки: `.claude/settings.json` не является framework-owned, апгрейд его не заменяет
> (но может **дописать** в него `vulyk-update-check.sh`, если найдёт, что он не подключён).

---

## 3. `.gitignore`: рамка теперь в git

**Что.** Раньше `.claude/` игнорировался целиком. Теперь игнорируется `.claude/*`, а обратно в
трекинг явно возвращены `agents/`, `commands/`, `hooks/`, `rules/`, `skills/`, `settings.json`,
`vulyk-version`.

**Почему.** Иначе улей существовал бы только на одной машине: агенты, команды и хуки не поехали бы
в git, и вторая машина осталась бы без рамки — ровно та проблема, что и с хуками в §2. Заодно в
трекинг попадают агенты и скилл Редколлегии, которые до сих пор были локальными.

Вне трекинга остаётся всё, что должно там остаться: `settings.local.json`,
`admin-credentials.local.md`, `handoff/`, `worktrees/`, скриншоты, `state.json`,
`.vulyk-update-cache`. Проверяется одной строкой:

```bash
git check-ignore -v .claude/admin-credentials.local.md .claude/settings.local.json .claude/handoff/
```

Строки, добавленные установщиком (`memory/snapshots/`, `memory/map/.stale`, `CLAUDE.local.md`,
`__pycache__/`), оставлены; дубли по `.claude/*` из его блока убраны как избыточные.

---

## 4. Деплой: рабочие каталоги улья не уезжают на прод

**Что.** В обе `rsync`-стадии `.github/workflows/deploy.yml` (preprod и прод) добавлены
`--exclude` для `.claude`, `memory`, `templates`, `bootstrap`, `scripts`, `CLAUDE.vulyk.md`.

**Почему.** Деплой синхронизирует рабочее дерево целиком, минус короткий список исключений.
После §3 `.claude/` попадает в чекаут CI — и без этой правки агенты, команды и карта улья
поехали бы на живой сервер. Это не ломает игру, но кладёт на прод то, что там не нужно, и
раздувает каждый релиз.

`docs/` намеренно **не** исключён: он уезжал на прод и до VULYK.

---

## 5. Конституция

`CLAUDE.md` проекта не тронут по сути — в него добавлен блок «🐝 РАМКА РАБОТЫ: VULYK» с разделением
ответственности («что строим» — `CLAUDE.md`, «как строим» — `CLAUDE.vulyk.md`) и строка импорта
`@CLAUDE.vulyk.md`.

`CLAUDE.vulyk.md` — конституция улья. Заполнены оба маркированных блока (`## Profile`,
`## Commands`) и добавлен раздел **`## Project bindings`**, который переопределяет ванильные пути и
раскладывает восемь конституционных правил проекта по кастам.

> За пределами рамки: конституцию установщик не перезаписывает ни при установке, ни при апгрейде.
> При выходе новой версии он лишь печатает `diff` и предлагает слить изменения руками.

---

## 6. Правки внутри рамки — что повторить после апгрейда

Только три файла, и все три помечены изнутри:

| Файл | Что добавлено | Как найти после апгрейда |
|---|---|---|
| `.claude/commands/vulyk-handoff.md` | вызов глобального `context_guard.py` вместо удалённого `handoff.sh` | `grep -L context_guard .claude/commands/vulyk-handoff.md` |
| `.claude/agents/lead-architect.md` | блок «Project path binding» — ADR пишутся в `mmorpg-vault/decisions/` | `grep -L "Project path binding" .claude/agents/lead-architect.md` |
| `.claude/agents/drone-docs.md` | блок «Project path binding» — ноты пишутся в `mmorpg-vault/tech-writing/` | `grep -L "Project path binding" .claude/agents/drone-docs.md` |

Одной командой — что откатилось:

```bash
grep -L "Project path binding" .claude/agents/lead-architect.md .claude/agents/drone-docs.md
grep -L "context_guard"       .claude/commands/vulyk-handoff.md
ls .claude/hooks/handoff.*        # должно быть пусто
grep -c "handoff.sh" .claude/settings.json   # должно быть 0
```

Пустой вывод первых двух и отсутствие `handoff.*` = апгрейд ничего не сломал.

---

## 7. Что осталось ванильным

Не тронуто ничем: все касты кроме двух (`queen-planner`, `worker-code`, `worker-test`,
`lead-review`, `librarian`, `drone-scout`, `drone-coverage`, `drone-acceptance`), все команды кроме
`/vulyk-handoff`, все скрипты `scripts/*.sh`, шаблоны, `bootstrap/interview.md`, мета-скиллы
`insight-harvester` и `skill-gardener`, `AGENTS.md`.

Роспуск ролей не проводился: у проекта есть и тест-раннер (PHPUnit), и способ наблюдать
собранное в работе (Tier-2 админка, Tier-3 живой Telegram), поэтому ни `worker-test`, ни
`drone-acceptance` удалять не пришлось.

---

## 8. Известные шероховатости, которые НЕ правились

- **`session-start-brief.sh` вечно показывает «learnings awaiting GC: 1».** Хук считает все `*.md`
  в `memory/learnings/` кроме `CONSOLIDATED.md`, а там лежит `README.md` из поставки — он и даёт
  постоянную единицу. Косметика; правка добавила бы четвёртый дрейфующий файл ради одной цифры,
  поэтому оставлено как есть. Читать как «на одну больше, чем на самом деле».
- **`SessionEnd` пишет заготовку урока каждую сессию.** Так задумано (консолидация — `/vulyk-gc`),
  но заготовки попадают в git. Если начнут копиться пустыми — резать хук, а не чистить руками.
- **Индекс решений в vault'е отстаёт:** ADR-168 и ADR-169 существуют, но строк в
  `mmorpg-vault/decisions/index.md` не имеют. К установке отношения не имеет, замечено попутно.
