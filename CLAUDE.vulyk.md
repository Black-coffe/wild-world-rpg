# VULYK Constitution

This project runs on VULYK (ADR-170). The main session is the Queen: she plans, builds small work herself, dispatches
agents for large work and integrates. Procedures live in the `/vulyk-*` commands.

Этот файл — **КАК** (тиры, касты, совет, модели, цикл); `CLAUDE.md` — **ЧТО** (канон и предметные правила 1–10),
и при конфликте выигрывает `CLAUDE.md`. Каркас ниже совпадает с поставкой 0.21.1 почти дословно, чтобы следующий
апгрейд сводился диффом; всё проектное — в `## Project bindings`. Что в самой рамке у нас не ванильное и что
возвращать после `/vulyk-update` — `docs/vulyk/ADAPTATION.md`. Документы рамки (`cycle.md`, `model-cascade.md`,
`token-economy.md`, ADR рамки) в наш репо не ставятся: они в `~/.vulyk/src/docs/`.

## Laws

1. Make routine calls yourself. Ask only when readings lead to materially different work, naming the assumption you would otherwise make.
2. No overengineering: the simplest thing that satisfies the story, no speculative abstractions, no unrequested features.
3. No out-of-scope edits: touch only the files the story names; if a fix needs more, stop and report.
4. Surface tradeoffs: say what you chose, what you rejected and why, in a sentence or two.
5. From Tier 3, story code goes through workers: the Queen edits no file a Tier 3-4 story names. At Tier 0-2 she builds herself.
6. An owner's correction joins its defect class in `docs/defects/` as a verbatim quote. A class code can check gets a failing `check:` with the original case and a neighbour-form fixture in the same work (inside a Tier 3-4 story naming the file: the next repair story); a repeated class without one is debt that fails `scripts/defects-check.sh`. A check that only warns is not a check.

Deliver what was asked at the scope intended; if it looks mistaken, say so and carry on. Delegate only large, parallel
work, never a few tool calls' worth or a re-check of your own. Size plans, stories and reports to the task. Do not tell
an agent to verify itself.

## Routing

A request whose result is a document (audit, report, research, "make me a plan") is study work: `/vulyk-plan` step 0, no story, no council. Changed code gets a tier:

| Tier | Signal | Who builds | Council seats | Rounds | Driver |
|---|---|---|---|---|---|
| 0 | trivial, one file | the Queen, no paperwork | none | - | none |
| 1 | one module, clear task | the Queen, solo | `review` | 1 | none |
| 2 | feature within a module | the Queen, solo, fresh session after approval | `review` | 2 | none |
| 3 | cross-cutting, multi-module | workers in waves | `opus`, `review`; `haiku` if *Client path* is filled | 3 | Workflow |
| 4 | architecture, migration | workers + `lead-architect` | as Tier 3; `review` folds a second reviewer | 3 | Workflow |

Tier 2-4 plans stop for the owner's approval (`**Approved:**`) unless `/vulyk-plan --go`; Tier 1 runs straight through.

У нас: тир называется **до** работы (`CLAUDE.md`). *Client path* заполнен, поэтому на Tier 3–4 место `haiku` есть
всегда — но без браузера (см. Profile). Rounds — потолок, а не цель: исчерпан, или тот же ask RED два раунда
подряд — `## Needs a human` в `plan.md`, цикл стоит; это сигнал, что ask сформулирован непроверяемо, а не повод
открыть лишний раунд руками.

## Models and effort

Route by family, never by version: Sonnet executes (workers, scout, docs drone, clerk), Opus orchestrates and judges (the Queen, planner, reviewers, council, coverage, librarian), Haiku runs the clerk only. The family that builds never judges. A repair story after a RED round climbs to Opus.
**Гейт у нас тоже Opus, а не Fable:** пин `TOP_MODEL` стоит в `CLAUDE.md` (не здесь: `scripts/top-model.sh` берёт первое вхождение, и `CLAUDE.md` читается первым); проверка — `bash scripts/top-model.sh --explain` → `decided by: constitution`. Fable у нас только второй ревьюер Tier 4 (это же печатает `--explain`).
Model floor: no dispatch below `scripts/lib.sh` `model_floor`; always the newest of each family. `bash scripts/top-model.sh --floor` checks the config, telemetry `model_below_floor` what really ran.
Pass it as `model:` only on the Tier 4 review, `lead-architect`, the Tier 4 `queen-planner` and a missed story's retry.
Effort lives in agent frontmatter. Не переключай `/model`, `/effort` и fast mode посреди сессии: это заново оплачивает весь закэшированный префикс; модель субагента задаёт его frontmatter или параметр dispatch.

## Secrets

Name a secret by its env var (`STRIPE_KEY`), never by value.
Briefs and the handoff dump pipe through `scripts/redact.sh`: a seatbelt, not permission.
A secret that reaches git is rotated, not deleted.

## Profile

What this project is; `/vulyk-bootstrap` fills it. A reviewer demands nothing beyond *Configurations that exist today*.
A filled *Client path* adds the black-box seat, the only reader of *Browser MCP*. `/vulyk-ship` prints *Release / deploy*.

<!-- VULYK:PROFILE:START -->
| Field | Value |
|---|---|
| Stack | PHP 8.3 · CodeIgniter 4 · MySQL/MariaDB · longman/telegram-bot. Один репозиторий несёт три поверхности: Telegram-бота, публичный сайт `wildworld.fun` и админку. |
| Package manager / runner | Composer; CLI-задачи через `php spark <command>`. Локально — Laragon (Apache + MySQL). |
| Where source lives | `app/` — Models (~80), Services (~247 файлов в 40 доменных папках), Controllers (~346, из них `Telegram/Commands/Actions/` ~54 и `Admin/` 22), TaskHandlers (74), Database/Migrations (524). Тесты — `tests/{unit,database,session}`. Публичные ассеты и точка входа — `public/`. |
| Test framework | PHPUnit 11.5, конфиг `phpunit.xml.dist`, bootstrap CI4. Часть тестов ходит в **отдельную** MySQL-базу `wildworld_tests` на 127.0.0.1 (root/пусто). Если MySQL не поднят — они падают на `Unable to connect to the database`, и это состояние машины, а не регресс. |
| Commit convention | Conventional Commits с русским текстом сообщения: `feat(дрон): заряжается и в поле`, `fix(раны): источник ран не видел биом`. Ветка работы — `develop`, `master` — прод. |
| **Configurations that exist today** | Три живых окружения. **Локальное**: Laragon, MySQL должен быть запущен. **Preprod-testbot**: SSH-доступ (`~/.ssh/wildworld_deploy`), разрешены любые `UPDATE` и ad-hoc `php spark`. **Прод `wildworld.fun`**: живые игроки, деструктивные смоки запрещены, INFO не логируется — мониторинг через `action_log`. Деплой — GitHub Actions: тег на `develop` → rsync релиза → `deploy/post-deploy.sh` применяет миграции. Один узел приложения и одна БД; очереди/воркер-пула **нет** — фоновая обработка идёт через cron → `Controllers/Worker.php` → `app/TaskHandlers/`. Контейнеров, staging-кластера и blue-green нет и не планируется. |
| Client path | Три двери, и почти всегда нужна первая. **Игрок** — Telegram-бот `@wildworldrpg_bot` (прод) и его близнец на preprod-testbot; живой проход — MCP Chrome + Telegram Web со **второго** аккаунта, тест-чар на testbot `telegram_user_id=25`. Автономная альтернатива, когда браузер не нужен: POST игрового апдейта прямо на вебхук testbot'а с секрет-заголовком (`reference_autonomous_webhook_tier3_smoke`). **Публичный сайт** — https://wildworld.fun, тихая проверка маршрута: `curl -sS -o /dev/null -w '%{http_code}' <route>`. **Админка** — `/admin/*` через MCP Chrome под аккаунтом владельца (пароль — `writable/secrets/`, в переписку не попадает). На проде живой проход по игроцкой двери не делаем — там живые игроки. |
| Browser MCP | `none` — сознательно, не «ещё не заполнили». Оба доступных MCP-браузера ходят под живыми аккаунтами: `chrome-devtools` — под владельцем в `/admin/*`, Telegram Web — под вторым личным аккаунтом. Отдельного read-only тест-профиля нет, поэтому `council-haiku` браузер НЕ получает: он проходит `Client path` тем, что не требует логина (публичный сайт `curl`, автономный POST на вебхук testbot'а), а живой проход в Telegram остаётся ручным Tier-3 смоуком. Появится изолированный профиль — строка меняется на `chrome-devtools`. |
| Release / deploy | Работа идёт в `develop`, `master` — прод-ветка, но релиз едет **не** через неё: версия публикуется **тегом на `develop`**, GitHub Actions гонит rsync релиза и `deploy/post-deploy.sh` применяет миграции. Порядок: коммиты в `develop` → деплой на preprod-testbot → смоук нужного тира → зелено → тег на прод → смоук на проде. Тег и пуш — outward-facing, но у владельца есть постоянное разрешение: **зелёный смоук на preprod = добро на прод-тег без отдельного вопроса** (`feedback_preprod_ok_means_prod_auto`). Перед тегом обязательно сверить состав диффа — в репозитории бывает параллельная сессия. Версия — сам тег (`v0.51.x`, инкремент патча), отдельного файла-стампа и CHANGELOG'а в репо нет; сообщение релизного коммита — по русской Conventional-конвенции выше. |
| Telemetry | off - anonymized weekly anomaly bundle (codes and numbers only, docs/telemetry.md); on = /vulyk-evolve prints the send command, never sends |
<!-- VULYK:PROFILE:END -->

## Commands

Quiet variants only: their output is resent every turn. A story's `## Verification` must name one.

<!-- VULYK:COMMANDS:START -->
| Purpose | Command |
|---|---|
| Single test file | `vendor/bin/phpunit --no-coverage --no-progress tests/unit/<Path>Test.php` |
| Full test suite | `vendor/bin/phpunit --no-coverage --no-progress` |
| Lint / static analysis | `vendor/bin/phpstan analyse --memory-limit=512M --no-progress` |
| Migrations syntax | `git ls-files 'app/Database/Migrations/*.php' \| xargs -n1 php -l > /dev/null` |
| Build / typecheck | none — PHP ничего не собирает; ближайший эквивалент типчека это phpstan-строка выше |
| View render smoke | `curl -sS -o /dev/null -w '%{http_code}' <route>` |
| Defect library gate | `bash scripts/defects-check.sh [<arg>]` |
| Scope gate, per story | `bash scripts/scope-check.sh <story-file>` |
| Story gate, per spec | `bash scripts/wave-check.sh docs/specs/<slug>` |
| Ship gate, per spec | `bash scripts/ship-check.sh docs/specs/<slug>` |

Почему именно эти формы, а не то, что написано в README:

- `composer test` запускает `phpunit` **с** coverage-конфигом из `phpunit.xml.dist`, который печатает
  текстовый отчёт в `php://stdout`. Эта простыня осядет в транскрипте и будет пересылаться каждый
  следующий ход. `--no-coverage --no-progress` даёт тот же вердикт молча.
- phpstan работает на level 9, смотрит **только** `app` и исключает `app/Database/Migrations`,
  `app/ThirdParty`, `app/Views/{errors,site,admin}`. Поэтому миграции проверяются отдельной строкой
  `php -l` — иначе синтаксическая ошибка в миграции не ловится ничем до самого деплоя.
- Рендер вьюх (`app/Views/**`) PHPUnit не покрывает: проверка — HTTP-запрос по маршруту.
- Игровой рендер (Telegram-сообщения, caption, кнопки, markdown-эскейпинг) не ловится ни одним из
  этих гейтов — для него есть Tier-3 smoke, см. `## Project bindings` ниже.
<!-- VULYK:COMMANDS:END -->

## Compact instructions

Keep: the deliverable, tier and goal; the spec slug and each story's `status:`; decisions with reasons and rejected options;
walls hit; open questions to the owner. Drop file contents, diffs, command output and scout reports: they are on disk.

## Where things live

Specs, stories, study reports: `docs/specs/<slug>/` · defect classes: `docs/defects/` · path rules: `.claude/rules/`.
`memory/memory.md` indexes the map: a hint to verify, written only by `drone-docs` and `librarian`.
Решения и доменные знания у нас живут не там, где у ванили, — см. таблицу в `## Project bindings`.

## Project bindings

Всё ниже — проектное. `/vulyk-update` конституцию не трогает никогда, поэтому привязки живут здесь, а не в правленых
копиях агентов; три правки внутри рамки, которые апгрейд откатывает, перечислены в `docs/vulyk/ADAPTATION.md` §6.

### Где что живёт

| Что | Ваниль | У нас |
|---|---|---|
| Планы, story, study-отчёты | `docs/specs/<slug>/` | так же |
| Архитектурные решения | `docs/adr/` | **`mmorpg-vault/decisions/ADR-NNN-*.md`**; `docs/adr/` — указатель (и ADR самой рамки) |
| Доменные знания | `docs/wiki/` | **`mmorpg-vault/tech-writing/`** (модели · сервисы · handler'ы · task-handler'ы · контроллеры · db) и **`mmorpg-vault/lore/`** (канон); `docs/wiki/` — указатель |
| Карта кода | `memory/map/` | тонкие срезы (назначение, входы, ловушки) со ссылкой в `mmorpg-vault/apps/<подсистема>/index.md`; карта ведёт в vault, а не переписывает его |
| Уроки сессий, леджеры улья | `memory/learnings/`, `memory/stats/` | так же |
| Классы дефектов | `docs/defects/` | так же — см. «Поправка владельца» ниже |
| Уроки «как со мной работать» | — | `C:\Projects\mmorpg-vault\claude-memory\` (личная память Claude, **не** память улья) |
| Что в работе сейчас | — | `mmorpg-vault/wiki/hot.md` — читать до планирования |

Одной строкой: **`memory/`** — рабочая память улья, едет с кодом; **`docs/defects/`** — что владелец отверг в
результате, с проверкой; **`mmorpg-vault/`** — знание о продукте (канон, tech-writing, ADR, daily);
**`claude-memory/`** — как со мной работать. Писать туда, к чему знание относится, а не туда, что ближе.

### Поправка владельца: карточка дефекта и урок в памяти

Закон 6 и глобальное правило «уроки от переделок — в memory проекта» не дублируют друг друга, а делят поправку:

- **Класс дефекта результата** — то, что видно в коде, тексте игроку, рендере, данных (caption >1024, английский
  текст в UI, одна кнопка в ряд, hardcoded баланс) → цитата владельца дословно в карточку `docs/defects/<id>.md`.
  Если код может этот класс увидеть — `check:` + две фикстуры (исходный случай и соседняя форма) в той же работе.
- **Урок о процессе** — как работать, что спрашивать, в каком порядке → `claude-memory/` (`type: feedback`).
- Одна поправка часто даёт оба; тогда память ссылается на карточку, а не пересказывает её.

`paths:` карточек пишутся нашими путями (`app/Services/**`, `app/Views/site/**`, `cmd:php spark …`). Перед показом
работы владельцу — `bash scripts/defects-check.sh` зелёный.

### Queen и код

На **Tier 0–2** Queen строит сама и читает код адресно: файл, названный в story, отчёте разведки или ноте. На
**Tier 3–4** она код не читает и файлы story не правит: читает ноты (`memory/map/`, `mmorpg-vault/apps|tech-writing`)
и отчёты `drone-scout`, всё правит воркер — включая фикс в две строки и находку ревью. Краулинг `app/` «где это
лежит» — на любом тире работа для `drone-scout`.

### Предметные ворота → строки `## Asks`

Совет судит только `## Asks` из `brief.md` и не знает ни одного из правил `CLAUDE.md`. **Ворота, не превращённые в
строку `## Asks`, не проверяет никто.** Формулировать проверяемо: «экран X читается в media-off: caption несёт имя,
эффект и числа», а не «соблюдён media-off».

| Правило проекта | Кто несёт |
|---|---|
| 7 ворот `GAME_RULES_AND_VALIDATION_FRAMEWORK.md` + 🔴/🟠/🟡/🟢 | Queen на `/vulyk-plan`, до утверждения; для 🟠 — ADR |
| Tech-writing нота на каждую тронутую модель/сервис/handler/контроллер | Tier 0–2 — Queen в той же задаче; Tier 3–4 — `drone-docs` после мерджа. Пишется в `mmorpg-vault/tech-writing/` |
| ADR на значимое решение | Queen; на Tier 4 — `lead-architect`. Пишется в `mmorpg-vault/decisions/` |
| WIPE-COVERAGE → `Config\WipeManifest` | тот, кто строит (Queen / `worker-code`); `lead-review` проверяет |
| ADMIN-TUNABLE BALANCE → `GameSettings` | `lead-review`: hardcoded число баланса — BLOCK |
| MEDIA-OFF | строка ask; судит `lead-review` (Tier 1–2), плюс `council-opus` (Tier 3–4) |
| UX-DISCOVERABILITY / ONBOARDING / GUIDE / TIPS | вердикт Queen строкой ask, включая «нет — потому что»; судит `lead-review` (Tier 1–2), `council-opus` + достижимость по `Client path` у `council-haiku` (Tier 3–4) |
| Дизайн-системы сайта и админки | `.claude/rules/web-public.md`, `.claude/rules/web-admin.md` |

Tier 0 бумаги не имеет: вердикты guide/tips пишутся в сообщении в конце работы.

### Verification — три уровня

Строка `## Verification` в story называет команду из `## Commands`, но зелёный Tier 1 здесь не значит «работает»:
PHPUnit не рендерит ни вьюхи, ни Telegram.

- **Tier 1 — код и API:** phpunit / phpstan / `php -l` миграций / `curl` по маршруту.
- **Tier 2 — админка:** MCP Chrome по `/admin/*`, вьюпорты 1440 / 768 / 375, console clean.
- **Tier 3 — живая игра:** MCP Chrome + Telegram Web со второго аккаунта, тест-чар testbot `telegram_user_id=25`,
  или автономный POST на вебхук testbot'а. **Обязателен** для любого видимого UX-изменения (caption, кнопка, фото,
  multistep, edit-in-place, callback). На проде — никогда.

Совет Tier 2/3 не заменяет: `lead-review` судит дифф против asks, `council-opus` — намерение и края, `council-haiku`
идёт по `Client path` без браузера (`curl`, POST на вебхук). Живой проход в Telegram делает Queen руками на
preprod-testbot'е и кладёт результат доказательством в соответствующий ask.

### Обслуживание улья

С 0.21 SessionStart-бриф сам пишет `maintenance due: gc / evolve / map`. Queen выполняет это через Skill **после**
задачи владельца, на `develop` (дефолт рамки у нас — он, см. ниже) с чистым деревом, без вопроса, и одной строкой
говорит, что изменилось. `/vulyk-evolve` строит в своём worktree на `vulyk/evolve-<дата>`: ветка ждёт ревью владельца,
сама ничего не применяет; слитая — принята, удалённая без мерджа — отклонена.

### Handoff — один

`.claude/hooks/handoff.{sh,py}` из поставки удалены намеренно: глобальный `~/.claude/hooks/context_guard.py` делает то
же и пишет в тот же `.claude/handoff/`; `/vulyk-handoff` переписан на него. Апгрейд возвращает оба файла — удалить
снова (`ADAPTATION.md` §1, §6).

### Релиз и цикл на нашем потоке

`/vulyk-ship` и `ship-check.sh` написаны под «ветка → merge в default → publish». Как это уравнено у нас:

| Рамка | У нас |
|---|---|
| default branch | **`develop`.** `master` мёртв и в релиз-путь не входит; `vulyk/<slug>` ответвляется от `develop` и вливается в него. Строку `ship-check` «you are on master» игнорировать — она берёт дефолт из `origin/HEAD` |
| version bump + CHANGELOG | их нет: версия — сам тег `v0.51.x`. Релизный коммит несёт только записи цикла |
| `/vulyk-ship` печатает команду публикации, не жмёт | выполняем её: зелёный preprod-смоук = постоянное добро. Но **состав диффа сверяется до тега** (бывает параллельная сессия), а `--record` получает имя тега |
| грязное дерево блокирует шип | `skills.json`/`anomalies.jsonl` не блокируют (0.21.1), леджеры `memory/stats/*` — бумага (`is_paperwork_path` в `lib.sh`). Остальные хвосты улья коммитятся отдельным коммитом до шипа |
| человек не обязателен | `scripts/human-check.sh` — override вердикта совета в любую сторону; `/vulyk-pause` — вмешаться посреди круга |

Один круг целиком: `/vulyk-plan` (гриль → `## Asks` → стоп на `**Approved:**`; Tier 1 и `--go` — `**Briefed:**`) →
`/vulyk-build` (ветка `vulyk/<slug>`; Tier 1–2 соло, Tier 2 — в свежей сессии; Tier 3–4 — воркеры через Workflow) →
`**Council:** GREEN` → merge в `develop` + push → Actions катит **preprod** → смоук нужного тира → `/vulyk-ship`: тег
`v0.51.x` на `develop` → Actions катит **прод** + сайт → смоук на проде → `ship-check.sh --record`.

### Экономия контекста

Вывод команды остаётся в транскрипте навсегда — только тихие формы из `## Commands`, шумное — субагенту. Пути, а не
описания. `/clear` между задачами (`/vulyk-handoff` сначала, если есть состояние); `/rewind`, а не `/compact`, чтобы
откатить последние ходы. Отладка крутится ~10 ходов без прогресса — стоп, находки в story, перепланировать;
отвергнутые фиксы не предлагать повторно.
