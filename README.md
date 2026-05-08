# Wild World RPG

<div align="center">

![PHP](https://img.shields.io/badge/PHP-8.2+-777BB4?style=for-the-badge&logo=php&logoColor=white)
![CodeIgniter](https://img.shields.io/badge/CodeIgniter-4.7-EF4223?style=for-the-badge&logo=codeigniter&logoColor=white)
![Telegram](https://img.shields.io/badge/Telegram-Bot-26A5E4?style=for-the-badge&logo=telegram&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-8.0-4479A1?style=for-the-badge&logo=mysql&logoColor=white)
![PHPStan](https://img.shields.io/badge/PHPStan-Level%209-8A2BE2?style=for-the-badge)
![License](https://img.shields.io/badge/License-MIT-green?style=for-the-badge)

**A persistent text-based MMORPG powered by Telegram**

[Features](#features) | [Installation](#installation) | [Game Systems](#game-systems) | [Architecture](#architecture) | [Contributing](#contributing)

</div>

---

## About

**Wild World RPG** is a persistent multiplayer role-playing game that runs entirely within Telegram. Players survive on a procedurally generated post-apocalyptic island, gather resources, craft items, build bases, fight NPCs and other players, choose a faction at level 10, and ultimately push their faction toward one of four endgame scenarios.

Built with **CodeIgniter 4** and the **Longman Telegram Bot** library. Real-time chat interactions are layered over a cron-driven background-task engine that runs the world while players are offline.

> **Living canon:** the full gameplay specification — biomes, formulas, factions, endgame scoring — lives in [`GAME_DESCRIPTION.md`](./GAME_DESCRIPTION.md). Architecture, ADRs, per-class wiki, and daily journal live in the sibling Obsidian vault at `C:\Projects\mmorpg-vault\` (see [`CLAUDE.md`](./CLAUDE.md) §Vault).

## Features

### Core gameplay
- **Cell-based world** — 1000×1000 procedurally generated grid with multiple biomes
- **Exploration** — choose duration (10 min – 12 h), risk vs reward, Chebyshev-metric player detection
- **Resource gathering** — biome-specific resources, tool durability, ±20% RNG
- **Two-tier crafting** — Workbench General (medical, tools, components) → Workbench Standard (armor, weapons, robots, beacons)
- **Base building** — 13 building types with daily-tax economy
- **PvE combat** — automatic NPC encounters with damage formulas, biome modifiers, equipment
- **PvP combat** — 4-component damage formula (75% gear / 10% level / 10% stats / 5% initiative), lucky-strike, one-shot, up to 150 rounds
- **Death and insurance** — gold-based one-shot insurance vs. catastrophic loss
- **Robots** — explorers and gatherers, durability-tracked, communication-tower-bound
- **Factions** — choose 1 of 4 at level 10 (Military / Partisans / Engineers / Farmers)
- **Endgame scenarios** — first faction to 75 000 points triggers their scenario (Domination / Anarchy / Scientific Breakthrough / Evacuation)
- **World events** — ~20 weather/geological/biological events with biome-conditional triggers
- **Quests** — multi-step objectives with intermediate (50%) and completion (100%) rewards
- **Strategic objects** — Bunker / Technopark / Ghost City — discovery awards faction points
- **Teleportation network** — beacon-based fast travel
- **Trade and shop** — wandering-merchant economy with 10-rarity-tier pricing
- **Daily nutrition** — automated food/water consumption, hunger penalties
- **Polls and surveys** — admin-driven in-chat voting

### Technical features
- Cron-driven background task engine (`Worker.php` + `TaskHandlers/`)
- Strict types throughout (`declare(strict_types=1)`)
- PHPStan **Level 9** with managed baseline
- CI4 Entity layer for hot-path models
- `Repositories/` for atomic DB operations under hot-path mutations
- `BaseConfig` pattern for runtime tuning (`GameBalance`, `EndgameScoring`, `Buildings`, `CraftRecipes`)
- Audit logging (`ActionLogModel`, `BattleLogModel`, `CraftedItemsLogModel`)
- Unit + integration test suites with MySQL test DB
- GitHub Actions CI/CD with rsync deploy

## Game systems

### World and biomes
The 1000×1000 cell map is split into biomes:

| Biome | Notes |
|-------|-------|
| Forest | Wood, herbs, plants |
| Desert | Minerals, rare metals |
| Mountains | Stone, ore deposits |
| Swamp | Unique flora |
| Plains | Balanced resources |
| River | Water, fish |
| Ruins | Abandoned-truck / closed-warehouse loot |
| Volcano | High-tier minerals, danger penalty |
| Coast | Salt, special resources |

Each biome carries a `danger_level` that influences combat damage, gathering yield, and event probabilities.

### Buildings (camp constructibles)

| Building | Function | Daily tax |
|----------|----------|-----------|
| Workshop | Basic crafting | 500💰 |
| Arsenal | Advanced weapons | 2000💰 |
| Laboratory | Medical recipes | 860💰 |
| Greenhouse | Food production | 840💰 |
| Warehouse | Resource capacity | 900💰 |
| Hand pump | Water production | 300💰 |
| Solar station | Energy generation | 760💰 |
| Gym | Stat training | 900💰 |
| Communication tower | Remote-base + robot range | 1300💰 |
| Blast furnace | Metal smelting | 450💰 |
| Teleportation center | Fast-travel hub | 820💰 |
| Robotics workshop | Robot construction | 1400💰 |
| Beacon | Remote teleport target | (per-unit) |

Daily-tax cron runs at 03:00 EEST. Two missed payments → demolition.

### Crafting tiers
1. **Workbench General** (no base required) — medkits, tools (pickaxes, axes, shovels, fishing rods), metal fragments, fabric, fertilizer
2. **Workbench Standard** (requires base + workbench) — robots (explorer, gatherer), teleport beacons, armor sets (`WorkbenchStandard/Armor/`), weapons (`WorkbenchStandard/Weapons/`)

### Factions and endgame
At level 10 the player picks one of four factions. Each maps 1:1 to a faction-thematic endgame scenario triggered when any faction crosses 75 000 score:

| Faction | Endgame scenario | Theme |
|---------|------------------|-------|
| 🛡️ Military | Domination | Military supremacy, strategic-point control |
| 🌲 Partisans | Anarchy | System collapse via guerrilla tactics |
| 🛠️ Engineers | Scientific Breakthrough | Technological singularity |
| 🌾 Farmers | Evacuation | Peaceful collective exodus |

Score sources (configurable in `Config/EndgameScoring.php`):
- PvP kill → +50 to winner faction
- Quest completion → +100 to player faction
- Building upgrade → +200 to thematically-linked faction
- Strategic-object discovery → +500 to themed faction (Bunker→Military, Technopark→Engineers, Ghost City→Partisans)

Daily threshold check at 04:00 EEST. First faction to cross threshold wins; others enter `lost` / `active` (neutrals) state. Admin dashboard: `/admin/endgame`.

## Installation

### Requirements
- PHP **8.2+**
- MySQL 8.0 or MariaDB 10.6+
- Composer 2.x
- Telegram Bot Token (from [@BotFather](https://t.me/BotFather))

### Quick start

```bash
# 1. Clone
git clone https://github.com/Black-coffe/wild-world-rpg.git
cd wild-world-rpg

# 2. Dependencies
composer install

# 3. Environment
cp env .env
# Edit .env: database creds, telegram.API_KEY, telegram.BOT_USERNAME

# 4. Database
php spark migrate

# 5. Telegram webhook
# Point your bot webhook to https://your-domain.tld/telegram/webhook

# 6. Cron — run every minute
* * * * * cd /path/to/project && php spark tasks:run >> /var/log/wildworld-cron.log 2>&1

# 7. Dev server
php spark serve
```

### Configuration

Key `.env` variables:

```env
CI_ENVIRONMENT = production
app.baseURL = 'https://your-domain.tld/'

database.default.hostname = localhost
database.default.database = wildworld
database.default.username = your_user
database.default.password = your_password

telegram.API_KEY      = 'your_bot_token'
telegram.BOT_USERNAME = 'your_bot_username'
telegram.HOOK_URL     = 'https://your-domain.tld/telegram/webhook'
telegram.MY_CHAT_ID   = 'your_admin_chat_id'
```

## Architecture

```
app/
├── Config/                # CI4 + custom BaseConfig (GameBalance, EndgameScoring, …)
├── Controllers/
│   ├── Admin/             # Web admin panel (biomes, events, quests, polls, endgame, …)
│   └── Telegram/
│       ├── Commands/      # Bot commands (/start, /name, /tips, /tasks, …)
│       │   └── Actions/   # ~80 callback action handlers
│       │       ├── Camp/Buildings/   (incl. Robots/, Upgrades/)
│       │       ├── PVP/
│       │       └── StartGame/
│       └── BotController.php
├── Database/Migrations/   # Schema evolution (NEVER edit applied migrations)
├── Entities/              # CI4 Entity layer (CharacterEntity, BiomeEntity, …)
├── Filters/, Helpers/, Libraries/, Language/, Views/
├── Models/                # CI4 Models (~50 — characters, world, factions, quests, …)
├── Repositories/          # Atomic DB-mutation wrappers for hot paths
├── Services/              # Business logic, grouped by domain
│   ├── Bases/             # Camp + base checks
│   ├── Coverage/          # Communication-tower coverage
│   ├── Endgame/           # Progression + season reset
│   ├── Events/            # Event dispatch / notification policy
│   ├── PVE/               # Battle, damage, effect, equipment, reward
│   ├── Player/            # Character, craft, death/insurance, detection, gather, …
│   ├── Tasks/             # ActiveTasksService + TaskDispatcher
│   ├── Telegram/          # CallbackRouter
│   └── World/             # Map, mini-map, NPC locator, object discovery
└── TaskHandlers/          # Background processors invoked by cron
    ├── Built/             # Building completion + production
    ├── Craft/             # Craft completion (incl. WorkbenchStandard/Armor/, /Weapons/)
    ├── Endgame/           # Daily threshold check
    ├── Events/            # ~20 world events
    ├── NPC/               # Spawn + AutoPve
    ├── Objects/           # Strategic-object + lootable-object discovery
    ├── Quests/            # Quest progress / completion
    └── Other/             # Faction notifications, world-object generator
```

## Development

### Running tests
```bash
composer test
# or
vendor/bin/phpunit
```

### Static analysis
```bash
vendor/bin/phpstan analyse        # Level 9, baseline-managed
```
The project enforces `declare(strict_types=1)`. New code must not regress the baseline.

### Database
```bash
php spark migrate            # apply pending migrations
php spark migrate:status     # check status
php spark migrate:rollback   # rollback last batch
```

### Adding new features

**New Telegram action:**
1. Create class in `app/Controllers/Telegram/Commands/Actions/<Group>/`
2. Extend `BaseAction` (or `BaseShiftingCommand` for top-level commands)
3. Register the callback in `CallbackRouter` if needed

**New craft item:**
1. Add migration with item definition (`crafted_items`, recipe rows)
2. Add row in `Config/CraftRecipes.php` (production), or wire `GenericCraftCompletionHandler`
3. Add visual asset to `public/uploads/telegram/craft/`

**New TaskHandler:**
1. Extend `BaseTaskHandler` (in `app/TaskHandlers/`)
2. Register the trigger in `Config/Tasks.php` (cron schedule)
3. Implement `handle()` — it must be idempotent (cron may retry)

**Documentation contract:** any change touching a model, service, action handler, task handler, or controller MUST update the corresponding tech-writing note in `mmorpg-vault/tech-writing/`. See [`CLAUDE.md`](./CLAUDE.md) for the full rule.

## Tech stack

- **Framework:** CodeIgniter 4.7
- **Bot library:** [longman/telegram-bot](https://github.com/php-telegram-bot/core) ^0.81
- **HTTP client:** Guzzle
- **Database:** MySQL 8.0 / MariaDB 10.6+
- **Tests:** PHPUnit
- **Static analysis:** PHPStan Level 9
- **CI/CD:** GitHub Actions + rsync

## Contributing

Contributions are welcome. Read [`CONTRIBUTING.md`](./CONTRIBUTING.md) before submitting a PR.

1. Fork
2. Branch (`git checkout -b feature/your-feature`)
3. Commit (`git commit -m 'feat: short description'`)
4. Push (`git push origin feature/your-feature`)
5. Open a PR

## License

MIT — see [LICENSE](LICENSE).

## Acknowledgments

- [CodeIgniter](https://codeigniter.com/) — PHP framework
- [Longman Telegram Bot](https://github.com/php-telegram-bot/core) — Telegram Bot API client
- All contributors and testers

---

<div align="center">

**Made with passion in Ukraine**

[Report Bug](https://github.com/Black-coffe/wild-world-rpg/issues) | [Request Feature](https://github.com/Black-coffe/wild-world-rpg/issues)

</div>
