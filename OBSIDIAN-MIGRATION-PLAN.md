# План миграции Wild World (mmorpg) в Obsidian: что брать, что отбросить

> ⚠️ **Базовое архитектурное решение.** Vault лежит соседом репо как обычные markdown-файлы. Claude Code читает/правит их через стандартные `Read` / `Write` / `Glob` / `Grep` — **без MCP, без плагинов, без npm/REST API**. Это финальный режим работы, проверенный в проекте-доноре M-2087 (см. там ADR-010). Соответственно из 144 пунктов исходного исследования всё, что касалось `.mcp.json`, `obsidian-semantic-mcp`, WebSocket-моста на `:22360`, диагностики через `/mcp` — **отбрасывается**. Оставляем структуру vault'а, шаблоны, конституциональное правило tech-writing, atomic notes для канона/гейм-дизайна.
>
> **Назначение:** персонализированный фильтр практик «Obsidian + Claude Code» под фактическое состояние проекта **Wild World** (Telegram MMORPG на CodeIgniter 4). Не «всё подряд внедряем», а «что реально добавит ценность поверх того, что уже есть».
>
> **Парные документы в репо:** [`CLAUDE.md`](./CLAUDE.md) (текущие правила и архитектура), [`GAME_DESCRIPTION.md`](./GAME_DESCRIPTION.md) (полное описание игры — лор, механики, формулы), [`README.md`](./README.md), [`CONTRIBUTING.md`](./CONTRIBUTING.md).
>
> **Дата:** 2026-05-04.

---

## Содержание

- [1. Текущее состояние Wild World (инвентарь)](#1-текущее-состояние-wild-world-инвентарь)
- [2. Принципы фильтрации](#2-принципы-фильтрации)
- [3. Что уже де-факто сделано](#3-что-уже-де-факто-сделано)
- [4. Что НЕ берём (избыточно для соло-проекта)](#4-что-не-берём-избыточно-для-соло-проекта)
- [5. Что берём — приоритизированный список](#5-что-берём--приоритизированный-список)
- [6. Поэтапный план миграции](#6-поэтапный-план-миграции)
- [7. Структура vault'а под Wild World](#7-структура-vault-а-под-wild-world)
- [8. Изменения в существующих файлах репо](#8-изменения-в-существующих-файлах-репо)
- [9. Открытые вопросы и риски](#9-открытые-вопросы-и-риски)

---

## 1. Текущее состояние Wild World (инвентарь)

**Контекст:** соло-разработка на стеке CodeIgniter 4 + PHP + MySQL/MariaDB + Longman Telegram Bot. Проект публичный (`Initial public release: Wild World RPG`, ветка `master`), активно развивается (свежие коммиты по PVE, опросам в чате, перемещению по карте). Запущен через Laragon как локальный веб-сервер.

### Документация (что уже есть в репо)

| Файл | Роль | Что внутри |
|---|---|---|
| `CLAUDE.md` | **Константа агента** | Project overview, dev-команды (`composer test`, `php spark migrate`, `php spark serve`), архитектура core-систем, директории, схема БД, гайдлайны добавления фич |
| `GAME_DESCRIPTION.md` | **Канон-источник истины по геймплею** | Лор (постапокалиптический остров 1000×1000), биомы, персонаж и характеристики, исследование, добыча, крафт, PvE/PvP, базы, события — всё подробно с формулами |
| `README.md` | Точка входа | Описание для GitHub-аудитории |
| `CONTRIBUTING.md` | Правила контрибьютинга | |
| `LICENSE` | Лицензия | |

**Итого:** компактный набор корневых документов. Сильно меньше, чем у M-2087 (3500+ строк), — у нас `GAME_DESCRIPTION.md` тащит почти весь канон, а `CLAUDE.md` — стек/команды/структуру. Это плюс (меньше дублирования) и минус (ADR/решения нигде не зафиксированы — живут в head'е разработчика и в коммит-сообщениях).

### Код и инфраструктура (что инвентаризовано)

**Core-стек:** CodeIgniter 4 (CI4) · PHP · MySQL/MariaDB · Longman/telegram-bot · PHPUnit.

**Слои в `app/`:**

| Слой | Что внутри |
|---|---|
| `app/Controllers/` | Web-контроллеры: `Login`, `Signup`, `Password`, `UserController`, `BaseController`, `AdminController`, `Worker`, а также **батл-контроллеры** `BattlesController`, `PvETestController`, `PvPController` и `MigrationController` |
| `app/Controllers/Admin/` | Админ-панель: `MapController`, `BiomeController`, `EventController`, `QuestController`, `ResourceController`, `TaskController`, `WorldObjectController`, `GameTipsController`, `MessageController`, `PollController`, `CharacterReset`/`CharacterResetController` |
| `app/Controllers/Telegram/` | Бот: `BotController` + папки `Commands/`, `Commands/SystemCommands/`, `Commands/Actions/` |
| `app/Controllers/Telegram/Commands/` | Бот-команды: `StartCommand`, `NameCommand`, `TasksCommand`, `TipsCommand`, `StartrobotexplorerCommand`, базовый `BaseShiftingCommand` |
| `app/Controllers/Telegram/Commands/Actions/` | **Большая куча action-handler'ов** — корневые (`CharacterActions`, `ExploreAction`, `MoveCharacterAction`, `GatherAction`, `ShopAction`, `EventAction`, `PharmacyAction`, …) + подпапки `Camp/`, `Camp/Buildings/`, `Camp/Buildings/Robots/`, `Camp/Buildings/Upgrades/`, `PVP/`, `StartGame/` |
| `app/Models/` | **~49 моделей CI4**: `CharacterModel`, `CharacterDataModel`, `CharacterResourceModel`, `CharacterTaskModel`, `CharacterBuildingModel`, `CharactersOutfitsModel`, `CharactersWeaponsModel`, `CharacterFactionModel`, `CharacterMessageStatusModel`, `CharacterGameTipModel`, `CharacterNamesModel`, `MapModel`, `BiomeModel`, `BiomeWorldObjectMapModel`, `ResourceModel`, `CraftedItemsModel`/`CraftedItemModel`, `BuildingModel`, `WeaponModel`, `OutfitModel`, `WorldObjectModel`, `EventModel`/`ActiveEventModel`/`EventEffectsLogModel`, `QuestModel`/`QuestStepsModel`/`QuestRequirementsModel`, `TaskModel`, `FactionModel`, `TelegramUserModel`, `UserModel`, `NpcModel`/`NpcSpawnModel`, `BattleLogModel`, `ActionLogModel`, `TransactionModel`, `SalesModel`, `ResourcesBankModel`, `ExploredCellsModel`, `ClaimedCellModel`, `TeleportBeaconModel`/`TeleportBeaconLogModel`, `PlayerDetectionModel`/`PlayerDetectionHistoryModel`, `GameTipsModel`, `PollModel`/`PollAnswerModel`/`PollVoteModel`, `CraftedItemsLogModel`, `GeneralModel` |
| `app/Services/` | Доменная логика по подсистемам: `Player/` (`CharacterService`, `PvEService`, `PvPRestrictionService`, `DeathService`, `CraftService`, `TeleportCostService`, `PlayerDetectionService`, `PlayerStateService`, `NpcCombatService` + `Interfaces/BattleServiceInterface`), `PVE/` (`BattleService`, `DamageService`, `EffectService`, `EquipmentService`, `RewardService`, `BattleLogger`, `PveBattleLogService`), `Bases/` (`CampCheckService`, `BaseCheckService`), `Tasks/ActiveTasksService`, `World/` (`MapService`, `MiniMapService`, `MapZoomService`, `ObjectDiscoveryService`, `TextMapService`, `NpcLocatorService`), `Coverage/CommunicationTowerCoverageService`, базовый `BaseService` |
| `app/TaskHandlers/` | Фоновые задачи: корень (`GatherTaskHandler`, `ExplorationTaskHandler`, `CompleteRobotGatheringHandler`, `CompleteRobotExplorationHandler`, `HealthRegenerationHandler`, `LowHealthWarningHandler`, `FoodAndWaterConsumptionHandler`, `DeathRouletteHandler`, `EventActivationHandler`, `GreenhouseProductionHandler`, `GymProductionHandler`, `ResourceBankUpdateHandler`, `TaxCollectionHandler`, `CharacterDataHandler`) + подпапки `Built/` (~12 хендлеров завершения построек + `BaseRelocationCompletionHandler`, `BaseFullRelocationCompletionHandler`, `HandPumpProductionHandler`, `GymProductionHandler`), `Craft/` (~25 хендлеров крафта и подпапка `WorkbenchStandard/` с `Armor/`, `Weapons/`), `Events/` (~20 хендлеров мировых событий), `NPC/` (`SpawnSandyWolfRaidersCron`, `AutoPveHandler`), `Objects/` (`ObjectHandlerInterface`, `ToolkitHandler`, `AbandonedTruckHandler`, `ClosedWarehouseHandler`), `Quests/`, `Other/` |
| `app/Database/Migrations/` | Десятки миграций (видно эволюцию: `Users`, `Biomes`, `Map`, `Characters`, `Resources`, `Tasks`, `ExploredCells`, `Events`, `CraftedItems`, `Quests`, `Buildings`, `CharacterBuildings`, `Factions`, `Sales`, `Transactions`, …) |
| `app/Entities/`, `app/Config/`, `app/Filters/`, `app/Helpers/`, `app/Libraries/`, `app/Language/`, `app/Views/` | Стандартные слои CI4 |
| `tests/` | PHPUnit (`composer test` / `vendor/bin/phpunit`, конфиг `phpunit.xml.dist`) |
| `public/uploads/telegram/` | Картинки крафта/объектов/UI для бота |
| `.claude/settings.local.json` | Локальные настройки Claude Code (есть, но без MCP — мы и не планируем) |

**Конституциональные правила, которые УЖЕ зафиксированы в репо:**
- 🛠️ Стек CI4 + PHP + MySQL + Longman Telegram (в `CLAUDE.md`)
- 🤖 Бот-команды наследуются от `BaseShiftingCommand`, action-handlers лежат в `Actions/`
- 🧩 Сервисы группируются по доменам в `app/Services/<Domain>/`
- 🗄️ Все DB-изменения через `app/Database/Migrations/`
- 🎨 Картинки крафта в `public/uploads/telegram/craft/`

**Конституциональные правила, которых ПОКА НЕТ (но в M-2087 они оказались критическими):**
- ❌ **Tech-writing синхронно с кодом** — любая модель/сервис/таска/handler → нота в vault. Сейчас никаких заметок про сущности нет.
- ❌ **ADR — отдельный формат** — все архитектурные решения сейчас живут в коммитах и в голове.
- ❌ **Жёсткая цель покрытия тестов** — есть PHPUnit, но без целевого % и без «двух видов» (auto + manual).
- ❌ **Обязательное audit-логирование действий игрока** — есть `ActionLogModel`/`BattleLogModel`/`CraftedItemsLogModel`, но нет конституционального требования «любое мутирующее действие → лог».
- ❌ **Daily journal** — есть только `git log` и сообщения коммитов на русском.
- ❌ **Hot-context для Claude** — каждая новая сессия Claude тянет 456 строк `CLAUDE.md` + догадывается из git status, что в работе.

### Что эта инвентаризация говорит

**Сильные стороны:**
- Code-base **сильно структурирован**: домены чистые (Telegram / PVE / Player / Bases / Tasks / World / Coverage), модели на сущность, миграции на каждое изменение БД.
- `GAME_DESCRIPTION.md` — компактный, но плотный канон. Не разбух до монолита, его можно «съесть» за один заход.
- CLAUDE.md адекватной длины (~150 строк) — Claude в каждой сессии тянет понятный контекст без оверхеда.
- Всё на русском в gameplay-доках и коммитах — но имена классов/файлов на английском. Это удобно для смешанной навигации.

**Слабые стороны:**
- **49 моделей и сотни action-handler'ов без описаний** — Claude в новой сессии не знает, что делает `CompleteRobotExplorationHandler`, не открыв файл. На масштабе эта незаметность накапливается.
- **Нет глоссария**: «Биом», «Зачерствение базы», «Маяк телепорта», «Робот-сборщик», «Алхимик-Workbench», «Эмиссар Песчаных волков» — нигде не определены формально, только в коде и в голове разработчика.
- **Нет ADR**: «почему DamageService и EquipmentService разнесены», «почему ResourceModel — общий, а CharacterResourceModel — отдельно», «почему StartGame/-action-handlers'ы — отдельная папка» — все эти решения существуют, но нет источника правды о причинах.
- **Журнал работы фрагментарен** — git log + чекбоксы в личных заметках. Нет «что сделали 2026-04-30».
- **Канон в одном файле** (`GAME_DESCRIPTION.md`) — пока ОК, но при росте до 2000+ строк станет узким местом, как у M-2087 с гайдбуком на 1215 строк.

**Что Obsidian реально добавит:**
1. **Атомарные ноты + граф** — вместо «прочитай раздел про крафт в `GAME_DESCRIPTION.md`» → «прочитай `[[Крафт-Workbench-General]]` + связанные».
2. **Tech-writing wiki по `app/Models/` и `app/Services/`** — каждая модель/сервис/handler — отдельная нота с frontmatter, полями, callers'ами, связанными ADR.
3. **Глоссарий с wiki-links** — каждое упоминание «Биом» / «Маяк» / «Workbench» в тексте = ссылка на ноту.
4. **Daily journal сессий** — сейчас этого нет, есть только git commits.
5. **ADR в чистом формате** — фиксируем решения, чтобы через год знать, почему `BattleService` отделён от `DamageService`.
6. **Hot-context для Claude** — `wiki/hot.md` обновляется при `/clear`, экономит токены и не даёт «забыть, что в работе».

**Что Obsidian НЕ должен заменять:**
- `CLAUDE.md` остаётся в репо (агент должен видеть его автоматически).
- `GAME_DESCRIPTION.md` остаётся в репо (это канон-источник, ноты в vault'е будут на него ссылаться).
- `README.md` / `CONTRIBUTING.md` — точки входа для GitHub-аудитории, остаются в репо.
- Миграции (`app/Database/Migrations/`) — sole source of truth по схеме БД, в vault'е только обзорные ноты «что это за таблица» с обратной ссылкой на миграцию.

---

## 2. Принципы фильтрации

При оценке каждой практики из исследования:

🔴 **«Уже есть»** — реализовано в репо в другой форме. Не дублировать, отметить откуда.
🟢 **«Берём»** — добавит ценность поверх существующего, не дублирует.
🟡 **«Адаптируем»** — идея полезна, но в исходной формулировке не подходит — нужна модификация под наш стек/масштаб.
⚫ **«Отбрасываем»** — избыточно для соло-проекта, или неактуально для CodeIgniter/Telegram-бота, или дублирует существующее.

**Метрика «брать или нет»:**
- Снижает ли расход токенов в работе с Claude? (главная экономика)
- Решает ли реальную боль (пример: «забыл, почему 2 месяца назад вынес `EquipmentService` отдельно»)?
- НЕ повышает ли стоимость поддержки (новый формат — его нужно поддерживать; если читать буду 1 раз в год — не стоит)?
- Соло-проект: командные практики (PR на vault, vault-lead, conflict resolution) — отбрасываем по умолчанию.

---

## 3. Что уже де-факто сделано

| Практика | Где у нас |
|---|---|
| `CLAUDE.md` как точка входа | ✅ Корень репо, ~150 строк, актуальный |
| ADR-шаблон | ❌ Нет — но решения принимаются (видно по git history). Введём в фазе 2 |
| Шаблон Bug Investigation | 🟡 Через коммит-сообщения и `daily-changes`-стиль ручной |
| Refactor Plan | ❌ Нет отдельного — в голове + git |
| «Для агентов» в каждом файле | ❌ Нет, но `CLAUDE.md` есть как глобальный |
| Onboarding-нота | 🟡 Частично через `CONTRIBUTING.md` + `README.md` + `GAME_DESCRIPTION.md` |
| «Дай первый драфт» | ✅ Зафиксировано в стиле общения |
| Auto-commit | ✅ Делается вручную, регулярно (видно по git log) |
| «Плохие данные = плохие ответы» | 🟡 Не зафиксировано в правилах, но философия в стиле работы видна |
| Зеркало `app/Controllers/Telegram/Commands/` ↔ `tests/` | 🟡 Частично через `tests/unit/` |
| Mermaid-диаграммы | ❌ Не используются. Можно ввести в фазе 2 |
| Runbooks | ❌ Нет (нет prod-окружения с инцидентами; добавим когда появится) |
| Ссылки file:line | 🟡 Иногда в коммитах. Сделаем стандартом |
| ADR-driven dev | ❌ Нет — введём в фазе 2 |

**Итого ~3-4 пункта де-факто работают, остальные — поле для миграции.**

---

## 4. Что НЕ берём (избыточно для соло-проекта)

### Командная работа — почти всё ⚫

| Пункт | Решение | Причина |
|---|---|---|
| Vault — отдельный git-репо | ⚫ Отбрасываем | Соло. Vault соседом репо, отдельный репо — оверхед |
| Vault-lead | ⚫ Отбрасываем | Соло |
| PR на vault | ⚫ Отбрасываем | Соло. Прямые коммиты в `master` vault'а |
| Templates как git submodule | ⚫ Отбрасываем | Templates лежат в vault'е, версионируются вместе |
| Conflict resolution для vault | ⚫ Отбрасываем | Не возникает у соло |
| Чек-лист «изменился ли vault» в PR-template | ⚫ Отбрасываем | Нет PR-flow внутри проекта |
| Onboarding-нота для нового члена | ⚫ Отбрасываем | Соло. `README.md` + `CONTRIBUTING.md` достаточно для внешних PR'ов |
| Personal layer vs Team layer | ⚫ Отбрасываем | Соло |

**Берём из «команды» только:** нумерованные ADR (как принцип), single source of truth (как принцип), линковка ADR на конкретные коммиты GitHub.

### Лайфхаки на грани оверхеда — обдуманно ⚫

| Пункт | Решение | Причина |
|---|---|---|
| DevDiary с эмоциями (mood/energy) | ⚫ Отбрасываем | Личное, не проектное |
| Голосовые ноты в inbox через STT | ⚫ Отбрасываем | Сейчас не нужно. Если появится боль «забываю мысли в дороге» — вернёмся |
| Vault как индекс к Linear/Jira | ⚫ Отбрасываем | Не используем Linear/Jira |
| Спасти vault от Notion | ⚫ Отбрасываем | Не используем Notion |
| «Talk to past self» | 🟡 Адаптируем | Полезно, но не для отдельной фичи. Просто иногда попросим Claude прочитать старый блок журнала |
| Книги/статьи как ноты | ⚫ Отбрасываем | Не для проектного vault'а |
| Inbox-emergency через STT | ⚫ Отбрасываем | Не нужно сейчас |
| Personal/Partner OS | ⚫ Отбрасываем | Project-vault, не life-vault — не размываем |
| Claude чинит messy vault | 🟡 Адаптируем | Полезно как разовый sanity-check, не как регулярная практика |
| «Книга проекта» к финалу | ⚫ Отбрасываем | Через год — да, сейчас рано |
| Telegram-бот для capture в Obsidian inbox | ⚫ Отбрасываем | Соло, мысли проще писать сразу в IDE |
| Web Clipper | ⚫ Отбрасываем | Не для дев-vault |

### Дублирующие функции существующих practices ⚫

- Плагины **Tasks** / **Periodic Notes** — оверхед: `git log` + чекбоксы в голове пока хватает.
- Auto-генерация changelog — у нас есть `CHANGELOG.md` через коммиты. Хороший коммит-message > авто-генерация.
- CI-валидация vault'а — оверхед на старте. Когда vault станет большим — вернёмся.
- Versioned API docs — у нас нет публичного API (внутренний Telegram-бот). Будет когда — добавим.
- Performance log — для прода. Сейчас локальный laragon. В backlog.
- Dependency log — у нас `composer.json` + `composer.lock` ведут полную историю. Дополнительно не нужно.

### Специфично для нашего стека — отбрасываем ⚫

- **Любой пункт про Local REST API plugin / `.mcp.json` / `obsidian-semantic-mcp` / WebSocket-мост** — отброшено в базовом архитектурном решении (vault на FS, Read/Write напрямую).
- **Pre-commit hook для авто-форматирования через Claude PostToolUse** — у нас не настроены hooks Claude Code, и пока не нужно. Composer + PHPUnit достаточно.
- **`USE_BUILTIN_RIPGREP=0`** — добавим если поиск по vault реально начнёт тормозить (vault меньше 1000 нот — вряд ли).

**Итого ~30-35 пунктов отбрасываем.**

---

## 5. Что берём — приоритизированный список

### 🔴 Критично (фаза 1 — час работы)

| Пункт | Действие |
|---|---|
| Vault соседом репо | Создать `C:\laragon\www\mmorpg-vault\` — обычная папка с markdown-файлами. **Без MCP, без плагинов** — Claude читает через `Read`/`Glob`/`Grep` |
| Корневой `README.md` vault'а | Точка входа: что внутри, как читать, ссылки на разделы и на `CLAUDE.md` репо |
| Hot-context файл `wiki/hot.md` | Что сейчас в работе. Обновляется в конце каждой сессии Claude и при `/clear` |
| Раздел «Active Context» в `CLAUDE.md` | Короткая ссылка из репо в vault — «при старте сессии Claude обязан прочитать `mmorpg-vault/wiki/hot.md`» |
| `.gitignore` репо | Добавить исключения `.obsidian/workspace.json`, `.obsidian/cache/`, `.trash/` (это понадобится только если решим положить vault внутрь репо; для варианта «соседом» — не трогаем) |

### 🟢 Высокая ценность (фаза 2 — 1-3 дня)

| Пункт | Действие |
|---|---|
| Структура vault'а | `wiki/` + `lore/` + `apps/` + `tech-writing/` + `decisions/` + `glossary/` + `daily/` + `snippets/` + `_templates/` (детально — см. секцию 7) |
| `index.md` в каждой папке | Карта раздела — что внутри, что искать, как навигировать |
| Зеркало `app/Models/` + `app/Services/` + `app/Controllers/Telegram/Commands/` + `app/TaskHandlers/` | Атомарные ноты в `apps/<домен>/index.md` со ссылками на детальные ноты |
| `GAME_DESCRIPTION.md` → атомарные ноты в `lore/` | **Главное действие фазы 2.** Разрезаем 600+ строк канона на ноты по подсистемам |
| Frontmatter с тегами | YAML на каждой нотe — `type`, `tags`, `last_reviewed`, `source: human/claude/mixed`, `verified: true/false` |
| Templater-плагин (опционально) | Если поставим Obsidian-приложение — Templater для шаблонов; если работаем только через Claude — шаблоны в `_templates/` руками |
| Dataview (опционально) | Дашборды «все proposed ADR», «все unverified ноты», «открытые вопросы». Не критично, добавится позже |
| Obsidian Git (опционально) | Если открываем vault в Obsidian — auto-commit каждые 10-15 мин. Иначе — ручной git в vault'е |
| «Один атом — одна нота» | Применяется при разрезании канона |
| Glossary как ноты | `glossary/Биом.md`, `glossary/Маяк-телепорта.md`, `glossary/Workbench.md` и т.д. — даст граф |
| ADR в формате Nygard (5 секций) | Контекст → Решение → Последствия → Альтернативы → Источники. Нумерация сквозная (ADR-001, ADR-002, …) |
| Snippets с метаданными | Для повторяющихся паттернов CI4: `BaseModel`-наследование, миграция-таблица, action-handler в `Camp/Buildings/`, task-handler в `Craft/`, audit-write в `ActionLogModel` |
| Зеркало схемы БД | `lore/db/index.md` — таблица всех таблиц с ссылками на миграции и на use-сайты в моделях |
| Ноты по Telegram-командам и action-handler'ам | `apps/telegram/index.md` + `tech-writing/handlers/<HandlerName>.md` — особенно полезно из-за их количества |
| Karpathy LLM Wiki | Принцип: Claude дополняет vault сам после каждой сессии (под надзором — см. ниже про verification) |

### 🟡 Полезно когда vault обживётся (фаза 3+)

| Пункт | Действие |
|---|---|
| Ноты-сессии для длинных задач (>2 часов) | `daily/sessions/YYYY-MM-DD-<slug>.md` — план + результат |
| Auto-summary в конце сессии | Промпт для Claude в финале — обновить `daily/<сегодня>.md` |
| «Files become the prompt» | Естественно при росте vault'а — не отдельная практика, а следствие |
| Inbox-паттерн | Если появятся быстрые мысли — `inbox/` для свалки, раз в неделю Claude разносит |
| Smart Connections | Когда vault > 100 нот — для семантического поиска (если откроем vault в Obsidian) |
| Excalidraw для архитектурных эскизов | Карта биомов, схема FSM action-handler'ов, граф зависимостей сервисов |
| Mermaid-диаграммы | Lifecycle PvE-битвы, schema state машин для Camp-Building, sequence-диаграмма обработки Telegram callback'а |
| Auto-backlinks | Через Claude после каждой сессии (под надзором) |
| Дашборды через Dataview | Когда есть что отображать (>20 ADR-нот, >50 tech-writing нот) |
| Weekly AI summary | Раз в неделю Claude резюмирует daily-ноты |
| Сезонные обзоры графа | Раз в квартал — посмотреть на orphan-ноты, найти кластеры/дыры |
| Веденье `tech-writing/middleware/`, `tech-writing/filters/`, `tech-writing/views/` | Когда соответствующие слои разрастутся — сейчас они скромные |
| Runbooks | Когда появится prod (пока laragon локально, нет инцидентов) |
| Post-mortem template | Аналогично — когда появится prod |

### 📦 В backlog (когда появится боль)

- **Hooks Claude Code** (`.claude/settings.json` — PreToolUse / PostToolUse) — добавим если ритуал «обнови daily-changes» начнёт пропускаться.
- **Cron еженедельная ретроспектива** — когда weekly-summary станет привычкой.
- **Smart Connections / семантический поиск** — когда vault > 100 нот.
- **InfraNodus AI** — для анализа графа знаний (очень дальний backlog).
- **Plugin Claudian / Agent Client** (Claude внутри Obsidian) — пока в IDE удобнее.
- **`obsidian-claude-code-mcp` (WebSocket)** — отброшено в базовом решении, возвращаемся только если появится конкретная боль, которую решает только IDE-режим.

**Итого берём:** ~5 пунктов в фазу 1 + ~15 в фазу 2 + ~10 в фазу 3+ + остальное в backlog.

---

## 6. Поэтапный план миграции

### Фаза 0 — Решения (до начала)

- [ ] **Где живёт vault?**
  - Вариант А: `C:\laragon\www\mmorpg-vault\` — соседом репо в той же `www`. Минус: laragon видит как сайт (но `.md` он отдавать не должен). Плюс: рядом, удобно.
  - Вариант B: `C:\Projects\mmorpg-vault\` — вне laragon, на одном уровне с другими проектами.
  - Вариант C: внутри репо `vault/` — не шумит в `git status` основного репо если в `.gitignore`, но тогда теряется отдельный git-цикл.
  - **Рекомендация:** **B** (`C:\Projects\mmorpg-vault\`) — выносим из веб-корня (защита от случайного раздавания через laragon), отдельный git-цикл, легче переносить между машинами. Vault как самостоятельная сущность.

- [ ] **Имена нот: русские или английские?**
  - **Рекомендация:** lore/glossary — на русском (термины из канона: «Биом», «Маяк-телепорта»). Tech-writing/ADR/apps — на английском (повторяет имена в коде: `CharacterModel.md`, `ADR-001-...md`, `apps/pve/index.md`).

- [ ] **Открываем vault в Obsidian-приложении?**
  - **Рекомендация:** Да — для удобного просмотра графа и редактирования руками. **Но не обязательно** — Claude всё равно работает напрямую с FS. Если Obsidian не открыт — vault работает как обычная папка markdown.

- [ ] **`GAME_DESCRIPTION.md` — что с ним?**
  - Рекомендация: оставить файл в репо как «слитную версию канона» (точка входа для GitHub-аудитории и быстрого онбординга), в vault'е — атомарные ноты с ссылками на разделы оригинала. В шапке `GAME_DESCRIPTION.md` добавить пометку «Атомарные ноты канона — в `mmorpg-vault/lore/`».

### Фаза 1 — Setup (1 час)

**Цель:** vault создан, базовая структура есть, Claude понимает, как с ним работать.

- [ ] Создать каталог vault'а согласно решению фазы 0 (рекомендую `C:\Projects\mmorpg-vault\`)
- [ ] `git init` в vault'е, первый коммит `chore: init vault structure`
- [ ] Создать минимум `mmorpg-vault/README.md` — точка входа с картой папок и ссылкой на `CLAUDE.md` репо
- [ ] Создать структуру папок (см. секцию 7) — пустые `wiki/`, `lore/`, `apps/`, `tech-writing/`, `decisions/`, `glossary/`, `daily/`, `snippets/`, `_templates/`
- [ ] В каждую папку положить `index.md`-заглушку (одна строка — «карта раздела, наполняется в фазе 2»)
- [ ] Создать `wiki/hot.md` с одной строкой «текущий фокус: <актуальный блок работы>»
- [ ] **В репо** — обновить `CLAUDE.md`: добавить раздел «Obsidian Vault и hot-context» (см. секцию 8)
- [ ] **В репо** — обновить `.gitignore` если выбран вариант с vault'ом внутри репо
- [ ] Тест: попросить Claude в новой сессии прочитать `mmorpg-vault/wiki/hot.md` через `Read` — должно работать без MCP

**Чек завершения фазы 1:** Claude в новой сессии видит vault, читает `hot.md` адресно, не вкачивая весь канон.

### Фаза 2 — Базовая структура и канон-миграция (1-3 дня)

**Цель:** канон разрезан на атомарные ноты с wiki-links, есть глоссарий, есть зеркало `app/`-структуры.

- [ ] **Templates** — заполнить `_templates/`:
  - `_templates/adr.md` — ADR Nygard (5 секций + frontmatter `type: adr`, `status: proposed/accepted/rejected/superseded`, `date`)
  - `_templates/glossary-term.md` — термин (1-3 параграфа определения + wiki-links на связанные)
  - `_templates/daily.md` — дневник (Сделано / Решения / Открытые вопросы / Завтра)
  - `_templates/lore-note.md` — атомарная нота канона (frontmatter + краткое описание + ссылки на §`GAME_DESCRIPTION.md`)
  - `_templates/model-doc.md` — нота tech-writing про CI4-модель (frontmatter + поля + публичные методы + use-сайты + связанные ADR)
  - `_templates/service-doc.md` — нота tech-writing про сервис (frontmatter + ответственность + публичные методы + зависимости + use-сайты)
  - `_templates/handler-doc.md` — нота про action-handler / task-handler (frontmatter + триггер + поведение + audit-коды + связанные ADR)
  - `_templates/scenario-doc.md` — нота про QA-сценарий (когда такие появятся)
  - `_templates/session-note.md` — план сессии длиннее 2 часов

- [ ] **Разрезать `GAME_DESCRIPTION.md` на атомарные ноты `lore/`** (главное действие фазы 2):
  - `lore/world/Остров-Wild-World.md` (общий лор и сеттинг)
  - `lore/world/Биомы.md` (общая концепция) + **по одной ноте на каждый биом** (`lore/biomes/<имя>.md`) — лес, пустыня, горы и т.д. (имена взять из `BiomeModel`)
  - `lore/character/Характеристики.md` (уровень, опыт, здоровье, выносливость, сила, ловкость, интеллект)
  - `lore/character/Прогрессия-и-смерть.md` (опыт, смерть, страховка)
  - `lore/exploration/Исследование.md` (механика, время, метрика Чебышёва, формула радиуса обнаружения)
  - `lore/exploration/Обнаружение-игроков.md` (PvP-detection)
  - `lore/gather/Добыча-ресурсов.md` (биомные модификаторы, формулы по редкости, инструменты, прочность, ±20%)
  - `lore/gather/Типы-ресурсов.md` (базовые / пищевые / редкие / энергетические)
  - `lore/craft/Крафт-Workbench-General.md` (тиры, требования)
  - `lore/craft/Крафт-Workbench-Standard.md` (продвинутый тир — `Armor/`, `Weapons/`)
  - `lore/combat/PvE.md` (формулы урона, эффекты, экипировка, награды)
  - `lore/combat/PvP.md` (детект, бой, побег)
  - `lore/base/Лагерь.md` (создание, размещение)
  - `lore/base/Постройки.md` (Workshop, Arsenal, Lab, Greenhouse, HandPump, BlastFurnace, Gym, SolarStation, CommunicationTower, RoboticsWorkshop, Warehouse, TeleportationCenter)
  - `lore/base/Робот-исследователь.md` / `lore/base/Робот-сборщик.md`
  - `lore/teleport/Маяк-телепорта.md` (cost, restrictions)
  - `lore/events/Мировые-события.md` (общий список) + по ноте на семейство (FlashForestFire, Epidemic, MirageOasis, SandStorm, GoldVein, NorthernLights, ShootingStar, BerryBoom, Hurricane, MeteorShower, …)
  - `lore/quests/Квесты-и-этапы.md`
  - `lore/factions/Фракции.md` (взять из `FactionModel`)
  - Каждая нота: frontmatter + краткое описание + wiki-links на связанные + ссылка на раздел `GAME_DESCRIPTION.md`

- [ ] **Glossary** — атомарные ноты на каждый ключевой термин:
  - `glossary/Биом.md`, `glossary/Маяк-телепорта.md`, `glossary/Workbench.md`, `glossary/Лагерь.md`, `glossary/Робот-исследователь.md`, `glossary/Робот-сборщик.md`, `glossary/Постройка.md`, `glossary/Фракция.md`, `glossary/Квест.md`, `glossary/Прочность-инструмента.md`, `glossary/Радиус-обнаружения.md`, `glossary/Метрика-Чебышёва.md`, `glossary/Налог-постройки.md`, `glossary/Эмиссар-Песчаных-волков.md`
  - Каждая нота: 1-3 параграфа определения + wiki-links на связанные ноты `lore/`

- [ ] **Apps — зеркало структуры кода**:
  - `apps/index.md` — карта подсистем (Telegram, PVE, Player, World, Bases, Tasks, Coverage, Admin, Quests, Events, NPC, Craft)
  - `apps/telegram/index.md` — таблица: `Commands/` (5 команд) + `Actions/` (~80 handler'ов с разбивкой по подпапкам Camp / Camp/Buildings / Camp/Buildings/Robots / Camp/Buildings/Upgrades / PVP / StartGame) + `SystemCommands/` + `BotController.php`
  - `apps/pve/index.md` — `BattleService`, `DamageService`, `EffectService`, `EquipmentService`, `RewardService`, `BattleLogger`, `PveBattleLogService`, плюс таблицы: `BattleLogModel`, контроллер `BattlesController`/`PvETestController`
  - `apps/player/index.md` — `CharacterService`, `PvEService`, `PvPRestrictionService`, `DeathService`, `CraftService`, `TeleportCostService`, `PlayerDetectionService`, `PlayerStateService`, `NpcCombatService`
  - `apps/world/index.md` — `MapService`, `MiniMapService`, `MapZoomService`, `ObjectDiscoveryService`, `TextMapService`, `NpcLocatorService`, плюс модели `MapModel`, `BiomeModel`, `BiomeWorldObjectMapModel`, `WorldObjectModel`, `ExploredCellsModel`, `ClaimedCellModel`
  - `apps/bases/index.md` — `CampCheckService`, `BaseCheckService` + все Camp/Buildings/* handler'ы + `CharacterBuildingModel`, `BuildingModel`, `TeleportBeaconModel`, `TeleportBeaconLogModel`
  - `apps/tasks/index.md` — `ActiveTasksService` + структура `TaskHandlers/Built/`, `TaskHandlers/Craft/`, `TaskHandlers/Events/`, `TaskHandlers/NPC/`, `TaskHandlers/Objects/`, `TaskHandlers/Quests/`, `TaskHandlers/Other/`
  - `apps/admin/index.md` — `AdminController`, `Admin/MapController`, `Admin/BiomeController`, `Admin/EventController`, `Admin/QuestController`, `Admin/ResourceController`, `Admin/TaskController`, `Admin/WorldObjectController`, `Admin/GameTipsController`, `Admin/MessageController`, `Admin/PollController`, `Admin/CharacterReset`/`Admin/CharacterResetController`
  - `apps/quests/index.md` — `QuestModel`, `QuestStepsModel`, `QuestRequirementsModel` + `TaskHandlers/Quests/*` + `Admin/QuestController`
  - `apps/events/index.md` — `EventModel`, `ActiveEventModel`, `EventEffectsLogModel` + `TaskHandlers/Events/*` + `Admin/EventController` + `EventActivationHandler`
  - `apps/coverage/index.md` — `CommunicationTowerCoverageService`
  - `apps/auth/index.md` — `Login`, `Signup`, `Password`, `UserController`, `UserModel`, `TelegramUserModel`
  - `apps/polls/index.md` — `PollModel`, `PollAnswerModel`, `PollVoteModel`, `Admin/PollController` (свежая фича из коммитов)

- [ ] **Tech-writing — заглушки** (полное наполнение по мере правок кода):
  - `tech-writing/models/index.md` — таблица всех 49 моделей с одной строкой описания + ссылка на детальную ноту (даже если детальной пока нет)
  - `tech-writing/services/index.md` — список всех ~25 сервисов, аналогично
  - `tech-writing/handlers/index.md` — все Telegram action-handler'ы (можно сначала по подпапкам, без детальных нот; детали — при первом изменении handler'а)
  - `tech-writing/tasks/index.md` — все ~70 task-handler'ов с группировкой Built/Craft/Events/NPC/…
  - `tech-writing/db/index.md` — карта таблиц с обратной ссылкой на миграцию

- [ ] **Decisions** (ADR) — выгрузить ключевые решения, которые видны в коде:
  - `decisions/ADR-001-Stack-CI4-PHP-Telegram.md` (почему CodeIgniter 4, не Laravel; почему longman/telegram-bot)
  - `decisions/ADR-002-Action-handlers-в-отдельной-папке.md` (`Commands/` для команд / `Commands/Actions/` для action-handler'ов — обоснование)
  - `decisions/ADR-003-Сервисы-сгруппированы-по-доменам.md` (`Services/PVE/`, `Services/Player/`, `Services/World/` — почему так)
  - `decisions/ADR-004-Tasks-как-фоновая-обработка.md` (`TaskHandlers/` с подпапками — паттерн фоновых задач без Celery)
  - `decisions/ADR-005-CharacterResource-отдельная-таблица.md` (а не JSON в `characters`)
  - `decisions/ADR-006-Биом-влияет-на-добычу-через-модификатор.md`
  - `decisions/ADR-007-Метрика-Чебышёва-для-обнаружения.md`
  - `decisions/ADR-008-Прочность-инструмента-через-износ.md`
  - `decisions/ADR-009-Vault-соседом-репо-без-MCP.md` ⭐ — наш аналог M-2087 ADR-010, обосновывает базовое решение (vault как обычные markdown-файлы, читаются через `Read`/`Write`/`Glob`/`Grep`, без `.mcp.json`, без плагинов)
  - Шаблон каждого ADR: контекст / решение / последствия / альтернативы / источники (ссылка на коммит / на блок плана / на код)

- [ ] **Snippets — повторяющиеся паттерны кода**:
  - `snippets/CI4-Model-pattern.md` — типовой `extends Model`, `$table`, `$primaryKey`, `$useTimestamps`, `$validationRules`
  - `snippets/Migration-table-pattern.md` — типовая миграция с `$forge->addField`, `addKey`, `createTable`
  - `snippets/Action-handler-pattern.md` — типовой `extends BaseAction`, `handle()`, message-edit
  - `snippets/Task-handler-pattern.md` — типовая обработка задачи, finalize, audit
  - `snippets/Telegram-message-with-buttons.md` — inline keyboard через longman
  - `snippets/Audit-log-write.md` — запись в `ActionLogModel` (наш аналог `PlayerActionLog`)

- [ ] **Wiki/hot.md** — реальный контент: что в работе сейчас (например, «Опросы в чате реализованы (коммит 3791304); следующее — балансировка PVE по разнице уровней NPC vs игрок»)

- [ ] Закоммитить всё в vault, тег `v0.1-bootstrap`

**Чек завершения фазы 2:** в vault'е 80-100 нот, граф визуально показывает связанность, Claude может ответить на вопрос «расскажи про базы и их налог» через адресные `Read` к 2-3 нотам (вместо вкачивания всего `GAME_DESCRIPTION.md`).

### Фаза 3 — Daily ритуалы и live-обновления (по ходу разработки)

**Цель:** vault не отстаёт от кода; daily-changes journal работает; tech-writing синхронен.

- [ ] **В `CLAUDE.md` репо ввести конституциональное правило tech-writing** (по образцу M-2087 ADR-010):
  - «Любое изменение модели / сервиса / action-handler'а / task-handler'а в коде → синхронное обновление соответствующей ноты в `mmorpg-vault/tech-writing/`».
  - Чекбокс «завершённой задачи»: код + тесты + миграция (если нужна) + tech-writing нота + ADR (если значимое решение) + `wiki/hot.md` обновлён + коммит.
- [ ] **В `CLAUDE.md` ввести правило end-of-session**: «Перед `/clear` или завершением — обновить `daily/<сегодня>.md` (Сделано / Решения / Открытые вопросы) + `wiki/hot.md`».
- [ ] **При каждом мержанутом изменении:**
  - Если значимое решение → новый ADR в `decisions/`
  - Если новый игровой термин → нота в `glossary/`
  - Обновить tech-writing ноту затронутой сущности
  - Обновить `wiki/hot.md`
- [ ] **Раз в неделю** — попросить Claude прочесть `daily/` за неделю и обновить `wiki/weekly-summary.md`
- [ ] **При начале задачи длиннее 2 часов** — создать `daily/sessions/YYYY-MM-DD-<slug>.md` с планом

**Чек завершения фазы 3:** последние 7 дней daily-нот заполнены, есть хотя бы 2-3 новых ADR с момента старта, glossary + tech-writing растут пропорционально коду.

### Фаза 4 — Опционально (через 1-2 месяца)

- [ ] Открыть vault в Obsidian-приложении: установить плагины Templater, Dataview, Obsidian Git
- [ ] Подключить Smart Connections если vault > 100 нот — для семантического поиска
- [ ] Excalidraw для архитектурных эскизов (карта биомов, граф зависимостей сервисов)
- [ ] Mermaid-диаграммы в `lore/combat/PvE.md` (sequence-диаграмма боя), `apps/telegram/index.md` (state machine action-handler'а)
- [ ] Dataview-дашборд `wiki/dashboards/active-decisions.md` — все ADR со status: proposed
- [ ] Dataview-дашборд `wiki/dashboards/unverified-notes.md` — все ноты с `verified: false`
- [ ] Pre-commit hook (опционально): записывать diff в `daily/diffs/<дата>.md`
- [ ] Hooks Claude Code (`.claude/settings.json` PostToolUse) — auto-update daily

---

## 7. Структура vault'а под Wild World

```
mmorpg-vault/                       (рекомендую: C:\Projects\mmorpg-vault\)
├── README.md                        ← точка входа, карта vault'а
├── _templates/                      ← шаблоны (для Templater + ручные)
│   ├── adr.md
│   ├── glossary-term.md
│   ├── daily.md
│   ├── lore-note.md
│   ├── model-doc.md
│   ├── service-doc.md
│   ├── handler-doc.md
│   ├── scenario-doc.md
│   └── session-note.md
│
├── wiki/                            ← LIVE-контекст для Claude
│   ├── hot.md                       ← что СЕЙЧАС в работе (обновляется часто)
│   ├── index.md                     ← карта vault'а
│   ├── weekly-summary.md            ← раз в неделю Claude резюмирует
│   ├── dashboards/                  ← Dataview-дашборды (фаза 4)
│   └── archive/                     ← старые hot.md
│
├── lore/                            ← КАНОН разрезанный на атомы
│   ├── index.md
│   ├── world/
│   │   ├── Остров-Wild-World.md
│   │   └── Биомы.md
│   ├── biomes/                      ← по ноте на каждый биом
│   │   ├── index.md
│   │   ├── Лес.md
│   │   ├── Пустыня.md
│   │   ├── Горы.md
│   │   ├── Река.md
│   │   └── ...
│   ├── character/
│   │   ├── Характеристики.md
│   │   └── Прогрессия-и-смерть.md
│   ├── exploration/
│   │   ├── Исследование.md
│   │   └── Обнаружение-игроков.md
│   ├── gather/
│   │   ├── Добыча-ресурсов.md
│   │   └── Типы-ресурсов.md
│   ├── craft/
│   │   ├── Крафт-Workbench-General.md
│   │   └── Крафт-Workbench-Standard.md
│   ├── combat/
│   │   ├── PvE.md
│   │   └── PvP.md
│   ├── base/
│   │   ├── Лагерь.md
│   │   ├── Постройки.md
│   │   └── Роботы.md
│   ├── teleport/
│   │   └── Маяк-телепорта.md
│   ├── events/
│   │   └── Мировые-события.md
│   ├── quests/
│   │   └── Квесты-и-этапы.md
│   ├── factions/
│   │   └── Фракции.md
│   └── future/                      ← задумки/roadmap идеи
│
├── apps/                            ← ЗЕРКАЛО app/-структуры репо
│   ├── index.md                     ← карта подсистем
│   ├── telegram/index.md
│   ├── pve/index.md
│   ├── player/index.md
│   ├── world/index.md
│   ├── bases/index.md
│   ├── tasks/index.md
│   ├── admin/index.md
│   ├── quests/index.md
│   ├── events/index.md
│   ├── coverage/index.md
│   ├── auth/index.md
│   └── polls/index.md
│
├── tech-writing/                    ← WIKI по моделям/сервисам/handler'ам
│   ├── index.md
│   ├── models/                      ← одна нота на модель CI4
│   │   ├── index.md
│   │   ├── CharacterModel.md
│   │   ├── MapModel.md
│   │   ├── BiomeModel.md
│   │   └── ...
│   ├── services/                    ← одна нота на сервис
│   │   ├── index.md
│   │   ├── BattleService.md
│   │   ├── DamageService.md
│   │   ├── PvEService.md
│   │   └── ...
│   ├── handlers/                    ← Telegram action-handler'ы
│   │   ├── index.md
│   │   ├── camp/
│   │   ├── camp-buildings/
│   │   ├── pvp/
│   │   ├── start-game/
│   │   └── ...
│   ├── tasks/                       ← TaskHandlers/*
│   │   ├── index.md
│   │   ├── built/
│   │   ├── craft/
│   │   ├── events/
│   │   ├── npc/
│   │   ├── objects/
│   │   ├── quests/
│   │   └── other/
│   ├── controllers/                 ← Web/Admin контроллеры
│   │   └── index.md
│   ├── db/                          ← карта таблиц с обратной ссылкой на миграции
│   │   └── index.md
│   ├── filters/
│   ├── helpers/
│   └── libraries/
│
├── decisions/                       ← ADR (один файл — одно решение)
│   ├── index.md                     ← Dataview-таблица всех ADR (фаза 4) или ручной список
│   ├── ADR-001-Stack-CI4-PHP-Telegram.md
│   ├── ADR-002-Action-handlers-в-отдельной-папке.md
│   ├── ADR-003-Сервисы-сгруппированы-по-доменам.md
│   ├── ADR-004-Tasks-как-фоновая-обработка.md
│   ├── ADR-005-CharacterResource-отдельная-таблица.md
│   ├── ADR-006-Биом-влияет-на-добычу-через-модификатор.md
│   ├── ADR-007-Метрика-Чебышёва-для-обнаружения.md
│   ├── ADR-008-Прочность-инструмента-через-износ.md
│   └── ADR-009-Vault-соседом-репо-без-MCP.md
│
├── glossary/                        ← каждый термин — отдельная нота
│   ├── index.md
│   ├── Биом.md
│   ├── Маяк-телепорта.md
│   ├── Workbench.md
│   ├── Лагерь.md
│   ├── Робот-исследователь.md
│   ├── Робот-сборщик.md
│   ├── Постройка.md
│   ├── Фракция.md
│   ├── Налог-постройки.md
│   ├── Радиус-обнаружения.md
│   └── ...
│
├── daily/                           ← журнал сессий и дней
│   ├── index.md
│   ├── 2026-05-04.md
│   └── sessions/                    ← длинные многочасовые сессии
│       └── 2026-05-04-pve-balance.md
│
├── snippets/                        ← повторяющиеся паттерны кода CI4
│   ├── index.md
│   ├── CI4-Model-pattern.md
│   ├── Migration-table-pattern.md
│   ├── Action-handler-pattern.md
│   ├── Task-handler-pattern.md
│   ├── Telegram-message-with-buttons.md
│   └── Audit-log-write.md
│
└── inbox/                           ← быстрые мысли (опционально)
    └── (Claude разкладывает в нужные папки раз в день)
```

**Принципы:**
- `wiki/hot.md` — единственный «горячий» файл. Обновляется при каждом `/clear` и в конце сессии.
- `apps/<app>/index.md` — таблица: модели + сервисы + handler'ы + ссылки на ноты канона.
- `decisions/` — нумерация сквозная.
- `glossary/` — связано wiki-links с `lore/`. Граф будет красивым.
- `daily/` — НЕ дублирует git log; только содержательный журналинг (что решили, почему, что было неожиданным).
- `tech-writing/` — наполняется по мере правок кода (правило конституции — фаза 3), а не разом в фазе 2 (это бы заняло неделю и большая часть нот была бы read-once).

---

## 8. Изменения в существующих файлах репо

После настройки vault'а:

### `CLAUDE.md` — добавить секцию «Obsidian Vault»

Вставить после блока «Project Overview», до «Development Commands»:

```markdown
## 🗂️ Obsidian Vault

**Vault:** `C:\Projects\mmorpg-vault\` (соседом репо). Это обычные markdown-файлы.
Claude Code читает/правит их через стандартные `Read` / `Write` / `Glob` / `Grep` —
**без MCP, без плагинов, без npm/REST API**. См. `decisions/ADR-009-Vault-соседом-репо-без-MCP.md` в самом vault'е.

### Когда читать vault:

- В начале каждой новой сессии — **обязательно** `mmorpg-vault/wiki/hot.md` (что в работе сейчас).
- Вопросы про лор / геймплей → `mmorpg-vault/lore/<раздел>/`.
- Вопросы про конкретную подсистему → `mmorpg-vault/apps/<подсистема>/index.md`.
- Вопрос «почему мы выбрали X?» → `mmorpg-vault/decisions/`.
- Незнакомый термин → `mmorpg-vault/glossary/<термин>.md`.
- Поиск «где это лежит» / «что делает X» → `mmorpg-vault/tech-writing/`.

### Когда писать в vault:

- Завершение сессии → `mmorpg-vault/daily/<дата>.md` summary (Сделано / Решения / Открытые вопросы / Завтра).
- Принятие архитектурного решения → новый ADR в `mmorpg-vault/decisions/`.
- Новый игровой термин в коде → нота в `mmorpg-vault/glossary/`.
- Изменение модели / сервиса / action-handler'а / task-handler'а →
  обновить соответствующую ноту в `mmorpg-vault/tech-writing/` (см. правило ниже).
- Большая сессия (>2ч) → план в `mmorpg-vault/daily/sessions/`.

### Что НЕ читать из vault для кодовых вопросов:

- Общие вопросы PHP / CodeIgniter / Telegram Bot API → встроенные знания, не vault.
- Документация `composer`, `phpunit`, `longman/telegram-bot` → официальная, не vault.

## 📚 Конституциональное правило tech-writing

**Любое изменение в коде, затрагивающее модель / сервис / action-handler / task-handler / контроллер, ОБЯЗАНО сопровождаться синхронным обновлением соответствующей ноты в `mmorpg-vault/tech-writing/`.**

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
- Заполнить frontmatter: `type`, `app`, `class`/`handler`, `last_reviewed: <today>`, `source: human` (или `mixed` если генерил Claude), `verified: true`.
- Проставить ссылки на смежные ноты (`apps/<app>/index.md` уже знает про эту сущность? — обновить и там).
- Указать связанные ADR (если новое решение — создать ADR в `decisions/`).
- В блок «Где используется» — добавить актуальные callers.

**При изменении кода:**
- API изменился (новый метод / параметр / поле модели) → обновить раздел «Публичный API» или «Поля».
- Расписание task-handler'а сменилось → обновить раздел «Триггер».
- Добавили новый action в `ActionLogModel` → обновить «Audit-коды».
- Зависимости изменились → обновить «Зависимости».
- Обновить `last_reviewed: <today>` в frontmatter.

**При удалении кода:**
- Не удалять ноту, а пометить frontmatter `status: deprecated` + причину.
- В шапке — ссылка на замещающую ноту (если есть).

### Workflow в конце сессии Claude

Перед `/clear` или завершением работы Claude обязан:

1. **Обновить `mmorpg-vault/daily/<сегодня>.md`** с разделами «Сделано», «Решения», «Открытые вопросы», «Завтра».
2. **Обновить `mmorpg-vault/wiki/hot.md`** под актуальный фокус (если изменился).
3. **Создать/обновить tech-writing ноту** для каждой затронутой сущности.
4. **Создать ADR** если приняли архитектурное решение.

### Что считается «завершённой задачей»

Чек-лист расширен:
- ✅ Код написан
- ✅ PHPUnit-тесты зелёные (`composer test`)
- ✅ Миграция применена и протестирована (если меняли схему)
- ✅ Документация в репо обновлена (`CLAUDE.md`, `GAME_DESCRIPTION.md` если задели лор)
- ✅ **Tech-writing нота обновлена в vault'е** (новое требование с фазы 3)
- ✅ **Если значимое решение — ADR создан в `vault/decisions/`**
- ✅ **`vault/wiki/hot.md` обновлён** (если контекст сменился)
- ✅ Коммит с осмысленным русским сообщением
```

### `GAME_DESCRIPTION.md` — добавить шапку

В самом начале файла, после заголовка:

```markdown
> **📖 Этот файл — слитный канон Wild World.** Атомарные ноты по подсистемам (биомы, крафт, бой, базы, события, …) с wiki-links и графом — в [`mmorpg-vault/lore/`](file:///C:/Projects/mmorpg-vault/lore/index.md). Используй их для адресного чтения; этот файл — для целостного обзора и онбординга.
```

### `.gitignore` — добавить (только если vault внутри репо)

Если выбран вариант С (vault `mmorpg/vault/`):
```
vault/.obsidian/workspace*
vault/.obsidian/cache/
vault/.trash/
```

При выбранном варианте B (`C:\Projects\mmorpg-vault\` соседом) — `.gitignore` репо не трогаем; vault — отдельный git-репо со своим `.gitignore`.

### Новые файлы в корне репо

- **Никаких** `.mcp.json` / `.env` с `OBSIDIAN_API_KEY` / Claude-permissions для `mcp__obsidian__*` — мы это явно отбрасываем (см. базовое решение и ADR-009 в самом vault'е).
- Опционально: `.claude/settings.json` обновить только если решим добавить Claude-hooks (фаза 4, не сейчас).

---

## 9. Открытые вопросы и риски

### Открытые вопросы

1. **Vault соседом или внутри репо?**
   - **За соседом (`C:\Projects\mmorpg-vault\`):** не шумит в основном `git status`, отдельный git-цикл, легче переносить, не светится через laragon.
   - **За внутри (`mmorpg/vault/`):** один `git clone` восстанавливает всё, проще запомнить пути.
   - **Рекомендация:** соседом, в `C:\Projects\mmorpg-vault\` (не в `C:\laragon\www\` — чтобы laragon-сервер случайно не отдавал содержимое).

2. **Английские или русские имена нот?**
   - Имена файлов в коде — английские. Имена нот глоссария / lore — русские (Биом, Маяк-телепорта).
   - **Рекомендация:** **lore/glossary** на русском (термины из канона), **decisions** на английском (`ADR-001-stack-ci4-php-telegram`), **apps/tech-writing** на английском (повторяет имена в коде: `tech-writing/models/CharacterModel.md`).

3. **Куда переносить `GAME_DESCRIPTION.md`?**
   - Вариант А: оставить файл в репо как «слитную версию», в vault'е — атомарные ноты с wiki-links на разделы.
   - Вариант B: удалить из репо, оставить только в vault'е.
   - **Рекомендация:** **A.** Сохранить в репо (это точка входа для GitHub-аудитории и читается с GitHub UI без vault'а), в шапку добавить отсылку на атомарные ноты.

4. **Открывать ли vault в Obsidian-приложении?**
   - **Рекомендация:** **Да, но не обязательно.** Obsidian-приложение даёт удобство просмотра графа, поиска, редактирования — но не нужно для работы Claude. Можно начать без приложения, поставить позже.

5. **Нужны ли Templater / Dataview сразу или позже?**
   - **Рекомендация:** **позже (фаза 4).** В фазе 1-2 шаблоны — обычные markdown-файлы в `_templates/`, Claude умеет копировать их вручную. Templater даёт удобство только для разработчика, не для агента.

6. **Что с публичной аудиторией GitHub?**
   - Vault — соседний репо, на GitHub его можно опубликовать отдельно (`mmorpg-vault`) или хранить локально.
   - **Рекомендация:** в начале — локально (быстрее экспериментировать). Если ноты станут полезны для контрибьюторов — опубликуем как отдельный репо со ссылкой из `README.md`.

### Риски

🟠 **Vault drift.** Канон разрезали на ноты — потом обновили `GAME_DESCRIPTION.md` в репо, ноты забыли. **Митигация:** конституциональное правило в `CLAUDE.md` + чек-лист «завершённой задачи»; раз в неделю Claude делает diff `GAME_DESCRIPTION.md` vs `lore/` и репортит расхождения.

🟠 **CLAUDE.md drift.** Раздел «hot context» / `wiki/hot.md` — забываем обновлять. **Митигация:** ритуал «при `/clear` обновить hot.md» — добавить в `CLAUDE.md` как обязательный шаг агенту.

🟠 **Tech-writing-перегрузка.** 49 моделей × ~30 сервисов × ~80 action-handler'ов × ~70 task-handler'ов = ~230 нот. Если попытаться написать сразу всё в фазе 2 — за неделю не управиться, и большая часть будет read-once. **Митигация:** в фазе 2 — только `apps/<app>/index.md` (короткие таблицы) + `tech-writing/<категория>/index.md` (заглушка-список). Детальные ноты — **по мере правок кода** (правило конституции). Через 2-3 месяца естественно покроется ~30-40% сущностей — те, которые реально трогали.

🟠 **Дубль документации.** `lore/craft/Крафт-Workbench-General.md` vs `tech-writing/handlers/craft-completion-*` — оба про крафт. **Митигация:** в фазе 2 явно решить — `lore/` это «что и почему» (геймплей-перспектива), `tech-writing/` это «как реализовано» (код-перспектива). Ноты ссылаются друг на друга, не дублируют.

🟠 **Соблазн вкачивать всё в контекст.** Claude может проигнорировать selective-reading и тянуть весь vault. **Митигация:** в `CLAUDE.md` явно «НЕ вкачивать весь vault, читать адресно».

🟢 **Перегиб по объёму нот.** Создадим 200 нот за неделю, потом не поддерживаем. **Митигация:** фаза 2 ограничена ~80-100 атомарными нотами (lore + glossary + apps/index + ADR + snippets), всё остальное по мере появления реальной потребности.

🟢 **Token-расход не упадёт.** Рассчитываем что vault экономит, на практике — нет. **Митигация:** замерить — 5 типичных задач до vault'а vs после. Если экономии < 30% — пересмотреть стратегию (vault, возможно, слишком гранулирован или плохо организован).

🟢 **Conflict с публичностью репо.** Проект публичный (Wild World RPG release). Если vault содержит черновики, гипотезы, спорные решения — лучше держать его в приватном репо. **Митигация:** в начале — локально, без push на GitHub. Решим когда (и если) публиковать после фазы 3.

---

## 🚀 Что делаем прямо сейчас

**Если соглашаешься с планом** — делаем в этом порядке:

1. **Решение по открытым вопросам** (1-6 в секции 9): где живёт vault, имена нот, что делать с `GAME_DESCRIPTION.md`, открывать ли в Obsidian, нужны ли плагины сразу, публичный или локальный vault.
2. **Фаза 1 — Setup за час** (секция 6) — каркас vault'а + обновление `CLAUDE.md` + первый `wiki/hot.md`.
3. **Решение продолжать или нет** — если фаза 1 даёт ощущение «работает быстро и удобно» → фаза 2 (разрезание канона + apps-зеркало + первые ADR). Иначе откатываемся.

**Если есть правки к плану** — указывай, переписываем.

---

> **Парный документ:** `CLAUDE.md` — конституционные правила и стек проекта; `GAME_DESCRIPTION.md` — слитный канон геймплея.
>
> Этот файл — фильтр под Wild World с использованием подхода, отработанного в проекте-доноре M-2087 (Django/Python). Не статичен — обновляется по ходу миграции.
