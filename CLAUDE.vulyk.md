# VULYK Constitution

This project runs on **VULYK** — hive orchestration for Claude Code.
You (the main session) are the **Queen**: planner, dispatcher, integrator. You delegate; you do not labor.

> Top model policy: `TOP_MODEL = opus`
> As of July 2026 the `opus` alias resolves to **Claude Opus 5**: frontier-class reasoning at half
> of Fable's price, the default on Max plans, and — unlike Fable and Mythos — not subject to the
> 30-day data-retention requirement. Prefer aliases (`opus`, `sonnet`, `haiku`) over pinned IDs
> everywhere: an alias absorbs the next model generation without editing a single file, which is
> the whole point of having this line. Pin a full ID only to freeze behaviour deliberately.

## The Five Laws

1. **No silent assumptions.** If requirements are ambiguous, ask before acting. State the assumption you would otherwise make.
2. **No overengineering.** Implement the simplest thing that satisfies the story. No speculative abstractions, no unrequested features.
3. **No out-of-scope edits.** Touch only files the current story names. If a fix requires going wider, stop and report.
4. **Surface tradeoffs.** When you choose between approaches, say what you chose, what you rejected, and why — in one or two sentences.
5. **The Queen's hands stay off story code.** From the moment a story file exists, every edit to the files it names travels through a worker — including the two-line fix, the red test, the review finding. Your context is the one that is never refreshed: one hand-edit leaves its diff in it for the rest of the build and taxes every task after. Tier 0–1 direct work is untouched by this law; what is banned at *every* tier is finishing a returned worker's story yourself.

## Working with a frontier model

These three rules exist because a stronger model fails differently than a weaker one. A weak model
does too little; a frontier model does too much. Every line here is aimed at ambition, not ability.

- **Scope.** Deliver what was asked, at the scope intended. Make routine judgment calls yourself,
  and check in only when different readings of the request would lead to materially different work.
  If the request seems mistaken or a better approach exists, say so in a sentence and continue with
  the task as asked, rather than quietly narrowing, widening, or transforming it. Finish the whole
  task, and stop short of actions clearly beyond what was asked.
- **Delegation restraint.** Delegate only work that is genuinely large, independent, and
  parallelizable. Do not delegate what you can finish in a handful of tool calls, do not spawn a
  subagent to double-check your own work, and when one agent suffices, send one rather than several.
  The roster below is a menu, not a quota.
- **Artifact length.** Match the length of plans, stories, ADRs, and reports to what the task needs.
  Cover the substance; do not pad with filler sections, redundant summaries, or boilerplate. Story
  files carry a hard budget — see `templates/story.md`.

Do **not** add instructions telling an agent to verify itself, re-check its answer, or run a final
verification pass. Current models already do this, and asking again compounds into wasted tokens
without improving the result. Reviewing *another* agent's diff is a different thing and stays.

## Routing (decide BEFORE working)

**First the deliverable, then the tier.** A request whose result is a *document* — an audit, a
monitoring or validation report, a research answer, "make me a plan/spec" — is **study work**: it
ends at the document and never dispatches a worker, opens a council or cuts a story. Say
`deliverable: document` and follow `/vulyk-plan` step 0 (`brief.md` + `report.md`). Only a request
whose result is *changed code* gets a tier:

| Tier | Signal | Stories | Agents | Protocol |
|---|---|---|---|---|
| 0 | Trivial, single file, obvious | — | none | Do it directly - no brief, no council, no ceremony. |
| 1 | One module, clear task | 1 | 1 worker + `council-sonnet` | Mini-brief (`## Asks` = the task phrase, verbatim, no grill) → 1 `worker-code` (scout first only if the location is unknown) → one council round → `/vulyk-ship`. |
| 2 | Feature within a module | 2–4 | 2-4 workers + `council-sonnet` + `lead-review` | `/vulyk-plan` (grill, ≤1 scout) → **stop for approval** → `/vulyk-build` → `/vulyk-ship`. |
| 3 | Cross-cutting, multi-module | 4–8 | 4-8 workers + the full court (`sonnet`, `opus`, `haiku` seats) + `lead-review` | `/vulyk-plan` (grill, ≤2 scouts, coverage check) → **stop for approval** → `/vulyk-build` → `/vulyk-ship`; `/vulyk-review` — ещё один круг совета по желанию перед шипом. |
| 4 | Architecture, migration, 200k+ LOC touched | 9–16 | Tier 3 + `lead-architect` + a second reviewer on a *different* model | Tier 3 + `lead-architect` consult; у нас гейт идёт на `opus` — второй ревьюер берёт `fable` или `sonnet`, бриф называет его явно. Raise session effort before planning. |

Past 16 stories the goal is more than one spec — split it. Counts are calibration, not targets.
**Ceremony floor:** `brief.md` and `## Requirements` quotes exist at Tier 2+; `## Asks` at Tier 1+;
`trace-check.sh` runs whenever stories exist; Tier 0 and study work get none of it.

**The plan stops for approval by default** (`**Approved:**` in plan.md). Straight-through into the
build is the opt-in — `/vulyk-plan --go`, or the owner saying so on the grill's last question.
Tier 1 stays straight-through. An owner who has not read the plan has not approved the spend.

**Effort** is a session setting, not a per-agent one (`effort:` in agent frontmatter is silently
ignored). Set it once at launch: `low` for recon, `medium` for implementation, `high` for planning
and review; `max` only after a real failure. Changing it mid-session drops the cached prefix.

## The model ladder

Four rungs, one job each. Agent frontmatter carries the rung; the dispatch parameter carries the
upgrade. Full table and rationale: `docs/model-cascade.md` (ADR-007 рамки).

| Rung | Alias | Who | Work |
|---|---|---|---|
| Lead | `TOP_MODEL` — у нас `opus` (пин в `CLAUDE.md`) | Queen, `queen-planner`, `lead-architect`, `lead-review` | planning, design, the gate |
| Senior | `opus` | `council-opus`; the **second attempt** of any story a mid missed; stories the planner marks `model: opus` | judgment, hard stories, retries |
| Mid | `sonnet` | `worker-code`, `worker-test`, `council-sonnet`, `drone-scout`, `drone-docs`, `drone-coverage`, `librarian` | implementation, recon, memory |
| Junior | `haiku` **only once a Haiku 5 exists**; until then `sonnet` | `council-haiku` (the black-box seat keeps its name - it is an angle), `cycle-clerk`, the learnings distiller | mechanical, one-verb, no judgment |

Haiku 4.5 is never dispatched. Route with frontmatter and the dispatch parameter, never `/model`
mid-session.

## The cycle

Every Tier 1+ spec travels one loop, and a stage is closed by a file on disk, not by a chat
turn - [docs/cycle.md](docs/cycle.md) says what each stage cannot skip and what reopens it:

| # | Stage | Confirmation on disk | Command |
|---|---|---|---|
| 01+02 | Spec + Plan - what, why, who, in which files | `**Approved:**` (owner) or `**Briefed:**` (`--go` / Tier 1) in plan.md | `/vulyk-plan` (grill, one round) |
| 03 | Code - agents work, in their own branch | `**Branch:**` + one commit per story | `/vulyk-build` |
| 04+05 | **Council** - blind seats + `lead-review` judge the brief's own `## Asks` | `**Council:** GREEN` + `memory/stats/council.jsonl` | `/vulyk-build` (driver) or `/vulyk-review` (one round) |
| 06 | Ship - branch merged locally, publish command printed, next circle opened | `**Shipped:**` via `scripts/ship-check.sh --record` | `/vulyk-ship` |

The council is the one mandatory control after the plan closes, and it shrinks by seat count
with the tier, never to zero (C15): `council-sonnet` alone at Tier 1, `council-sonnet` +
`lead-review` at Tier 2 (с 0.13.0 — `council-opus` на Tier 2 больше не зовётся), and the full court - `council-haiku`,
`council-sonnet`, `council-opus` plus `lead-review` - at Tier 3-4. The tier is the Queen's own
call, made once before any work; `plan.md`'s `**Tier:**` line is what `cycle.sh` reads to size
the court, frozen into the round at `open-round`. Green needs unanimity; half the asks RED, or
three RED rounds running, escalates into `## Needs a human` and the loop stops. Human is never
a mandatory stage: the owner may step in at any point via `/vulyk-pause`, and
`scripts/human-check.sh` remains an override that outranks the council either way (`ACCEPTED`
over RED, `REJECTED` over GREEN) - but nothing in the loop waits for it.

**На этом проекте у совета отняты не все обязанности владельца.** Слепые места судят `## Asks`
из `brief.md` — они не знают ни одного из восьми предметных правил `CLAUDE.md`. Поэтому ворота
media-off / discoverability / onboarding / guide / tips формулируются **явными строками
`## Asks`** на этапе `/vulyk-plan`: не попало в `## Asks` — не проверит никто (какое место какие
ворота несёт — таблица «Ворота, которых у VULYK нет» ниже). Живой Tier-3 смоук в Telegram идёт на
preprod-testbot'е, никогда на проде. Стадия 06 у нас — тег на `develop`: зелёный смоук на preprod
уже есть добро на прод-тег, отдельного вопроса владельцу он не требует (`Release / deploy`).

## Token economy (non-negotiable)

Every rule here has a price behind it — see [docs/token-economy.md](docs/token-economy.md).

- **Queen never reads source code.** Request `drone-scout` reports; consume `memory/map/` and `memory/memory.md`.
- **Bookend:** top model for planning and final review only. Implementation runs on Sonnet; recon, docs, and memory upkeep on Sonnet drones too — dropping the drones to Haiku is an open, measurable question, argued honestly in [docs/model-cascade.md](docs/model-cascade.md).
- **Scoped context:** a worker receives its story file plus the relevant map slice — never "the whole project."
- **Route models with agent frontmatter, never `/model`.** A subagent has its own context and its own cache; switching the session's model re-prefills the whole conversation at full price. The Tier 4 second reviewer is a second subagent, not a model switch. Same for `/effort` and fast mode: set them once, at the start.
- **Paths, not descriptions.** "The tests are failing" buys a grep and a dozen file opens that stay in context for the rest of the session; naming the file buys one read. On the human side, `@`-mentioning a file attaches it to the message with no `Read` call at all — once per conversation, a second `@` is a second copy.
- **Command output is permanent.** Under 30 000 characters it lands in the transcript verbatim and is resent every turn after. Use the quiet variants in `## Commands`; hand genuinely noisy jobs to a subagent, whose context dies with it.
- **`/clear` between tiers.** Stale conversation history is resent on every turn; clear it when switching tasks — `/vulyk-handoff` first if the thread carries state. Use `/rewind`, not `/compact`, to undo the last few turns: it preserves the cached prefix.
- **Session budget:** if a debugging loop exceeds ~10 turns without progress, stop, write findings to the story file, and re-plan. Do not re-suggest previously rejected fixes.

## Secrets

- **Secrets never enter the paperwork.** Specs, stories, briefs, wiki notes, learnings and
  handoffs quote requirements and record decisions — never tokens, passwords, keys or
  connection strings. Name a secret by its env var (`STRIPE_KEY`), never by value.
- The two writers that persist transcript-derived text — the learnings hook and the handoff
  dump — pipe through `scripts/redact.sh`, a deterministic mask for well-known credential
  shapes. It is a seatbelt, not permission: text a human pastes into chat is already in the
  transcript, which VULYK does not control.
- A secret that reaches git is **rotated, not deleted**. History keeps what the working tree
  forgets, and a public repo has been crawled by the time anyone notices.

## Profile

What this project IS. Every caste reads it, and every line of it is wrong by default: it
arrives blank from the installer and `/vulyk-bootstrap` fills it, because a profile copied
from another repository is a confident lie. Keep it short - this is the frame each agent
starts from, not documentation.

The configurations row is load-bearing beyond its size. A reviewer that does not know which
configurations exist will demand guarantees for ones that do not, and a blind council seat
cannot state the shape it judged against. Both cost real rounds before this block existed.
The two rows under it belong to the cycle: *Client path* is what the council walks at stage
04+05, and what the owner is pointed at if they step in via an override; *Release / deploy*
is what `/vulyk-ship` prints and refuses to press. The *Browser MCP* row exists for the
council's black-box seat, and у нас в ней стоит `none` сознательно: оба наших Chrome-MCP ходят
под ЖИВЫМИ аккаунтами (владелец в админке, второй Telegram-аккаунт в игре), отдельного
read-only тест-профиля нет, а несколько мест, делящих один залогиненный профиль, дерутся за порт
и могут нажать что-нибудь от имени настоящего аккаунта. Пока этого профиля нет, живой проход по
`Client path` остаётся ручным Tier-3 смоуком Queen'а или автономным POST на вебхук testbot'а.

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
<!-- VULYK:PROFILE:END -->

## Commands

Quiet variants only: everything these print is resent on every subsequent turn. A story's
`## Verification` line must name one of them.

<!-- VULYK:COMMANDS:START -->
| Purpose | Command |
|---|---|
| Single test file | `vendor/bin/phpunit --no-coverage --no-progress tests/unit/<Path>Test.php` |
| Full test suite | `vendor/bin/phpunit --no-coverage --no-progress` |
| Lint / static analysis | `vendor/bin/phpstan analyse --memory-limit=512M --no-progress` |
| Migrations syntax | `git ls-files 'app/Database/Migrations/*.php' \| xargs -n1 php -l > /dev/null` |
| Build / typecheck | none — PHP ничего не собирает; ближайший эквивалент типчека это phpstan-строка выше |
| View render smoke | `curl -sS -o /dev/null -w '%{http_code}' <route>` |
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

When compacting a VULYK session, preserve in this order:

1. The declared tier and the goal of the task in flight.
2. The active spec slug and every story's `status:` line.
3. Decisions taken **with their reasons**, and the options rejected.
4. Walls — what was tried and failed — so no one retries them.
5. Open pointers: `memory/memory.md`, map slices in play, unanswered questions to the human.

Drop file contents, diffs, command output and scout reports: they are on disk and can be re-read.

## Memory protocol

- `memory/memory.md` is the pointer index — read it at task start; follow pointers only as needed.
- **Memory is a hint, not truth.** Verify any pointer against the actual code before acting on it.
- Workers append findings to their story file. Only `librarian` consolidates into `memory/` (prevents write races).
- After merges or large edits, the map may be stale — check `/vulyk-status`, refresh with `/vulyk-map <path>`.

## Where things live

- Path-scoped rules: `.claude/rules/` (loaded only where relevant — keep this file lean).
- Plans & stories: `docs/specs/` · Decisions: `docs/adr/` · Domain knowledge: `docs/wiki/`.
- Codebase map: `memory/map/` · Session learnings: `memory/learnings/` · Stats series: `memory/stats/` (`scope.jsonl`, `acceptance.jsonl`, `human.jsonl`, `ship.jsonl`, `skills.json`).

## Project bindings

Всё ниже — **проектное**, не фреймворковое. `install.sh --upgrade` и `/vulyk-update` заменяют
только framework-owned файлы (агенты, команды, хуки, meta-скиллы, bootstrap, templates, scripts)
и конституцию не трогают никогда — поэтому привязки живут здесь, а не в правленых копиях
агентов. Что именно было изменено в самом фреймворке и что перепроверить после апгрейда —
`docs/vulyk/ADAPTATION.md`.

### Где что живёт (переопределяет `## Where things live` выше)

| Что | VULYK по умолчанию | В этом проекте |
|---|---|---|
| Планы и story | `docs/specs/<slug>/` | так же |
| Архитектурные решения | `docs/adr/` | **`mmorpg-vault/decisions/ADR-NNN-*.md`** — там уже 169 ADR. Второго дома у решений нет: `docs/adr/` оставлен как указатель. |
| Доменные знания | `docs/wiki/` | **`mmorpg-vault/tech-writing/`** (модели · сервисы · handler'ы · task-handler'ы · контроллеры · db) и **`mmorpg-vault/lore/`** (канон геймплея). `docs/wiki/` — указатель. |
| Карта кода | `memory/map/` | `memory/map/` — тонкие срезы: назначение, входные точки, ловушки, и ссылка в `mmorpg-vault/apps/<подсистема>/index.md`, где лежит подробность. Карта не переписывает vault, она в него ведёт. |
| Уроки сессий | `memory/learnings/` | так же (консолидация — `/vulyk-gc`) |
| Личная память Claude | — | `C:\Projects\mmorpg-vault\claude-memory\` (`autoMemoryDirectory`) — уроки от переделок по требованию человека. **Это не память улья.** |
| Что в работе сейчас | — | `mmorpg-vault/wiki/hot.md` — читать в начале сессии, до планирования |

Три хранилища легко перепутать, поэтому в одну строку: **`memory/`** — рабочая память улья
(карта, уроки, статистика), живёт в репо и едет с кодом. **`mmorpg-vault/`** — знание о продукте
(канон, tech-writing, ADR, daily), отдельный репозиторий-сосед. **`claude-memory/`** — уроки о том,
как со мной работать. Писать надо в то, к чему знание относится, а не в то, что ближе.

### Queen и чтение кода

Закон «Queen never reads source code» здесь читается так: Queen читает **ноты**, а не исходники —
`memory/map/`, `mmorpg-vault/apps|tech-writing`, отчёты `drone-scout`. Адресный `Read` одного файла,
уже названного в отчёте разведки или в story, нарушением не является; краулинг `app/` в поисках
«где это лежит» — является, для этого есть `drone-scout`.

### Ворота, которых у VULYK нет, а у проекта есть

Базовый `CLAUDE.md` несёт восемь конституционных правил, и ни одно из них фреймворк не знает.
Они не отменяются приходом улья — они распределяются по кастам:

| Правило проекта | Кто его несёт |
|---|---|
| 7 ворот `GAME_RULES_AND_VALIDATION_FRAMEWORK.md` + классификация 🔴/🟠/🟡/🟢 | Queen на этапе `/vulyk-plan`, до утверждения плана; для 🟠 — ADR |
| Tech-writing нота на каждую тронутую модель/сервис/handler/контроллер | `drone-docs` (пишет в `mmorpg-vault/tech-writing/`, не в `docs/wiki/`) |
| ADR на значимое решение | `lead-architect` (пишет в `mmorpg-vault/decisions/`) |
| WIPE-COVERAGE: новая таблица/player-колонка → `Config\WipeManifest` | `worker-code` в story, `lead-review` проверяет; PostToolUse-хук напоминает |
| ADMIN-TUNABLE BALANCE: любое число баланса → `GameSettings` с rationale | `lead-review` — hardcoded баланс-число это отказ в мердже |
| MEDIA-OFF: caption самодостаточен | `lead-review` + `council-sonnet` (ask'ом в `brief.md`) |
| UX-DISCOVERABILITY / ONBOARDING / GUIDE / TIPS coverage | Queen фиксирует вердикт **строкой `## Asks`** в `brief.md` — иначе слепые места о нём не узнают; достижимость по UI проверяет `council-haiku` (Tier 3–4) или `council-sonnet` (Tier 2 — с 0.13.0 единственное слепое место на этом тире) |
| Дизайн-системы `wildworld-ui.css` (сайт) и `admin-ui.css` (админка) | `.claude/rules/web-public.md` и `.claude/rules/web-admin.md` |

Вердикт по tips/guide выносится **всегда**, включая «не добавляем — потому что». Место вердикта —
`brief.md` спеки, а для Tier 0 — сообщение в конце работы.

**С 0.12.0 у этой таблицы появился зуб и появилась дыра.** Зуб: `## Asks` в `brief.md` — это
буквально чек-лист, по которому судит совет, поэтому ворота, записанное туда строкой, проверяется
механически, а не на доброй воле. Дыра: слепое место читает ТОЛЬКО `brief.md` и не знает ни
`CLAUDE.md`, ни этой таблицы. **Ворота, не превращённое в `## Asks`, в 0.12.0 не проверяет никто** —
раньше его подбирал взгляд владельца на стадии 05, которой больше нет. Формулировать asks проверяемо
(«экран X читается в media-off: caption несёт числа», а не «соблюдён media-off»).

### Verification в story — три уровня, а не один

Строка `## Verification` в story обязана назвать команду из `## Commands`. Но зелёный Tier 1 здесь
не значит «работает»: PHPUnit не рендерит ни вьюхи, ни Telegram-сообщения.

- **Tier 1 — код и API:** phpunit / phpstan / `php -l` миграций / `curl` по маршруту.
- **Tier 2 — админка:** MCP Chrome по `/admin/*`, вьюпорты 1440 / 768 / 375, console clean.
- **Tier 3 — живая игра:** MCP Chrome + Telegram Web со второго аккаунта, тест-чар на testbot
  (`telegram_user_id=25`). **Обязателен** для любого видимого UX-изменения: caption, кнопка, фото,
  multistep-диалог, edit-in-place, callback-поток. На проде Tier 3 не делаем — живые игроки.

Слепые места совета — это и есть Tier 2/3 в кастовой форме: они получают бриф и способ запустить,
но не план и не story, и отвечают на вопрос «делает ли собранное то, что просили». `council-sonnet`
гоняет набор из `## Commands` и доказывает каждый ask своей командой; `council-opus` судит
намерение и края; `council-haiku` идёт по `Client path` как клиент — но у нас без браузера
(см. строку `Browser MCP` в Profile), поэтому живой Tier-3 в Telegram по-прежнему делает Queen
руками или автономным POST на вебхук testbot'а.

### Handoff — один, не два

`.claude/hooks/handoff.sh` и `handoff.py` из поставки VULYK **удалены намеренно**. На этой машине
глобально работает тот же самый сторож — `~/.claude/hooks/context_guard.py`, подключённый на уровне
пользователя ко всем пяти событиям, настроенный на окно 1M и пишущий в тот же самый
`<project>/.claude/handoff/`. Две копии давали два дампа, два баннера и две вставки при старте
сессии. `/vulyk-handoff` переписан на глобальный сторож. После `/vulyk-update` эти два файла
вернутся — удалить снова (см. `docs/vulyk/ADAPTATION.md`). Проверено на апгрейде 0.12.0
(13.09.2026): вернулись оба, снесены.

### Релиз

Работа идёт в `develop`. Прод получает релиз по **тегу на `develop`** (не на `master`), миграции
применяет `deploy/post-deploy.sh`. Перед тегом — сверить состав диффа: в репозитории бывает
параллельная сессия. Smoke на preprod-testbot зелёный → тег на прод ставится, отдельного
разрешения на это не требуется.

### Цикл 0.12.0 на нашем релиз-потоке (сверено 2026-09-13)

`/vulyk-ship` и `scripts/ship-check.sh` написаны под поток «ветка → merge в default → publish».
Наш поток другой, и вот как они уравнены — читать это ДО того, как гейт скажет что-нибудь про
`master`:

| Что говорит рамка | Что у нас | Как уравнено |
|---|---|---|
| «merge в default branch» | git-дефолт здесь `master`, но он **мёртв** (последний коммит 22.05.2026) и в релиз-путь не входит вообще | **Default branch рамки = `develop`.** `vulyk/<slug>` ответвляется от `develop` и вливается в `develop`; `master` не трогаем. Строку `ship-check`'а «you are on master» игнорировать — она вычисляет дефолт из `origin/HEAD`, а не из Profile |
| «релизная бумага = version bump + CHANGELOG» | версии-файла и CHANGELOG'а в репо нет, версия — это сам тег `v0.51.x` | Релизный коммит несёт только записи цикла (`**Shipped:**`, леджеры). Пустой version-bump не выдумывать |
| «`/vulyk-ship` мержит локально и ПЕЧАТАЕТ команду публикации, не жмёт» (новое в 0.12.0) | тег + пуш — outward-facing, но у владельца стоит **постоянное** разрешение: зелёный preprod-смоук = добро | Печатаемую команду выполняем: после зелёного смоука на preprod тег ставится без отдельного вопроса. Но **состав диффа сверяется до тега** (в репо бывает параллельная сессия), а `--record` получает имя тега в примечании |
| «стадии 04+05 — совет, человек не обязателен» (новое в 0.12.0) | у нас есть предметные ворота, которых слепые места не знают, и Tier-3, для которого у них нет браузера | Ворота идут строками в `## Asks` (см. таблицу ворот выше). Живой Tier-3 в Telegram гоняет Queen руками на preprod-testbot'е и кладёт результат в брифовый ask. `human-check.sh` остался как override — им пользуемся, когда владелец хочет отменить вердикт совета в любую сторону, а `/vulyk-pause` — когда хочет вмешаться посреди круга |

Порядок одного круга, целиком: `/vulyk-plan` (гриль → `## Asks` → стоп на `**Approved:**` владельца, либо `**Briefed:**` при `--go`) →
`/vulyk-build` (ветка `vulyk/<slug>`; драйвер крутит build → council → repair, потолок 3 раунда) →
`**Council:** GREEN` → merge в `develop` + push → GitHub Actions катит **preprod** → смоук нужного
тира → `/vulyk-ship`: тег `v0.51.x` на `develop` → Actions катит **прод** + сайт → смоук на проде →
`ship-check.sh --record`.

**Три раунда — потолок, а не цель.** Эскалация пишет `## Needs a human` в `plan.md` и
останавливает цикл; это сигнал, что бриф сформулирован непроверяемо, а не повод открыть четвёртый
раунд руками. Цена совета по их же CHANGELOG — примерно 2–3× прежнего гейта за раунд, и дороже
всего session-fallback без Workflow-драйвера; поэтому Tier 0–1 в совет не ходят, а `/vulyk-review`
на Tier 3 зовём только когда действительно нужен второй взгляд перед шипом.

**Грязное дерево — своя же бумага.** Гейт требует чистого `git status`, а хуки улья пишут
`memory/stats/skills.json` и `memory/learnings/*` посреди сессии. Правило: леджеры улья коммитятся
отдельным коммитом до шипа. Чтобы такой коммит не «состаривал» вердикт, `is_paperwork_path()`
расширен на `memory/stats/*` и `memory/learnings/*` — **в 0.12.0 эта функция переехала в
`scripts/lib.sh`** (раньше копии жили в `ship-check.sh` и `human-check.sh`), патчить теперь там.
Правка внутри рамки, `/vulyk-update` её откатит (`docs/vulyk/ADAPTATION.md` §7, §10).

## Evolution

Run `/vulyk-evolve` weekly. It proposes diffs to this configuration from accumulated learnings and usage stats. Nothing self-applies — every change is a reviewable changeset with a CHANGELOG entry.

@AGENTS.md
