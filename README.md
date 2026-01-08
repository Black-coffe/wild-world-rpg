# Wild World RPG

<div align="center">

![PHP](https://img.shields.io/badge/PHP-8.1+-777BB4?style=for-the-badge&logo=php&logoColor=white)
![CodeIgniter](https://img.shields.io/badge/CodeIgniter-4.4-EF4223?style=for-the-badge&logo=codeigniter&logoColor=white)
![Telegram](https://img.shields.io/badge/Telegram-Bot-26A5E4?style=for-the-badge&logo=telegram&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-8.0-4479A1?style=for-the-badge&logo=mysql&logoColor=white)
![License](https://img.shields.io/badge/License-MIT-green?style=for-the-badge)

**A text-based MMORPG game powered by Telegram Bot API**

[Features](#features) | [Installation](#installation) | [Game Systems](#game-systems) | [Contributing](#contributing)

</div>

---

## About

Wild World RPG is a persistent multiplayer role-playing game that runs entirely within Telegram. Players explore a procedurally generated world, gather resources, craft items, build bases, and engage in PvE/PvP combat - all through an intuitive chat interface.

Built with **CodeIgniter 4** and the **Longman Telegram Bot** library, this project demonstrates how to create complex game mechanics using a messaging platform as the primary interface.

## Features

### Core Gameplay
- **Exploration** - Navigate a cell-based world map with multiple biomes (forests, deserts, mountains, swamps, etc.)
- **Resource Gathering** - Collect materials based on your location and biome modifiers
- **Crafting System** - Multi-tier crafting with Workbench General and Workbench Standard
- **Base Building** - Establish camps with various buildings (Workshop, Arsenal, Laboratory, Greenhouse, and more)
- **PvE Combat** - Battle NPCs with a detailed damage and effects system
- **PvP System** - Player detection and combat mechanics
- **Quests** - Dynamic quest system with multiple objectives

### Technical Features
- Asynchronous task processing for time-based actions
- Real-time world events affecting gameplay
- Faction system with territory control
- Robot automation (Explorer, Gatherer)
- Teleportation network with beacons
- Admin panel for game management

## Game Systems

### World & Biomes
The game world is divided into cells, each belonging to a specific biome:
- **Forest** - Rich in wood and herbs
- **Desert** - Minerals and rare materials
- **Mountains** - Stone and ore deposits
- **Swamp** - Unique flora and fauna
- **Plains** - Balanced resources

### Buildings
Players can construct various structures at their base:

| Building | Function |
|----------|----------|
| Workshop | Basic crafting station |
| Arsenal | Weapon storage and upgrades |
| Laboratory | Research and special items |
| Greenhouse | Food production |
| Solar Station | Energy generation |
| Robotics Workshop | Build and manage robots |
| Teleportation Center | Fast travel network |
| Gym | Character training |
| Warehouse | Resource storage |

### Crafting Tiers
1. **Basic Workbench** - Components, medical supplies, basic tools
2. **Standard Workbench** - Armor, weapons, robots, teleport beacons

## Installation

### Requirements
- PHP 8.1 or higher
- MySQL 8.0 or MariaDB 10.6+
- Composer
- Telegram Bot Token (from [@BotFather](https://t.me/BotFather))

### Quick Start

1. **Clone the repository**
   ```bash
   git clone https://github.com/Black-coffe/wild-world-rpg.git
   cd wild-world-rpg
   ```

2. **Install dependencies**
   ```bash
   composer install
   ```

3. **Configure environment**
   ```bash
   cp .env.example .env
   ```
   Edit `.env` and set your credentials:
   - Database connection
   - Telegram Bot API key
   - SMTP settings (optional)

4. **Run database migrations**
   ```bash
   php spark migrate
   ```

5. **Set up Telegram webhook**
   ```bash
   # Point your bot webhook to:
   # https://your-domain.com/telegram/webhook
   ```

6. **Start the development server**
   ```bash
   php spark serve
   ```

### Configuration

Key environment variables in `.env`:

```env
# Application
CI_ENVIRONMENT = production
app.baseURL = 'https://your-domain.com/'

# Database
database.default.hostname = localhost
database.default.database = your_db_name
database.default.username = your_db_user
database.default.password = your_db_password

# Telegram Bot
telegram.API_KEY = 'your_bot_token'
telegram.BOT_USERNAME = '@your_bot_username'
telegram.HOOK_URL = 'https://your-domain.com/telegram/webhook'
telegram.MY_CHAT_ID = 'your_admin_chat_id'
```

## Project Structure

```
app/
├── Config/              # Application configuration
├── Controllers/
│   ├── Admin/          # Admin panel controllers
│   └── Telegram/       # Bot command handlers
│       └── Commands/
│           └── Actions/ # Game action handlers
├── Database/
│   └── Migrations/     # Database schema
├── Libraries/          # Core game libraries
├── Models/             # Data models
├── Services/           # Business logic
│   ├── PVE/           # Combat system
│   ├── Player/        # Player management
│   └── World/         # Map and world services
├── TaskHandlers/       # Background task processors
│   ├── Built/         # Building completion
│   ├── Craft/         # Crafting completion
│   ├── Events/        # World events
│   └── Quests/        # Quest handlers
└── Views/              # Admin panel templates
```

## Development

### Running Tests
```bash
composer test
# or
vendor/bin/phpunit
```

### Database Commands
```bash
# Run migrations
php spark migrate

# Check migration status
php spark migrate:status

# Rollback
php spark migrate:rollback
```

### Adding New Features

**New Telegram Commands:**
1. Create command class in `app/Controllers/Telegram/Commands/`
2. Extend `BaseShiftingCommand`
3. Register action handlers in `Actions/` directory

**New Crafting Items:**
1. Add migration with item definition
2. Create action handler for crafting UI
3. Add completion handler in `TaskHandlers/Craft/`
4. Place visual assets in `public/uploads/telegram/craft/`

## Screenshots

<details>
<summary>Click to view game screenshots</summary>

The game uses visual assets for an immersive experience:
- Character creation screens
- World map exploration
- Crafting interface
- Building management
- Combat encounters

</details>

## Tech Stack

- **Backend Framework:** CodeIgniter 4.4
- **Bot Library:** [longman/telegram-bot](https://github.com/php-telegram-bot/core)
- **HTTP Client:** Guzzle
- **Database:** MySQL/MariaDB
- **Testing:** PHPUnit

## Contributing

Contributions are welcome! Please read our [Contributing Guidelines](CONTRIBUTING.md) before submitting a pull request.

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## Acknowledgments

- [CodeIgniter](https://codeigniter.com/) - The PHP framework
- [Longman Telegram Bot](https://github.com/php-telegram-bot/core) - Telegram Bot API implementation
- All contributors and testers

---

<div align="center">

**Made with passion in Ukraine**

[Report Bug](https://github.com/Black-coffe/wild-world-rpg/issues) | [Request Feature](https://github.com/Black-coffe/wild-world-rpg/issues)

</div>
