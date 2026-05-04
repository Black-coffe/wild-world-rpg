# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a Telegram-based MMORPG game built with CodeIgniter 4 and the Longman Telegram Bot library. The game features exploration, resource gathering, crafting, PvE combat, base building, and PvP mechanics within a persistent world managed through Telegram chat interactions.

**Master documents (north star):**
- [`GAME_DESCRIPTION.md`](./GAME_DESCRIPTION.md) — слитный канон геймплея. **Атомарные ноты** канона по подсистемам — в [`mmorpg-vault/lore/`](file:///C:/Projects/mmorpg-vault/lore/index.md).
- [`OBSIDIAN-MIGRATION-PLAN.md`](./OBSIDIAN-MIGRATION-PLAN.md) — обоснование структуры vault'а и фильтрация практик.
- [`mmorpg-vault/`](file:///C:/Projects/mmorpg-vault/README.md) — Obsidian vault соседом репо (см. ADR-009 в самом vault'е). Tech-writing wiki, glossary, daily journal, hot-context для Claude.

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

- ✅ Код написан
- ✅ PHPUnit-тесты зелёные (`composer test`)
- ✅ Миграция применена и протестирована (если меняли схему)
- ✅ Документация в репо обновлена (`CLAUDE.md`, `GAME_DESCRIPTION.md` если задели лор)
- ✅ **Tech-writing нота обновлена в vault'е**
- ✅ **Если значимое решение — ADR создан в `mmorpg-vault/decisions/`**
- ✅ **`mmorpg-vault/wiki/hot.md` обновлён** (если контекст сменился)
- ✅ Коммит с осмысленным русским сообщением

### Workflow в конце сессии Claude

Перед `/clear` или завершением работы — Claude обязан:

1. **Обновить `mmorpg-vault/daily/<сегодня>.md`** с разделами «Сделано», «Решения», «Открытые вопросы», «Завтра».
2. **Обновить `mmorpg-vault/wiki/hot.md`** под актуальный фокус (если изменился).
3. **Создать/обновить tech-writing ноту** для каждой затронутой сущности.
4. **Создать ADR** если приняли архитектурное решение.

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
- Multi-tier crafting system (Workbench General → Workbench Standard)
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
- Copy `env` to `.env` and configure database and Telegram bot credentials
- Required: `telegram.API_KEY` and `telegram.BOT_USERNAME`
- Database configuration for MySQL/MariaDB

**Telegram Bot Setup**
- Bot registration and webhook configuration required
- Commands auto-registered from `app/Controllers/Telegram/Commands/`
- Image assets in `public/uploads/telegram/` for game visuals

## Development Guidelines

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