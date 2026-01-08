# Contributing to Wild World RPG

First off, thank you for considering contributing to Wild World RPG! It's people like you that make this game better for everyone.

## Code of Conduct

This project and everyone participating in it is governed by our commitment to creating a welcoming environment. Please be respectful and constructive in all interactions.

## How Can I Contribute?

### Reporting Bugs

Before creating bug reports, please check the existing issues to avoid duplicates. When you create a bug report, include as many details as possible:

- **Use a clear and descriptive title**
- **Describe the exact steps to reproduce the problem**
- **Provide specific examples** (Telegram message content, screenshots)
- **Describe the behavior you observed and what you expected**
- **Include your environment details** (PHP version, database version, OS)

### Suggesting Features

Feature suggestions are welcome! Please provide:

- **A clear and descriptive title**
- **Detailed description of the proposed feature**
- **Explain why this feature would be useful** to game players
- **Include mockups or examples** if applicable

### Pull Requests

1. **Fork the repository** and create your branch from `master`
2. **Follow the coding standards** (PSR-12 for PHP)
3. **Write meaningful commit messages**
4. **Add tests** for new functionality
5. **Update documentation** if needed
6. **Ensure all tests pass** before submitting

## Development Setup

### Prerequisites

- PHP 8.1+
- Composer
- MySQL 8.0+ or MariaDB 10.6+
- A Telegram Bot Token (for testing)

### Local Development

```bash
# Clone your fork
git clone https://github.com/YOUR_USERNAME/wild-world-rpg.git
cd wild-world-rpg

# Install dependencies
composer install

# Copy and configure environment
cp .env.example .env
# Edit .env with your local settings

# Run migrations
php spark migrate

# Start development server
php spark serve
```

### Running Tests

```bash
# Run all tests
composer test

# Run specific test file
vendor/bin/phpunit tests/unit/YourTest.php
```

## Code Style

### PHP Standards

- Follow **PSR-12** coding standards
- Use **type hints** for function parameters and return types
- Add **PHPDoc blocks** for public methods
- Keep methods focused and under 50 lines when possible

### Naming Conventions

- **Classes:** PascalCase (`CharacterModel`, `BattleService`)
- **Methods:** camelCase (`getCharacter`, `calculateDamage`)
- **Variables:** camelCase (`$playerHealth`, `$resourceCount`)
- **Constants:** UPPER_SNAKE_CASE (`MAX_INVENTORY_SIZE`)
- **Database tables:** snake_case (`character_resources`, `crafted_items`)

### Directory Structure

When adding new features, follow the existing structure:

```
app/
├── Controllers/Telegram/Commands/Actions/  # New game actions
├── Services/                               # Business logic
├── Models/                                 # Data models
├── TaskHandlers/                          # Background tasks
└── Database/Migrations/                    # Schema changes
```

## Game Design Guidelines

### Balance Considerations

When proposing gameplay changes:

- Consider impact on **new players vs veterans**
- Test with different **resource availability** scenarios
- Document any **number changes** (damage, costs, timers)
- Explain the **reasoning** behind balance decisions

### Localization

- All user-facing text should support future localization
- Use language files in `app/Language/` for strings
- Avoid hardcoding text in views or controllers

## Commit Messages

Use clear, descriptive commit messages:

```
# Good examples
feat: Add teleportation beacon crafting
fix: Correct damage calculation in PvE combat
docs: Update installation instructions
refactor: Simplify resource gathering logic

# Format
<type>: <short description>

[optional body with more details]
```

Types: `feat`, `fix`, `docs`, `style`, `refactor`, `test`, `chore`

## Review Process

1. All PRs require at least one review
2. Address feedback promptly
3. Keep PRs focused on a single feature/fix
4. Large changes should be discussed in an issue first

## Questions?

Feel free to open an issue for any questions about contributing. We're happy to help you get started!

---

Thank you for contributing!
