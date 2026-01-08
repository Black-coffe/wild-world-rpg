# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a Telegram-based MMORPG game built with CodeIgniter 4 and the Longman Telegram Bot library. The game features exploration, resource gathering, crafting, PvE combat, base building, and PvP mechanics within a persistent world managed through Telegram chat interactions.

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