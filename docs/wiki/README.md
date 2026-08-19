# Доменные знания живут не здесь

VULYK по умолчанию держит доменные ноты в `docs/wiki/`. В этом проекте их дом — vault:

| Что нужно | Где искать |
|---|---|
| Кто участвует в подсистеме (модели, сервисы, handler'ы) | `mmorpg-vault/apps/<подсистема>/index.md` |
| Нота по конкретной сущности | `mmorpg-vault/tech-writing/{models,services,handlers,tasks,controllers,db}/` |
| Термин канона | `mmorpg-vault/glossary/` |
| Канон геймплея | `mmorpg-vault/lore/` и `GAME_DESCRIPTION.md` |
| Что в работе сейчас | `mmorpg-vault/wiki/hot.md` |

Конституционное правило tech-writing (`CLAUDE.md`) требует: **любое** изменение модели, сервиса,
Telegram-action-handler'а, task-handler'а или контроллера сопровождается синхронным обновлением
соответствующей ноты в `mmorpg-vault/tech-writing/` — с `last_reviewed: <сегодня>`. Это относится
и к `drone-docs`: его цель — vault, не этот каталог.

Быстрая навигация «где что лежит в коде» — `memory/map/*.md`: тонкие срезы, ведущие в vault.
