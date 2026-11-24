# CLAUDE.md

Telegram-MMORPG «Wild World» на CodeIgniter 4 + longman/telegram-bot: исследование, добыча, крафт,
PvE, базы, PvP в персистентном мире через чат. Три поверхности в одном репо: бот, сайт
`wildworld.fun`, админка.

## Опорные документы

- [`GAME_DESCRIPTION.md`](./GAME_DESCRIPTION.md) — канон геймплея; атомарные ноты — `mmorpg-vault/lore/`.
- [`GAME_RULES_AND_VALIDATION_FRAMEWORK.md`](./GAME_RULES_AND_VALIDATION_FRAMEWORK.md) — 4 категории
  правил (🔴/🟠/🟡/🟢) + 7 ворот валидации. **Любая идея/фикс/рефактор проходит ворота** (ADR-017,
  шпаргалка `mmorpg-vault/runbooks/idea-validation.md`); для 🟠 — ADR.
- `README.md`, `CONTRIBUTING.md` (documentation contract, image contract).
- `C:\Projects\mmorpg-vault\` — Obsidian-vault соседом (ADR-009): tech-writing, glossary, ADR, daily,
  `wiki/hot.md`. Читается обычным `Read`.

## Рамка работы: VULYK thin (ADR-170)

VULYK (блок `## VULYK` в конце файла, команда `/vulyk:go`) отвечает за **КАК**: бриф словами владельца, план,
одно одобрение, сборка, ревью моделью другого семейства, локальный мердж. Этот файл — за **ЧТО** (канон и
предметные правила); при конфликте выигрывает этот файл. Предметные ворота отсюда **записываются строками
`## Asks` в `brief.md`** — ревьюер знает только бриф и код (раздел «Предметные ворота → строки `## Asks`»).

## Начало сессии

1. `mmorpg-vault/wiki/hot.md` — что в работе сейчас.
2. `mmorpg-vault/apps/<подсистема>/index.md` — если задача в конкретном домене.
3. Термины канона — `mmorpg-vault/glossary/`; архитектурные решения — `mmorpg-vault/decisions/`
   (не повторять обсуждение).

Selective reading: адресный `Read` нужной ноты вместо вкачивания подсистемы.

## Проактивность

При ожидании (деплой, смоук, polling) — не idle: параллельная работа, несколько независимых
вызовов в одном сообщении. Рапорт в конце: «закончил X, Y; результат W; дальше A/B/C — рекомендую
A». Нет задач в направлении — сам смотри хвосты (`hot.md`, open questions).

## Смоук: три уровня

| Tier | Что | Когда |
|---|---|---|
| 1 — код и API | phpunit / phpstan L9 / `php -l` миграций / `curl <route>` / `php spark` и SQL на testbot | всегда |
| 2 — админка | MCP Chrome по `/admin/*`, вьюпорты 1440/768/375, console clean | правка admin-view |
| 3 — живая игра | MCP Chrome + Telegram Web со 2-го аккаунта, тест-чар testbot `telegram_user_id=25` (или автономный POST на вебхук) | **любое видимое UX-изменение**: caption, кнопка, фото, multistep, edit-in-place, callback |

Tier 3 — только на preprod-testbot, **на проде никогда** (живые игроки). Спросить разрешения на
запуск браузера, сессию не закрывать. PHPUnit и Tier 2 не ловят рендер Telegram, markdown-эскейп,
caption >1024, edit-vs-send. Детали: memory `feedback_mcp_chrome_telegram_real_game_smoke`,
`reference_autonomous_webhook_tier3_smoke`.

## Конституционные правила

Здесь — инвариант и чек. Полный текст, таблицы «делай/не делай», anti-patterns и инциденты — в
path-scoped правилах `.claude/rules/*.md` (грузятся, когда работа касается их путей) и в
указанных ADR/памяти.

1. **TECH-WRITING (ADR-009).** Любая правка модели / сервиса / action-handler'а / task-handler'а /
   контроллера → синхронная нота в `mmorpg-vault/tech-writing/` (создать по шаблону, обновить API,
   триггер, audit-коды, `last_reviewed`; удалённое — `status: deprecated`, не удалять).
   → `.claude/rules/tech-writing.md`.
2. **ADMIN-TUNABLE BALANCE (ADR-024).** Любое число баланса (цены, вероятности, время, формулы,
   лимиты, флаги фич, прогрессия) — в `GameSettings` с `rationale` / `effect` / `above` / `below`,
   soft- и hard-границами и Reset-to-default. Не магическое число, не `Config\GameBalance`.
   Инфраструктурные таймауты, enum'ы, тексты, cron — не баланс. → `.claude/rules/balance.md`,
   memory `feedback_admin_tunable_balance`.
3. **MEDIA-OFF (ADR-020).** Любое сообщение с фото несёт самодостаточный `caption` (имя, эффект,
   числа, состояние, инструкции); картинка — только enhancement. Фото — только через
   `App\Services\Notifications\MediaSender`, не `Request::sendPhoto(`. → `.claude/rules/telegram-ux.md`.
4. **UX-DISCOVERABILITY.** Вход в любую player-фичу виден на доступном экране всегда; условная
   фича — lock-кнопка «🔒 Название (нужно: …)» с объяснением и путём к prerequisite. Путь в
   tip/анонсе/квесте сверен с кодом. Скрытая фича — только с ADR (easter-egg). → `.claude/rules/player-facing.md`.
5. **ONBOARDING-COVERAGE (ADR-103).** Навигация не теряется (reply-меню пере-аттачится,
   `setMyCommands` под контролем версий, fallback «не понял» подсказывает возврат). Каждая
   механика — just-in-time подсказка + место в обучающем потоке (one-shot, opt-out, killswitch).
   → `.claude/rules/player-facing.md`.
6. **GUIDE-COVERAGE (ADR-127).** Каждая player-механика получает вердикт Редколлегии «в `/guide`:
   да/нет + раздел»; «да» — раздел в `GuideCatalog` в той же задаче (read-only, media-off,
   markdown-safe, ключ `[a-z]`, без чисел баланса). → `.claude/rules/player-facing.md`.
7. **TIPS-COVERAGE (ADR-134).** **Любое** изменение (new / fix / refactor) — вердикт «нужен совет:
   да/нет + категория»; «да» — идемпотентная seed-миграция `*Seed<Что>Tip.php` (ключ `title_en`,
   одна из 14 категорий ENUM, тон Роби, без чисел баланса, не дубль). → `.claude/rules/player-facing.md`.
8. **PUBLIC-WEB FLAT (ADR-062).** Сайт (`app/Views/site/*`, `wildworld-*.css/js`) — только
   `wildworld-ui.css`: 0 радиусов, 0 теней, палитра-токены, Oswald/Manrope/JetBrains Mono; новый
   компонент сначала в `public/ui-kit.html`. → `.claude/rules/web-public.md`.
9. **ADMIN-UI «QUIET PREMIUM» (ADR-128).** Админка — только `admin-ui.css` (`.aui-*`), один
   янтарный акцент, Fraunces/Hanken Grotesk/JetBrains Mono; новый компонент сначала в
   `public/admin-redesign-preview.html`. → `.claude/rules/web-admin.md`.
10. **WIPE-COVERAGE (ADR-087).** Новая таблица или player-колонка → классификация в
    `Config\WipeManifest` (KEEP / PLAYER_DATA / TRANSIENT / CHARACTER_RESET / IDENTITY_RESET /
    SEED_RESET). Без неё падает `WipeManifestCoverageTest` и блокирует деплой. → `.claude/rules/db-schema.md`.

Вердикты по guide и tips выносятся **всегда**, включая «не добавляем — потому что…». Исключения
для 4–7: admin-only, dev/debug за killswitch=false, easter-egg с ADR; чисто бэкенд без новой
player-поверхности — вердикт обычно «нет», но фиксируется.

## Когда задача закрыта

- ✅ Идея прошла 7 ворот, классифицирована; для 🟠 — ADR.
- ✅ Код + `composer test` зелёный; миграция применена и проверена; `php -l` миграций.
- ✅ Tech-writing нота(ы) обновлены; значимое решение — ADR в `mmorpg-vault/decisions/`; `hot.md`
  обновлён, если сменился фокус.
- ✅ Нет hardcoded чисел баланса (всё в `GameSettings`).
- ✅ Контент с картинкой полон в media-off.
- ✅ Player-фича: вход виден, lock-state есть, tip/анонс сверен, Tier-3 cold-smoke на чистом
  тест-чаре без предзнаний; онбординг-шаг добавлен; вердикты guide и tips зафиксированы.
- ✅ Новая таблица/колонка — в `WipeManifest`.
- ✅ Сайт/админка: только токены своей дизайн-системы, компонент в UI-kit, Tier-2 на 3 вьюпортах.
- ✅ Новый контент требует картинки (крафт/здание/событие/оружие/NPC/фракция) — LEXICON +
  `Config\ImageRegistry` + `php spark images:generate`, стиль «Найденная фотоплёнка» (ADR-022).
- ✅ Коммит с осмысленным русским сообщением.

**Конец сессии:** `mmorpg-vault/daily/<сегодня>.md` (сделано / решения / вопросы / завтра),
`hot.md`, tech-writing ноты, ADR.

## Архитектура — куда смотреть

- Бот: `app/Controllers/Telegram/BotController.php`; команды `Telegram/Commands/`
  (`BaseShiftingCommand`), action-handler'ы `Telegram/Commands/Actions/`.
- Сервисы `app/Services/<домен>/`; модели `app/Models/`; фон — cron → `Controllers/Worker.php` →
  `app/TaskHandlers/`; миграции `app/Database/Migrations/`.
- Мир — клеточная карта + биомы; PvE — `app/Services/PVE/`; крафт — 2 верстака / 3 уровня (ADR-130).
- Карта подсистем с обратными ссылками — `mmorpg-vault/apps/index.md`.
- Окружение: `.env` из `.env.example` (`telegram.API_KEY`, `telegram.BOT_USERNAME`, MySQL).

Команды (тихие варианты для тестов, phpstan, миграций) — раздел `## Команды` ниже.
Полные тексты разделов, сокращённых здесь 2026-09-14 (рамка, смоук, архитектура, гайдлайны), —
`docs/claude/reference.md`; полные тексты правил 1–10 — в указанных `.claude/rules/*.md`.

## Профиль проекта

Перенесено из `CLAUDE.vulyk.md` (старый VULYK) при переходе на VULYK thin.

| Поле | Значение |
|---|---|
| Stack | PHP 8.3 · CodeIgniter 4 · MySQL/MariaDB · longman/telegram-bot. Один репозиторий несёт три поверхности: Telegram-бота, публичный сайт `wildworld.fun` и админку. |
| Package manager / runner | Composer; CLI-задачи через `php spark <command>`. Локально — Laragon (Apache + MySQL). |
| Where source lives | `app/` — Models (~80), Services (~247 файлов в 40 доменных папках), Controllers (~346, из них `Telegram/Commands/Actions/` ~54 и `Admin/` 22), TaskHandlers (74), Database/Migrations (524). Тесты — `tests/{unit,database,session}`. Публичные ассеты и точка входа — `public/`. |
| Test framework | PHPUnit 11.5, конфиг `phpunit.xml.dist`, bootstrap CI4. Часть тестов ходит в **отдельную** MySQL-базу `wildworld_tests` на 127.0.0.1 (root/пусто). Если MySQL не поднят — они падают на `Unable to connect to the database`, и это состояние машины, а не регресс. |
| Commit convention | Conventional Commits с русским текстом сообщения: `feat(дрон): заряжается и в поле`, `fix(раны): источник ран не видел биом`. Ветка работы — `develop`, `master` — мёртв и в релиз-путь не входит. |
| Configurations that exist today | Три живых окружения. **Локальное**: Laragon, MySQL должен быть запущен. **Preprod-testbot**: SSH-доступ (`~/.ssh/wildworld_deploy`), разрешены любые `UPDATE` и ad-hoc `php spark`. **Прод `wildworld.fun`**: живые игроки, деструктивные смоки запрещены, INFO не логируется — мониторинг через `action_log`. Деплой — GitHub Actions: тег на `develop` → rsync релиза → `deploy/post-deploy.sh` применяет миграции. Один узел приложения и одна БД; очереди/воркер-пула **нет** — фоновая обработка идёт через cron → `Controllers/Worker.php` → `app/TaskHandlers/`. Контейнеров, staging-кластера и blue-green нет и не планируется. |
| Client path | Три двери, и почти всегда нужна первая. **Игрок** — Telegram-бот `@wildworldrpg_bot` (прод) и его близнец на preprod-testbot; живой проход — MCP Chrome + Telegram Web со **второго** аккаунта, тест-чар на testbot `telegram_user_id=25`. Автономная альтернатива, когда браузер не нужен: POST игрового апдейта прямо на вебхук testbot'а с секрет-заголовком (`reference_autonomous_webhook_tier3_smoke`). **Публичный сайт** — https://wildworld.fun, тихая проверка маршрута: `curl -sS -o /dev/null -w '%{http_code}' <route>`. **Админка** — `/admin/*` через MCP Chrome под аккаунтом владельца (пароль — `writable/secrets/`, в переписку не попадает). На проде живой проход по игроцкой двери не делаем — там живые игроки. |
| Browser MCP | Субагентам — нет. Оба доступных MCP-браузера ходят под живыми аккаунтами: `chrome-devtools` — под владельцем в `/admin/*`, Telegram Web — под вторым личным аккаунтом; отдельного read-only тест-профиля нет. Агенты проходят `Client path` без логина (публичный сайт `curl`, автономный POST на вебхук testbot'а), а живой проход в Telegram — ручной Tier-3 смоук главной сессии на preprod-testbot'е. |
| Release / deploy | Работа идёт в `develop`; версия публикуется **тегом на `develop`** (`v0.51.x`, инкремент патча; отдельного файла-стампа и CHANGELOG'а нет), GitHub Actions гонит rsync релиза и `deploy/post-deploy.sh` применяет миграции. Порядок: коммиты в `develop` → push → деплой на preprod-testbot → `bash scripts/preprod-parity.sh` зелёный (preprod играет ту же игру, что прод: `docs/defects/preprod-settings-drift.md`) → смоук нужного тира → тег на прод → смоук на проде. **Перед тегом сверить состав диффа** — в репозитории бывает параллельная сессия. Push в `develop` (он запускает деплой на preprod) — только по слову владельца на этот шаг. Постоянное разрешение владельца: **зелёный смоук на preprod = добро на прод-тег без отдельного вопроса**, после сверки состава диффа (`feedback_preprod_ok_means_prod_auto`). |

## Команды

Только тихие варианты: их вывод пересылается каждый ход.

| Назначение | Команда |
|---|---|
| Один тест-файл | `vendor/bin/phpunit --no-coverage --no-progress tests/<Path>Test.php` |
| Полный набор unit1 | `vendor/bin/phpunit --no-coverage --no-progress tests/unit/Se*` |
| Полный набор unit2 | `vendor/bin/phpunit --no-coverage --no-progress tests/unit/[!S]* tests/unit/S[!e]*` |
| Полный набор db1 | `vendor/bin/phpunit --no-coverage --no-progress tests/database/[AC]*Test.php` |
| Полный набор db2 | `vendor/bin/phpunit --no-coverage --no-progress tests/database/[BE]*Test.php` |
| Полный набор db3 | `vendor/bin/phpunit --no-coverage --no-progress tests/database/[DF]*Test.php` |
| Полный набор db4 | `vendor/bin/phpunit --no-coverage --no-progress tests/database/[G-O]*Test.php tests/database/*/` |
| Полный набор db5 | `vendor/bin/phpunit --no-coverage --no-progress tests/database/P[!v-z]*Test.php tests/database/U*Test.php` |
| Полный набор db8 | `vendor/bin/phpunit --no-coverage --no-progress tests/database/P[v-z]*Test.php` |
| Полный набор db6 | `vendor/bin/phpunit --no-coverage --no-progress tests/database/[Q-S]*Test.php` |
| Полный набор db7 | `vendor/bin/phpunit --no-coverage --no-progress tests/database/[TV-Z]*Test.php tests/session` |
| Статический анализ | `vendor/bin/phpstan analyse --memory-limit=512M --no-progress` |
| Синтаксис миграций | `git ls-files 'app/Database/Migrations/*.php' \| xargs -n1 php -l > /dev/null` |
| Сборка / типчек | нет — PHP ничего не собирает; ближайший эквивалент типчека это phpstan-строка выше |
| Рендер вьюх | `curl -sS -o /dev/null -w '%{http_code}' <route>` |
| Паритет preprod, до preprod-смоука | `bash scripts/preprod-parity.sh` |

Полный набор разбит на 10 частей (каждая укладывается примерно в 300 с): `tests/unit` на `Se*` (Services) и
остальное, `tests/database` по первой букве файла, вместе A–Z без пересечений, подпапки `tests/database/*/` —
в db4. Новая папка прямо в `tests/` (не `unit`/`database`/`session`) в эти части **сама не попадёт** — добавить
её строкой. Сверка покрытия: сумма `--list-tests` по десяти строкам = `vendor/bin/phpunit --list-tests` без
`exploit-poc` (2026-11-10: 1943 + 1595 + 1557 + 1). Полный набор не запускать параллельно с другим полным
набором: он пересоздаёт тест-БД.

Почему именно эти формы, а не то, что написано в README:

- `composer test` запускает `phpunit` **с** coverage-конфигом из `phpunit.xml.dist`, который печатает
  текстовый отчёт в `php://stdout`. Эта простыня осядет в транскрипте и будет пересылаться каждый
  следующий ход. `--no-coverage --no-progress` даёт тот же вердикт молча.
- phpstan работает на level 9, смотрит **только** `app` и исключает `app/Database/Migrations`,
  `app/ThirdParty`, `app/Views/{errors,site,admin}`. Поэтому миграции проверяются отдельной строкой
  `php -l` — иначе синтаксическая ошибка в миграции не ловится ничем до самого деплоя.
- Рендер вьюх (`app/Views/**`) PHPUnit не покрывает: проверка — HTTP-запрос по маршруту.
- Игровой рендер (Telegram-сообщения, caption, кнопки, markdown-эскейпинг) не ловится ни одним из
  этих гейтов — для него Tier-3 смоук (раздел «Смоук: три уровня»).

## Где что живёт

| Что | Где |
|---|---|
| Спеки VULYK (бриф, план, ревью) | `docs/specs/<slug>/` на ветке `vulyk/<slug>` до мерджа |
| Архитектурные решения | **`mmorpg-vault/decisions/ADR-NNN-*.md`** (+ строка в `decisions/index.md`); `docs/adr/` — указатель |
| Доменные знания | **`mmorpg-vault/tech-writing/`** (модели · сервисы · handler'ы · task-handler'ы · контроллеры · db) и **`mmorpg-vault/lore/`** (канон); `docs/wiki/` — указатель |
| Карта кода | `mmorpg-vault/apps/<подсистема>/index.md` |
| Классы дефектов | правила — в `.claude/rules/` (`player-text`, `game-integrity`, `web-links-assets`, `cron-and-notifications`, `db-schema`, `lessons`), перенесены 2026-11-24; `docs/defects/` — архив карточек с дословными цитатами владельца. Проверки `scripts/defects-*-check.php` идут в наборе PHPUnit (`tests/unit/Defects/`, часть unit2; живой прогон `task-result-foreign` — `tests/database/DefectTaskResultForeignCheckTest.php`, db3) и работают ручной командой |
| Карта кода старого улья | `memory/map/<домен>.md`, раздел `## Gotchas` — указатель в `.claude/rules/code-map.md` |
| Уроки по коду и процессу | `.claude/rules/lessons.md` или `.claude/rules/<тема>.md` с `paths:` (блок `## VULYK`) |
| Уроки «как со мной работать» | `C:\Projects\mmorpg-vault\claude-memory\` (личная память Claude) |
| Что в работе сейчас | `mmorpg-vault/wiki/hot.md` — читать до планирования |
| `memory/` | архив старого VULYK (карта, уроки, леджеры), только для чтения |

**Поправка владельца** делится надвое. Класс дефекта результата — то, что видно в коде, тексте игроку,
рендере, данных (caption >1024, английский текст в UI, одна кнопка в ряд, hardcoded баланс) → правило с
дословной цитатой владельца в `.claude/rules/` (а проверку, которую может сделать код, — тестом проекта).
Урок о процессе — как работать, что спрашивать, в каком порядке → `claude-memory/` (`type: feedback`). Одна
поправка часто даёт оба; тогда память ссылается на правило, а не пересказывает его.

## Предметные ворота → строки `## Asks`

Ревьюер VULYK видит только бриф и код и не знает правил этого файла. **Ворота, не превращённые в строку
`## Asks` брифа, не проверяет никто.** Формулировать проверяемо: «экран X читается в media-off: caption несёт
имя, эффект и числа», а не «соблюдён media-off».

| Правило проекта | Как попадает в работу |
|---|---|
| 7 ворот `GAME_RULES_AND_VALIDATION_FRAMEWORK.md` + 🔴/🟠/🟡/🟢 | на плане, до одобрения; для 🟠 — ADR |
| Tech-writing нота на каждую тронутую модель/сервис/handler/контроллер | story того, кто строит, в той же спеке; пишется в `mmorpg-vault/tech-writing/` |
| ADR на значимое решение | главная сессия на плане; пишется в `mmorpg-vault/decisions/` |
| WIPE-COVERAGE → `Config\WipeManifest` | ask + story того, кто строит |
| ADMIN-TUNABLE BALANCE → `GameSettings` | ask: hardcoded число баланса — BLOCK на ревью |
| MEDIA-OFF | ask на каждый экран с фото |
| UX-DISCOVERABILITY / ONBOARDING / GUIDE / TIPS | вердикт строкой ask, включая «нет — потому что» |
| Дизайн-системы сайта и админки | `.claude/rules/web-public.md`, `.claude/rules/web-admin.md` |

Маленькая очевидная правка без брифа: вердикты guide/tips пишутся в сообщении в конце работы.

## Секреты и контекст

- Секрет называем именем переменной окружения (`STRIPE_KEY`), никогда значением. Попавший в git секрет
  ротируется, а не удаляется.
- Вывод команды остаётся в транскрипте навсегда — только тихие формы из `## Команды`, шумное — субагенту.
  Отладка крутится ~10 ходов без прогресса — стоп, находки в спеку, перепланировать; отвергнутые фиксы не
  предлагать повторно.

<!-- vulyk:begin -->
## VULYK
- Work goes through `/vulyk:go`: brief with the owner's words, plan, one approval, build, review by
  another model family, local merge. Small obvious fixes skip it.
- Specs: `docs/specs` (each spec in `<folder>/<slug>/`; `brief.md` keeps the owner's words verbatim and is
  the contract). A project that keeps specs elsewhere writes its folder here.
- Publishing (push, tag, release, deploy) only on the owner's explicit word, each step named.
- Never discard uncommitted work: no `git reset --hard`, `git checkout -- .`, `git clean -f` or
  `git stash drop` on changes that are not committed elsewhere. Ask.
- When the owner corrects your work and says why, record the lesson as a rule: a line in
  `.claude/rules/lessons.md` (at most 60 lines; merge or drop old lines to stay under), or a
  `.claude/rules/<topic>.md` with `paths:` when it applies to certain files. No new hooks or scripts for it:
  a check the project wants goes into its own tests.
<!-- vulyk:end -->
