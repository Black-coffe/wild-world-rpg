# Contributing to Wild World RPG

Thanks for considering a contribution! This document covers everything you need to start working on the project: setup, code conventions, the documentation contract, and how PRs flow.

## Code of conduct

Be respectful and constructive. We aim for a welcoming environment for contributors of all backgrounds.

## How to contribute

### Reporting bugs

Search existing issues first, then open a new one with:

- Clear, descriptive title
- Reproduction steps (Telegram message content, screenshots, exact callback you tapped)
- Observed vs expected behaviour
- Environment: PHP version, MySQL/MariaDB version, OS

### Suggesting features

Open an issue with:

- Clear title
- Detailed description of the proposed feature
- Why it would be useful (player perspective)
- Mockups or examples if relevant

### Pull requests

1. Fork the repo and branch off `master`
2. Follow the coding standards below (PSR-12, strict types, PHPStan Level 9)
3. Write clear commit messages (see *Commit messages*)
4. Add tests for new functionality
5. Update documentation — both repo files (`CLAUDE.md`, `GAME_DESCRIPTION.md` if you touched canon) and the Obsidian vault (see *Documentation contract*)
6. Make sure `composer test` and PHPStan are clean before opening the PR

## Development setup

### Prerequisites

- PHP 8.2+
- Composer 2.x
- MySQL 8.0+ or MariaDB 10.6+
- A Telegram Bot Token (for end-to-end testing — get one from [@BotFather](https://t.me/BotFather))

### Local development

```bash
# Clone your fork
git clone https://github.com/YOUR_USERNAME/wild-world-rpg.git
cd wild-world-rpg

# Install dependencies
composer install

# Configure environment
cp env .env
# Edit .env: database creds, telegram.API_KEY, telegram.BOT_USERNAME

# Run migrations
php spark migrate

# Start dev server
php spark serve
```

### Running tests

```bash
# All tests (unit + integration)
composer test

# Single file
vendor/bin/phpunit tests/unit/Services/PVE/DamageServiceTest.php
```

Integration tests require a MySQL test DB; the project ships a test database config in `phpunit.xml.dist`.

### Static analysis

```bash
vendor/bin/phpstan analyse
```

The codebase is enforced at **PHPStan Level 9** with a managed baseline (`phpstan-baseline.neon`). New code must not regress the baseline. If you fix existing issues, lower the baseline; if you genuinely need to add a baseline entry, justify it in the PR.

## Code style

### PHP standards

- **PSR-12** coding standard
- `declare(strict_types=1);` at the top of every PHP file (already enforced across the project)
- Type hints on all parameters and return types
- PHPDoc blocks on public methods only when they add information beyond the type signature (otherwise skip)
- Keep methods small and single-purpose — long methods are a refactor signal

### Naming conventions

- **Classes:** `PascalCase` (`CharacterModel`, `BattleService`)
- **Methods:** `camelCase` (`getCharacter`, `calculateDamage`)
- **Variables:** `camelCase` (`$playerHealth`, `$resourceCount`)
- **Constants:** `UPPER_SNAKE_CASE`
- **Database tables:** `snake_case` (`character_resources`, `crafted_items`)

### Directory structure

When adding new features, follow the layout in [`README.md`](./README.md) §Architecture. Service-layer code lives under `app/Services/<Domain>/`; background work under `app/TaskHandlers/<Group>/`; Telegram callback handlers under `app/Controllers/Telegram/Commands/Actions/<Group>/`.

## Documentation contract

> **This is a hard requirement, not a suggestion.**

Any change that touches a **model / service / Telegram action handler / task handler / web or admin controller** MUST update the corresponding note in the sibling Obsidian vault (`C:\Projects\mmorpg-vault\` for the maintainer; for external contributors, ship the note as part of your PR — it lives next to the relevant subsystem in `mmorpg-vault/tech-writing/<category>/<Name>.md`).

Why: at ~50 models, ~30 services, and ~150 handlers we cannot reason about the codebase without a wiki layer. Without this rule the docs drift from the code in a few months.

Specific rules and templates are documented in [`CLAUDE.md`](./CLAUDE.md) §Constitution. The short version:

- **Created code** → create a note from `_templates/<kind>-doc.md`, fill `frontmatter`, link callers
- **Changed code** → update API / fields / triggers / dependencies, bump `last_reviewed`
- **Deleted code** → mark frontmatter `status: deprecated`, link to replacement; do not delete the note
- **Architectural decision** → add an ADR in `mmorpg-vault/decisions/`
- **Lore / canon change** → update `GAME_DESCRIPTION.md` AND the corresponding atomic note in `mmorpg-vault/lore/`

## Game-balance considerations

When proposing gameplay or formula changes:

- Consider impact on **new players vs veterans**
- Test with realistic resource availability
- Document numeric changes (damage multipliers, tax amounts, cooldowns) in the PR description
- Explain the reasoning — both *why* the current value is wrong and *why* the proposed value is right
- Reference any relevant ADR; if no ADR covers the area, write one

Balance constants live in `app/Config/GameBalance.php` and `app/Config/EndgameScoring.php`. Prefer adding values there over hardcoding them in services.

## Commit messages

Commit messages can be in English or Russian (the project mixes both — code comments and class names are English, gameplay narrative and recent commit history are Russian). Use a conventional-style prefix:

```
feat: Add teleportation beacon crafting
fix: Correct damage calculation in PvE combat
docs: Update installation instructions
refactor: Extract DamageCalculator from BattleService
test: Add unit tests for InsuranceCalculator
chore: Bump CodeIgniter to 4.7.2
```

For multi-step refactors, the project uses a `vX.Y.Z STEP N — description` cadence with one tag per logical step. See `git log` for examples.

## Review process

1. All PRs require at least one review
2. Address feedback promptly
3. Keep PRs focused on a single feature or fix
4. For larger changes (any cross-cutting refactor, schema change, or new subsystem), open an issue first to align on scope

## Questions?

Open an issue. We're happy to help you find your bearings.

---

Thanks for contributing!
