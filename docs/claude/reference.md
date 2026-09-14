# CLAUDE.md — справочная часть

Разделы без привязки к путям, перенесённые дословно из `CLAUDE.md` 2026-09-14. Короткая версия каждого — в самом `CLAUDE.md`; path-scoped правила — в `.claude/rules/`.

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

## 🐝 РАМКА РАБОТЫ: VULYK (зафиксировано 2026-08-19, ADR-170)

Процесс работы над этим репозиторием ведёт **VULYK** — рамка оркестрации для Claude Code
(«вулик» = улей). Её конституция импортируется ниже и действует наравне с этим файлом.

**Разделение обязанностей между двумя конституциями — без наложений:**

| Кто отвечает | За что |
|---|---|
| **`CLAUDE.md`** (этот файл) | **ЧТО** мы строим и по каким правилам игры: канон, 7 ворот валидации, admin-tunable balance, media-off, UX-discoverability, onboarding/guide/tips-coverage, wipe-coverage, дизайн-системы, tech-writing contract, smoke-tiers. |
| **`CLAUDE.vulyk.md`** (импорт) | **КАК** мы это делаем: тиры сложности, касты агентов, story/plan/scope-гейты, экономия контекста, каскад моделей, память улья. Плюс раздел `## Project bindings`, где рамка привязана к нашим путям и нашим воротам. |

**При конфликте выигрывает предметное правило этого файла.** Пример: VULYK говорит «решения →
`docs/adr/`», у нас решения живут в `mmorpg-vault/decisions/` — и остаются там. Все такие привязки
перечислены в `CLAUDE.vulyk.md` → `## Project bindings`; чем именно правленая установка отличается
от ванильной поставки и что перепроверять после `/vulyk-update` — в `docs/vulyk/ADAPTATION.md`.

**Модель верхней касты: `TOP_MODEL = opus`.** Строка стоит именно здесь, а не в `CLAUDE.vulyk.md`
(где лежит её обоснование), по технической причине: резолвер `scripts/top-model.sh` из VULYK 0.10.0
объявляет, что читает обе конституции, но его `constitution_pin()` делает `return` внутри цикла и
до второго файла не доходит никогда. Пин в `CLAUDE.vulyk.md` он молча игнорирует и уходит решать
по тарифу — на Max это `fable`. Мы держим Opus 5 сознательно: он вне 30-дневного retention'а,
который тянет за собой Fable, и вдвое дешевле. `CLAUDE.md` рамка не перезаписывает никогда, так что
эта строка переживает любой `/vulyk-update`. Проверка — `bash scripts/top-model.sh --explain`
должен сказать `decided by: constitution`.

**Что это меняет в повседневной работе:** прежде чем начать задачу — назови её тир (0–4). Tier 0
делается напрямую, без церемонии. С Tier 1 у задачи появляется `## Asks`, с Tier 2 — `brief.md`,
story-файлы и scope-гейт; Tier 2+ идут конвейером `/vulyk-plan` (гриль → asks) → `/vulyk-build`
(драйвер крутит build → совет → ремонт). Ворота из этого файла при этом никуда не деваются, но с
VULYK 0.12.0 **их надо записывать строками `## Asks` в `brief.md`**: судит собранное совет слепых
агент-мест, который читает только бриф и не знает ни одного правила отсюда. Не попало в asks — не
проверит никто. Распределение ворот по кастам — таблица в `CLAUDE.vulyk.md` → `## Project bindings`.

`@CLAUDE.vulyk.md` <!-- импорт живёт в самом CLAUDE.md; здесь выключен бэктиками -->

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
