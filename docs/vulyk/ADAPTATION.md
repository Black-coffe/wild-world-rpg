# VULYK: чем эта установка отличается от ванильной

Установлено 2026-08-19, обновлено 2026-09-05 до версии **0.11.0**, источник —
`https://github.com/Black-coffe/vulyk`. Штамп версии — `.claude/vulyk-version`.
Обоснование решения — `mmorpg-vault/decisions/ADR-170`.

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

**Апгрейд 0.11.0 (2026-09-05):** сценарий повторился ровно как описано — `handoff.py` и `handoff.sh`
вернулись (в `settings.json` при этом не прописались), удалены снова; `.claude/commands/vulyk-handoff.md`
поставка перезаписала своей версией (`bash .claude/hooks/handoff.sh dump`) — восстановлена наша.
Ничего, кроме этих трёх файлов и двух абзацев конституции (§6), доводить руками не пришлось.

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

**Правка 0.10.0:** апгрейд снова дописал свой блок из пяти строк (`.claude/handoff/`,
`.vulyk-update-cache`, `settings.json.vulyk-bak`, `state.json`, `settings.local.json`). На этот раз
он **оставлен**: все пять уже покрыты правилом `.claude/*` выше, то есть блок избыточен и безвреден,
а вычищать его каждый апгрейд — работа без выхода (`ensure_gitignore` дописывает только
недостающее, так что стабильное состояние дешевле пустого). Проверено, что ни один трекаемый файл
рамки под новые строки не попал.

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

### 5.1. `TOP_MODEL = opus` живёт в `CLAUDE.md`, а не в `CLAUDE.vulyk.md` (0.10.0)

**Что.** Строка `**Модель верхней касты: TOP_MODEL = opus.**` добавлена в `CLAUDE.md`, в блок
«🐝 РАМКА РАБОТЫ: VULYK». Обоснование выбора модели по-прежнему в `CLAUDE.vulyk.md`.

**Почему.** В 0.10.0 появился `scripts/top-model.sh`, который резолвит верхнюю касту от тарифа, а
пин в конституции ставит выше тарифа. В комментарии скрипта заявлено, что он читает и `CLAUDE.md`,
и `CLAUDE.vulyk.md`, но `constitution_pin()` делает `return` **внутри** цикла `for` — после первого
существующего файла. У нас конституция расщеплена (см. §5), пин лежал во втором файле, и резолвер
его не видел: `--explain` выдавал `decided by: plan` и `fable` (аккаунт Max 20x). То есть
планирование и финальное ревью молча уехали бы на Fable 5.1 — вопреки решению держать Opus 5
(вне 30-дневного retention, вдвое дешевле).

**Почему именно так чинили.** Правка скрипта была бы четвёртой правкой внутри рамки и откатывалась
бы каждым апгрейдом. `CLAUDE.md` установщик не перезаписывает никогда — пин там переживает всё.

**Проверка после апгрейда:** `bash scripts/top-model.sh --explain` → `decided by: constitution`.
Если увидишь `decided by: plan` — строку из `CLAUDE.md` кто-то потерял.

**Что осталось не сделанным намеренно:** `bash scripts/top-model.sh --apply` не запускался — он
пишет `"model"` в `.claude/settings.local.json`, то есть решает за владельца, на чём стартует его
собственная сессия. Хук `top-model-brief.sh` каждую сессию сообщает, что сессия «not pinned»;
это ожидаемо, а не поломка.

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

Плюс две проверки, добавленные после 0.10.0:

```bash
bash scripts/top-model.sh --explain | sed -n 2p   # должно быть "decided by: constitution" (§5.1)
ls .claude/rules/example-api.md                   # должно отсутствовать (см. ниже)
```

`.claude/rules/example-api.md` — ванильная заглушка из поставки, объявляющая пути `src/api/**` и
`server/**`, которых в этом репозитории нет. Удаляется после каждого апгрейда: `.claude/rules/`
трекается (§3), и класть туда правило про несуществующий код — значит везти в git заведомую неправду.

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

---

## 6. Что доведено руками в апгрейде 0.11.0

Релиз 0.11.0 достроил цикл до шести стадий (`spec → plan → code → tests → **human** → **ship**`):
появились `/vulyk-ship`, `scripts/ship-check.sh`, `scripts/human-check.sh`, ветка `vulyk/<slug>`
на стадии build и «путь клиента» у `drone-acceptance`. Установщик конституцию не трогает, поэтому
в `CLAUDE.vulyk.md` вручную доведено:

- **Две новые строки `## Profile`** — `Client path` (Telegram-бот `@wildworldrpg_bot` + близнец на
  preprod-testbot, тест-чар `telegram_user_id=25`, сайт `wildworld.fun`, админка `/admin/*`) и
  `Release / deploy` (тег на `develop` → GitHub Actions → `post-deploy.sh`; зелёный preprod-смоук
  уже есть добро на прод-тег). Их читают `/vulyk-build`, `/vulyk-ship` и `drone-acceptance`.
- **Раздел `## The cycle`** с таблицей шести стадий и проектным абзацем: стадия 05 смотрится на
  preprod-testbot'е, стадия 06 — тег на `develop`.
- Колонка Protocol в таблице тиров (Tier 2–3 доведены до `your look → /vulyk-ship`), строка
  `Ship gate, per spec` в `## Commands`, `human.jsonl` / `ship.jsonl` в списке `memory/stats/`.
- Второй ревьюер Tier 4: у нас гейт идёт на `opus`, значит второй — `fable` или `sonnet`.

Пин `TOP_MODEL = opus` **не** взят из поставки: ванильная 0.11.0 ставит `auto`, который на Max
решает в пользу Fable. Наш пин остаётся, обоснование — в `CLAUDE.md`.

Удалён `.claude/rules/example-api.md` — ванильный пример под `src/api/**`, каталога такого в
проекте нет, а `README` правил прямо запрещает generic-правила.
