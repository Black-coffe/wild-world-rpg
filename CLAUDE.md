# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a Telegram-based MMORPG game built with CodeIgniter 4 and the Longman Telegram Bot library. The game features exploration, resource gathering, crafting, PvE combat, base building, and PvP mechanics within a persistent world managed through Telegram chat interactions.

**Master documents (north star):**
- [`GAME_DESCRIPTION.md`](./GAME_DESCRIPTION.md) — слитный канон геймплея. **Атомарные ноты** канона по подсистемам — в [`mmorpg-vault/lore/`](file:///C:/Projects/mmorpg-vault/lore/index.md).
- [`GAME_RULES_AND_VALIDATION_FRAMEWORK.md`](./GAME_RULES_AND_VALIDATION_FRAMEWORK.md) — **процессный north-star**: 4 категории правил (🔴 строго запрещено / 🟠 запрещено с исключениями / 🟡 разрешено, но подумать / 🟢 всегда нужно) + фреймворк валидации из 7 ворот, через который **обязана** пройти любая идея/фикс/рефактор/расширение игры. Провалидирован против 10 портретов ЦА. Архитектурное обоснование — `mmorpg-vault/decisions/ADR-017-Idea-validation-framework.md`.
- [`README.md`](./README.md) — точка входа для GitHub-аудитории, архитектурный обзор.
- [`CONTRIBUTING.md`](./CONTRIBUTING.md) — гайдлайны для контрибьюторов + documentation contract.
- [`mmorpg-vault/`](file:///C:/Projects/mmorpg-vault/README.md) — Obsidian vault соседом репо (см. ADR-009 в самом vault'е). Tech-writing wiki, glossary, daily journal, hot-context для Claude. Архитектурное обоснование самого vault'а живёт в `mmorpg-vault/decisions/ADR-009-Vault-without-MCP.md`.

---

## ⚡ ПРОАКТИВНІСТЬ (зафіксовано 2026-05-06)

**При background-очікуванні (CI deploy, smoke test, polling):** НЕ висіти у idle. Або:
1. Запускати паралельну роботу (інший рефакторинг, vault updates, prep наступного batch).
2. Запускати **кілька речей одночасно** (multi-tool calls в одному message).

**При завершенні роботи:** формат рапорту — "Я закінчив X, Y, Z. Результат: W. Бажано далі: A, B, або C — рекомендую X." Якщо у поточному напрямку нічого більше — самостійно дивитись на open tails (hot.md, untackled handlers, perf opportunities) і пропонувати логічний наступний крок.

**Multi-tool calls** — стандартна практика коли є 3+ незалежних read/edit. Не виняток.

---

## 🌐 SMOKE TIERS — 3 рівня тестування (зафіксовано 2026-05-19)

При тестуванні фічі / багфіксу вибирай **відповідний tier** (можна комбінувати):

### Tier 1 — Code & API (automated)

- `composer test` (PHPUnit) — unit + database tests, baseline gate
- `vendor/bin/phpstan` (L9) — static analysis
- `curl <route>` — HTTP smoke для view changes (memory `feedback_view_rendering_smoke`)
- SSH `php spark <command>` на testbot — для ad-hoc CLI smoke
- SQL UPDATE на testbot — для DB state manipulation (memory `feedback_testbot_db_manipulation_allowed`)

### Tier 2 — Admin UI (semi-automated через MCP Chrome під admin-account'ом)

- `mcp__chrome-devtools__*` для `/admin/*` форм / dashboards / settings panel'ів
- Авторизація через Andrei admin login (раз авторизувався — сесія тримається)
- Покриває: admin CRUD, form rendering, validation, dropdown panels (handler_options)
- Доказ: F5 Phase D forms unification (12/12 endpoints через Chrome MCP), S5a/S5b admin UI smoke

### Tier 3 — Real Game (manual через MCP Chrome + Telegram Web з 2-го аккаунта)

**Обов'язково для:** видимих UX-changes (caption / button label / photo / multistep dialog /
edit-in-place / callback flow / forceReply / typing delay).

**Workflow:**
1. **Запитати user'а** дозвіл: «Запустити MCP Chrome + Telegram Web на 2-му аккаунті?»
2. Відкрити `mcp__chrome-devtools__new_page` на `https://web.telegram.org/k/`.
3. Попросити user'а **підтвердити вхід на телефоні** (Telegram code prompt прийде на основний аккаунт).
4. **Тримати сесію активною** всю роботу — не закривати, не робити `close_page`.
5. **Між smoke-ітераціями**: `select_page` → `take_snapshot` → `click` / `type_text` / `wait_for` → `take_screenshot`.
6. Тест-чар на testbot: `telegram_user_id=25` (`aviad_echo`); перевірити поточний id через
   `SELECT id, name, level FROM characters WHERE telegram_user_id=25`.
7. **На проді — НЕ робити real-game smoke** (живі гравці). Тільки якщо user явно дозволив.

**Чому Tier 3 потрібен попри Tier 1-2:** unit + admin Chrome НЕ ловлять:
- runtime caption/button label mismatches у реальному Telegram render
- edit-in-place vs sendNew регресії (memory `feedback_view_rendering_smoke` ← але це HTTP, не Telegram)
- callback button flow correctness
- photo render edge cases (caption > 1024, broken img_path, disable_media flag)
- markdown escaping у Telegram (S5b/Sell-#6 bug history доказ)
- forceReply multistep UX

Деталі та коли пропускати — memory `feedback_mcp_chrome_telegram_real_game_smoke`.

---

## 🗂️ ОБЯЗАТЕЛЬНОЕ ЧТЕНИЕ В НАЧАЛЕ КАЖДОЙ СЕССИИ

**Перед началом любой задачи Claude обязан:**

**Vault лежит соседом репо в `C:\Projects\mmorpg-vault\`** — это обычные markdown-файлы, читаются через стандартный `Read` (никаких MCP / плагинов / специальных инструментов). См. [[mmorpg-vault/decisions/ADR-009-Vault-without-MCP]].

1. **Прочитать [`mmorpg-vault/wiki/hot.md`](file:///C:/Projects/mmorpg-vault/wiki/hot.md)** — что в работе СЕЙЧАС, какие активные блоки, какие открытые вопросы.
2. **Прочитать соответствующий [`mmorpg-vault/apps/<подсистема>/index.md`](file:///C:/Projects/mmorpg-vault/apps/index.md)** если задача касается конкретного домена — там список моделей, сервисов, handler'ов, контроллеров с обратными ссылками.
3. **При работе с термином из канона** — заглядывать в [`mmorpg-vault/glossary/<термин>.md`](file:///C:/Projects/mmorpg-vault/glossary/index.md) для точного определения.
4. **При архитектурном решении** — найти существующий ADR в [`mmorpg-vault/decisions/`](file:///C:/Projects/mmorpg-vault/decisions/index.md), не повторять обсуждение.

**МЕТАЦЕЛЬ:** не вкачивать весь контекст в системный промпт. Selective reading через адресные `Read` к нужным нотам — главная экономика подхода.

---

## 📚 КОНСТИТУЦИОННОЕ ПРАВИЛО TECH-WRITING (зафиксировано 2026-05-04, ADR-009)

**ЛЮБОЕ изменение в коде, затрагивающее модель / сервис / Telegram action-handler / task-handler / контроллер — ОБЯЗАНО сопровождаться синхронным обновлением соответствующей ноты в `mmorpg-vault/tech-writing/`.**

### Где какая нота

| Категория | Путь | Шаблон |
|---|---|---|
| CI4-модели | `tech-writing/models/<ModelName>.md` | `_templates/model-doc.md` |
| Сервисы | `tech-writing/services/<ServiceName>.md` | `_templates/service-doc.md` |
| Telegram action-handler'ы | `tech-writing/handlers/<group>/<HandlerName>.md` | `_templates/handler-doc.md` |
| Task-handler'ы | `tech-writing/tasks/<group>/<HandlerName>.md` | `_templates/handler-doc.md` |
| Web/Admin контроллеры | `tech-writing/controllers/<ControllerName>.md` | `_templates/service-doc.md` |
| Миграции (карта таблиц) | `tech-writing/db/<table>.md` | (структура аналогична model-doc) |

### Что включает обязательное обновление

**При создании нового кода:**
- Создать ноту по соответствующему шаблону.
- Заполнить frontmatter: `type`, `kind`, `class`, `file`, `last_reviewed: <today>`, `source: human` (или `mixed` если генерил Claude), `verified: true`.
- Проставить ссылки на смежные ноты (`apps/<подсистема>/index.md` уже знает про эту сущность? — обновить и там).
- Указать связанные ADR (если новое решение — создать ADR в `decisions/`).
- В блок «Где используется» — добавить актуальные callers.

**При изменении кода:**
- API изменился (новый метод / параметр / поле модели) → обновить раздел «Публичный API» или «Поля».
- Триггер task-handler'а сменился → обновить раздел «Триггер».
- Добавили новый action_name в `ActionLogModel` → обновить «Audit-коды».
- Зависимости изменились → обновить «Зависимости».
- Обновить `last_reviewed: <today>` в frontmatter.

**При удалении кода:**
- Не удалять ноту, а пометить frontmatter `status: deprecated` + причину.
- В шапке — ссылка на замещающую ноту (если есть).

### Зачем это правило

🔍 **Навигируемость на масштабе.** На сегодня — 49 моделей, ~30 сервисов, ~80 action-handler'ов, ~70 task-handler'ов. Без wiki будет невозможно найти «где это лежит, что делает, кто вызывает».

🧠 **Selective reading для Claude.** Claude адресным `Read` тянет нужную ноту (`apps/pve/index.md`, `tech-writing/services/BattleService.md`) вместо вкачивания целой подсистемы в контекст.

🔗 **Граф связей.** Wiki-links между моделями/сервисами/handler'ами строят граф. Obsidian Graph View показывает кластеры. Видно сирот.

⏰ **Anti-drift.** Без правила доки разойдутся с кодом за 3-4 месяца. Правило заставляет обновлять синхронно.

### Что считается «завершённой задачей»

- ✅ Идея прошла фреймворк валидации (`GAME_RULES_AND_VALIDATION_FRAMEWORK.md` — 7 ворот, классифицирована в 🔴/🟠/🟡/🟢; для 🟠 — ADR создан)
- ✅ Код написан
- ✅ PHPUnit-тесты зелёные (`composer test`)
- ✅ Миграция применена и протестирована (если меняли схему)
- ✅ Документация в репо обновлена (`CLAUDE.md`, `GAME_DESCRIPTION.md` если задели лор)
- ✅ **Tech-writing нота обновлена в vault'е**
- ✅ **Если значимое решение — ADR создан в `mmorpg-vault/decisions/`**
- ✅ **`mmorpg-vault/wiki/hot.md` обновлён** (если контекст сменился)
- ✅ **Если новый контент требует картинки** (крафт / здание / событие / оружие / NPC / фракция) — LEXICON-запись + строка в `Config\ImageRegistry` + сгенерённая картинка (`php spark images:generate`), стиль = «Найденная фотоплёнка» (ADR-022, `mmorpg-vault/reference/Image-Style-Bible.md`, runbook `image-generation.md`). См. CONTRIBUTING §Image contract.
- ✅ Коммит с осмысленным русским сообщением

### Workflow в конце сессии Claude

Перед `/clear` или завершением работы — Claude обязан:

1. **Обновить `mmorpg-vault/daily/<сегодня>.md`** с разделами «Сделано», «Решения», «Открытые вопросы», «Завтра».
2. **Обновить `mmorpg-vault/wiki/hot.md`** под актуальный фокус (если изменился).
3. **Создать/обновить tech-writing ноту** для каждой затронутой сущности.
4. **Создать ADR** если приняли архитектурное решение.

---

## 🎛️ КОНСТИТУЦИОННОЕ ПРАВИЛО ADMIN-TUNABLE BALANCE (зафиксировано 2026-05-17, ADR-024 будущий)

**Любой параметр, влияющий на сеттинг / баланс / ребаланс игры — ОБЯЗАТЕЛЬНО выносится в админку через `GameSettings` live-tunable framework.** Не магическое число в коде, не константа в `Config\GameBalance`, не hardcoded ENV.

Полный текст и аргументация — `memory/feedback_admin_tunable_balance.md` (всегда загружено).

### Что обязано быть у каждого admin-tunable параметра

| Атрибут | Назначение |
|---|---|
| `setting_key` | `category.subcategory.name` (snake_case_dot_notation) |
| `value_*` | Текущее значение (типизированная колонка) |
| `default_value_text` | Default который выставил **Claude** при создании ключа — для Reset button |
| `category` | Логическая группа (craft / buildings / resources / combat / world / endgame / experimental) |
| `rationale_text` | **Почему сейчас стоит именно это значение** (1–3 предложения) |
| `effect_text` | **На что влияет** (механики, формулы, какие игроки заметят) |
| `above_effect_text` | **Что произойдёт, если выше** (конкретный сценарий: «при 0.70 ремонт почти бесплатный, П8 теряют sink, инфляция») |
| `below_effect_text` | **Что произойдёт, если ниже** (конкретный сценарий) |
| `recommended_min/max` | Soft-границы (admin UI окрасит warning жёлтым) |
| `hard_min/max` | Жёсткие пределы (сохранение запрещено вне их) |
| `updated_at/by` | Audit trail |

**Без `rationale_text` / `above_effect_text` / `below_effect_text` — запись не должна сохраняться.** Это invariant.

### Когда вынос обязателен

Любой параметр меняющий:
- 💰 **цену/стоимость/количество** ресурсов или валюты
- 🎲 **вероятности** (RNG, drop rates, spawn chances, crit chances)
- ⏱ **время** (durations, cooldowns, intervals)
- ⚔ **формулы боя** (damage reduction, multipliers, caps)
- 📊 **лимиты/пороги** (max inventory, slot counts, level gates)
- 🚩 **флаги фич** (seasonal enabled, etc.)
- 📈 **прогрессию** (XP needed, levels-per-tier)

→ Регистрируй в `GameSettings` с полным rationale, **не оставляй в коде**.

### Когда НЕ нужно

- Архитектурные timeouts / retry counts / buffer sizes (не игровая механика) → `Config\*` ok.
- Идентификаторы и enum'ы (`status='queued'`) → data shape, не баланс.
- Тексты UI / локализация → у них своя инфра (`tips`, descriptions).
- Cron schedules → инфраструктура.

### Admin sidebar — структурированный, НЕ хаотичный

`/admin/game-settings/<category>` — отдельный экран на категорию. Sidebar дерево:

```
⚙️ Параметры баланса
├── 🔧 Крафт и ремонт          (craft)
├── 🏗 Стройка и постройки     (buildings)
├── 💎 Ресурсы и редкость       (resources)
├── ⚔ Бой и PvP                 (combat)
├── 🌐 Мир и события            (world)
├── 🎯 Эндгейм                   (endgame)
└── 🧪 Экспериментальные        (experimental — A/B флаги)
```

### Reset-to-default — обязательно

Каждая настройка в admin UI имеет кнопку **«Сбросить к default»**. Default-ы выбираю Я (Claude) при создании ключа — на основе game-design анализа, 10-портретного чека, принципа «safe baseline». Корректировка default → новая seed-migration.

### Anti-patterns (не делай)

- ❌ `const REPAIR_COST = 0.5` в коде (магическое число)
- ❌ Tunable значение в `Config\GameBalance` но не в `GameSettings` (двойная правда)
- ❌ GameSettings ключ без `rationale_text` / `above` / `below`
- ❌ Admin UI без Reset-to-default
- ❌ Плоский список ключей без категоризации в sidebar
- ❌ SQL напрямую в `game_settings` минуя admin UI (обходит audit-trail)

### Foundation

`GameSettings` framework — выход **S5 ROADMAP-CRAFT v1** (`ADR-024`; роадмап архивирован в `mmorpg-vault/reference/archive-roadmaps/ROADMAP-CRAFT-v1.md`). До его реализации новые tunable параметры можно временно держать в `Config\GameBalance` с TODO-комментарием «→ GameSettings после S5», но **с момента ship'а S5 — миграция обязательна**.

### Чек-лист «закрытой задачи» дополнен (новый пункт)

При завершении любой сессии — **проверь**, не появились ли в твоём коде hardcoded balance-числа. Если да — зарегистрируй в `GameSettings` с полной rationale-документацией. Без этого задача **не считается закрытой**.

---

## 🖼️ КОНСТИТУЦИОННОЕ ПРАВИЛО MEDIA-OFF: КОНТЕНТ ПОЛНОЦЕНЕН В ТЕКСТЕ (зафиксировано 2026-05-20)

**Игрок может полностью отключить картинки** в экране «⚙️ Настройки» (`SettingsAction`, Идея #14): per-character флаг `characters.disable_media` (0/1, дефолт 0). Экран прямо обещает игроку: *«Содержание и кнопки сообщений не изменятся, только пропадут изображения»*.

**Механизм:** ВСЕ photo-отправки в app-коде идут через `App\Services\Notifications\MediaSender` (`sendPhotoOrText` / `editOrSend` / `editTextOrSend`). При `disable_media=1` MediaSender шлёт/редактирует **только текст, подставляя `caption` как `text`** (фото опускается). Карта мира — текстовая всегда (исключение). Полная аргументация — `memory/feedback_media_disable_toggle_text_complete.md` (всегда загружено).

### Инвариант (учитывать ВСЕГДА, во ВСЁМ контенте)

🔴 **Любое сообщение с фото ОБЯЗАНО иметь самодостаточный `caption`**, несущий весь смысл: название, эффект, числа (HP / выносливость / стоимость / ресурсы), состояние, инструкции. **Картинка — только enhancement поверх текста, НЕ носитель смысла.**

| Делай ✅ | Не делай ❌ |
|---|---|
| Полный `caption` с именем/эффектом/числами/состоянием | Пустой или куцый caption, смысл которого «в картинке» |
| Photo-отправки через `MediaSender` | `Request::sendPhoto(` напрямую в app-коде |
| Лор/событие/превью/результат читается целиком текстом | «как на изображении» / смысл только в визуале |
| Картинки (image-waves) как отдельный enhancement-слой; text-fallback не ломает | Контент, который без картинки становится неполноценным |

### Чек-лист «закрытой задачи» дополнен (новый пункт)

При любом контенте, у которого есть/будет картинка (крафт / событие / здание / NPC / квест / оружие / диалог / экран) — **проверь, что он полностью понятен и функционален в media-off режиме**. Если смысл теряется без изображения — задача **не считается закрытой**.

---

## Development Commands

### Testing
- Run PHPUnit tests: `composer test` or `vendor/bin/phpunit`
- Test configuration: `phpunit.xml.dist`

### Database Management
- Run migrations: `php spark migrate`
- Check migration status: `php spark migrate:status`
- Rollback migrations: `php spark migrate:rollback`

### Development Server
- Start CodeIgniter development server: `php spark serve`
- Default URL: `http://localhost:8080`

### Dependencies
- Install dependencies: `composer install`
- Update dependencies: `composer update`

## Architecture Overview

### Core Game Systems

**Telegram Bot Integration**
- Main bot controller: `app/Controllers/Telegram/BotController.php`
- Command handlers in `app/Controllers/Telegram/Commands/`
- Action handlers in `app/Controllers/Telegram/Commands/Actions/`
- Uses longman/telegram-bot library for Telegram API integration

**Game World & Map System**
- World managed through cell-based coordinate system
- Biomes define environmental characteristics and resource availability
- Map services handle world generation and exploration logic
- Character movement and location tracking in `app/Models/MapModel.php`

**Task Management System**
- Background task execution for game actions (exploration, gathering, crafting, building)
- Task handlers in `app/TaskHandlers/` directory
- Character tasks tracked in `app/Models/CharacterTaskModel.php`
- Asynchronous processing for time-based game mechanics

**PvE Battle System**
- Combat engine in `app/Services/PVE/BattleService.php`
- Damage calculation: `app/Services/PVE/DamageService.php`
- Battle effects: `app/Services/PVE/EffectService.php`
- Equipment handling: `app/Services/PVE/EquipmentService.php`

**Resource & Crafting System**
- Resource gathering with biome-specific modifiers
- Multi-tier crafting system (Workbench General → Workbench Standard → Professional Workbench T3)
- Crafted items and recipes managed through dedicated models
- Resource banking and trading mechanics

**Base Building System**
- Camp creation and building construction
- Building types: Workshop, Arsenal, Laboratory, Greenhouse, etc.
- Building upgrade paths and resource requirements
- Teleportation system with beacons

### Key Directory Structure

**Controllers**
- `app/Controllers/` - Web controllers for admin panel and API
- `app/Controllers/Telegram/` - Telegram bot command handling
- `app/Controllers/Admin/` - Game administration interface

**Models**
- Character system: `CharacterModel`, `CharacterResourceModel`, `CharacterTaskModel`
- World: `MapModel`, `BiomeModel`, `ExploredCellsModel`
- Game mechanics: `CraftedItemsModel`, `QuestModel`, `EventModel`

**Services**
- Business logic layer in `app/Services/`
- Player services: Character management, crafting, combat
- World services: Map generation, object discovery
- PvE combat system with detailed battle mechanics

**Task Handlers**
- Background processing in `app/TaskHandlers/`
- Handles timed actions: exploration, gathering, crafting, building
- Event system for world events and effects

### Database Schema

Key tables managed through migrations in `app/Database/Migrations/`:
- `characters` - Player character data and stats
- `map` - World cell data and coordinates
- `biomes` - Environmental zones with resource modifiers
- `character_tasks` - Active background tasks
- `crafted_items` - Item definitions and recipes
- `character_buildings` - Base building data
- `quests` and `quest_steps` - Quest system

### Configuration

**Environment Setup**
- Copy `.env.example` to `.env` and configure database and Telegram bot credentials
- Required: `telegram.API_KEY` and `telegram.BOT_USERNAME`
- Database configuration for MySQL/MariaDB

**Telegram Bot Setup**
- Bot registration and webhook configuration required
- Commands auto-registered from `app/Controllers/Telegram/Commands/`
- Image assets in `public/uploads/telegram/` for game visuals

## Development Guidelines

### ⚠️ Перед добавлением чего-либо в игру — фреймворк валидации

**Любая идея / фикс / рефактор / расширение / доработка ОБЯЗАНА пройти через [`GAME_RULES_AND_VALIDATION_FRAMEWORK.md`](./GAME_RULES_AND_VALIDATION_FRAMEWORK.md)** — 7 ворот (Формулировка → Канон&сеттинг → 10-персон чек → Баланс&системы → Техно-чек → Smoke-план → Релиз&vault) и классификация в одну из 4 категорий (🔴/🟠/🟡/🟢). Карточка идеи (шаблон — §8 того файла) заполняется до начала работы. Это не бюрократия для мелких фиксов (они проскакивают ворота за минуты), но **пропускать ворота нельзя**. Решение зафиксировано в `mmorpg-vault/decisions/ADR-017-Idea-validation-framework.md`; операционная шпаргалка — `mmorpg-vault/runbooks/idea-validation.md`.

### Adding New Game Features

**New Commands**
- Create command class in `app/Controllers/Telegram/Commands/`
- Extend `BaseShiftingCommand` for action-based commands
- Register action handlers in `app/Controllers/Telegram/Commands/Actions/`

**New Game Mechanics**
- Add service classes in appropriate `app/Services/` subdirectory
- Create models for data persistence
- Add task handlers for background processing if needed
- Create database migrations for schema changes

**Adding Crafting Items**
- Define in migrations with rarity, requirements, and effects
- Add crafting action handlers for UI flow
- Create completion handlers in `app/TaskHandlers/Craft/`
- Add visual assets to `public/uploads/telegram/craft/`

### Testing Strategy
- Unit tests in `tests/unit/` for core game logic
- Database tests for model interactions
- Session tests for character state management
- PHPUnit configuration supports database testing

### Game Balance Considerations
- Resource spawn rates configured in biome settings
- Combat balance through damage service calculations
- Task completion times affect game pacing
- Event frequency and effects impact player experience

This project implements a complex game system through Telegram chat interface, requiring careful coordination between real-time messaging, background task processing, and persistent world state management.