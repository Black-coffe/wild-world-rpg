# CLAUDE.md

Telegram-MMORPG «Wild World» на CodeIgniter 4 + longman/telegram-bot: исследование, добыча, крафт,
PvE, базы, PvP в персистентном мире через чат. Три поверхности в одном репо: бот, сайт
`wildworld.fun`, админка.

**Модель верхней касты: `TOP_MODEL = opus`.** Строка стоит здесь, а не в `CLAUDE.vulyk.md`:
`scripts/top-model.sh` читает первое вхождение пина только в этом файле. Opus 5 держим сознательно
(вне 30-дневного retention Fable, вдвое дешевле). Проверка: `bash scripts/top-model.sh --explain`
→ `decided by: constitution`.

## Опорные документы

- [`GAME_DESCRIPTION.md`](./GAME_DESCRIPTION.md) — канон геймплея; атомарные ноты — `mmorpg-vault/lore/`.
- [`GAME_RULES_AND_VALIDATION_FRAMEWORK.md`](./GAME_RULES_AND_VALIDATION_FRAMEWORK.md) — 4 категории
  правил (🔴/🟠/🟡/🟢) + 7 ворот валидации. **Любая идея/фикс/рефактор проходит ворота** (ADR-017,
  шпаргалка `mmorpg-vault/runbooks/idea-validation.md`); для 🟠 — ADR.
- `README.md`, `CONTRIBUTING.md` (documentation contract, image contract).
- `C:\Projects\mmorpg-vault\` — Obsidian-vault соседом (ADR-009): tech-writing, glossary, ADR, daily,
  `wiki/hot.md`. Читается обычным `Read`.

## Рамка работы: VULYK (ADR-170)

`CLAUDE.vulyk.md` (импорт ниже) отвечает за **КАК** (тиры, касты, гейты, модели, память улья),
этот файл — за **ЧТО** (канон и предметные правила). При конфликте выигрывает этот файл. Привязки
рамки к нашим путям — `CLAUDE.vulyk.md` → `## Project bindings`; отличия от ванили —
`docs/vulyk/ADAPTATION.md`. Перед задачей назови тир (0–4). С VULYK 0.12.0 предметные ворота
отсюда **записываются строками `## Asks` в `brief.md`** — слепой совет знает только бриф.

@CLAUDE.vulyk.md

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

Команды (тихие варианты для тестов, phpstan, миграций) — `CLAUDE.vulyk.md` → `## Commands`.
Полные тексты разделов, сокращённых здесь 2026-09-14 (рамка, смоук, архитектура, гайдлайны), —
`docs/claude/reference.md`; полные тексты правил 1–10 — в указанных `.claude/rules/*.md`.
