# Wild World — Craft / Build / Resources Roadmap на 30 сессий

> **Версия документа:** v1.0 (2026-05-17)
> **Базовая линия:** v0.51.179, ~358 активных персонажей, develop @ commit 676b9d9
> **Направление:** crafting / building / resources
> **Срок исполнения:** 30 сессий (≈ v0.51.180 → v0.51.220)
> **Стиль документа:** roadmap-источник-правды, **не маркетинг**. Каждая сессия — самостоятельный work-item.

---

## §1 Преамбула

### 1.1 Цель документа

Этот файл — **тех-roadmap на 30 сессий** по направлению `crafting / building / resources` в Wild World. Документ построен под двух читателей:

1. **Андрей (solo-разработчик).** Должен открыть, выбрать следующую неначатую сессию (S-N), увидеть весь скоуп: канон-ссылку, список файлов, миграцию, картинки, smoke, тесты, прод-тег.
2. **Claude Code в новой сессии.** Открывает roadmap (по адресу `ROADMAP-CRAFT.md`) → читает §3 «Стратегическая карта» → находит активную фазу → выполняет одну сессию по её шаблону → коммитит → переходит к следующей.

Документ **самостоятельный** — кто-то, кто не видел репо, должен из него понять что и как делать. Реальные технические детали (модели, сервисы, БД-схемы) — через wiki-link на `mmorpg-vault/tech-writing/` и canon-link на `GAME_DESCRIPTION.md`.

### 1.2 Контракт с CLAUDE.md

Документ обязан не противоречить:

- **`CLAUDE.md`** — constitutional rule ADR-009 (tech-writing нота при каждом изменении).
- **`GAME_DESCRIPTION.md`** — слитный канон геймплея. Любая сессия, расходящаяся с каноном, помечается красным флагом и предлагает синхронное обновление канона.
- **`GAME_RULES_AND_VALIDATION_FRAMEWORK.md`** — 7 ворот валидации + 4 категории правил (🔴/🟠/🟡/🟢). Каждая сессия в §4–§9 проходит через сокращённую валидационную карточку (§11 хранит полные карточки).
- **`mmorpg-vault/decisions/ADR-009`** — sync tech-writing.
- **`mmorpg-vault/decisions/ADR-017`** — фреймворк валидации (карточка идеи).
- **`mmorpg-vault/decisions/ADR-022`** — image style (Найденная фотоплёнка).

### 1.3 Текущая базовая линия

| Метрика | Значение | Источник |
|---|---|---|
| Тег прод | v0.51.179 | git tag |
| Активных персов | ~358 | prod DB |
| Рецептов крафта | 46 | `app/Config/CraftRecipes.php` (963 LOC) |
| Зданий | 12 | `app/Config/Buildings.php` (404 LOC; **все 12 уже мигрированы на GenericBuildingAction** — F2.1 закрыта; legacy `Build*Construction.php` ждут удаления-cleanup) |
| Биомов | 9 | `BiomeModel` (danger 3–10) |
| Ресурсов | ~70 в БД | `resources` таблица |
| Действующих фракций | 4 (+ нейтралы) | `Config\EndgameScoring` |
| Apply'нутых миграций | актуально на `2026-05-14-100000` | `app/Database/Migrations/` |
| phpstan level | 9, baseline ~4910 | `phpstan.neon.dist` |
| Тестов | ~4434 | `composer test` |

### 1.4 Шесть фаз

| Фаза | Сессии | Фокус | Прод-теги | Файл §  |
|---|---|---|---|---|
| **Фаза 1 — Tech foundation** | S1–S5 | required_resources JSON, building upgrade wiring, durability decay, repair | v0.51.180 → v0.51.184 | §4 |
| **Фаза 2 — Resource expansion** | S6–S10 | missing resources, biome↔resource mapping, english ingredient cleanup, price cron, rare resources | v0.51.185 → v0.51.189 | §5 |
| **Фаза 3 — Building upgrades live** | S11–S15 | Workshop / BlastFurnace / Lab+Greenhouse / Robotics+robots T2-T3 / TeleportCenter L2-L3 | v0.51.190 → v0.51.194 | §6 |
| **Фаза 4 — Craft depth Tier 3** | S16–S20 | T3 Workbench ADR, weapons, armor, medical, utility | v0.51.195 → v0.51.199 | §7 |
| **Фаза 5 — Endgame faction content** | S21–S25 | Bunker / Technopark / GhostCity / Island-Farm + 4 faction-unique weapons | v0.51.200 → v0.51.204 | §8 |
| **Фаза 6 — Polish + ROADMAP-vNext** | S26–S30 | defensive structures, queue UI, seasonal rotation, content-pass, admin tools | v0.51.205 → v0.51.209 (резерв до v0.51.220) | §9 |

### 1.5 Метрики успеха

- **LOC delta.** Цель — net **−1500 LOC** по итогам 30 сессий за счёт удаления legacy `Build*Construction.php` (F2.1 cleanup), `WorkbenchGeneral/Components/*`, дубль-handler'ов; +600 LOC новых рецептов/конфигов.
- **+Tests.** Не менее **+150 unit-тестов** за 30 сессий (5 на сессию в среднем; больше — на S2/S4/S5/S16/S21).
- **phpstan baseline.** Не регрессит ни на одной сессии. Цель — −300 от текущего baseline 4910 (через явные type-аннотации новых конфигов).
- **Прод-теги.** 30 минорных тегов v0.51.180 → v0.51.209 (резерв до v0.51.220 если будут хотфиксы).
- **Активных игроков.** Не падает ниже 350 (метрика «не сломали ничего критичного»).
- **Анонсов в чате.** 5–7 крупных анонсов (после S2 контентный re-balance, после S6 новые ресурсы, после S11/12/13/14/15 видимые улучшения зданий, после S16 T3 верстак, после S21–S25 эндгейм-объекты, после S30 ROADMAP-vNext).

### 1.6 Параллелизация — ⚡

Сессии, помеченные **⚡**, могут идти параллельно с предыдущей или одновременно с другой:

- S13 (Lab + Greenhouse L2/L3) — ⚡ параллель с S12 (BlastFurnace) — независимые цепочки построек.
- S18 (T3 armor) — ⚡ параллель с S17 (T3 weapons) — общий T3 верстак уже стоит к этому моменту.
- S19 (T3 medical) — ⚡ параллель с S20 (T3 utility) — тот же T3 верстак.
- S22 (Technopark) — ⚡ параллель с S23 (GhostCity) — независимые building+quest пары.
- S24 (Island-Farm) — ⚡ параллель с S25 (faction weapons) — последний не зависит от S24.

Параллелизация = «можно делать в одной сессии Claude Code, релизить раздельными тегами». Не путать с «git branch». Develop остаётся линейным; параллель — это **планирование**, не VCS.

### 1.7 Как читать сессию

Каждая сессия в §4–§9 содержит **17 секций** (Цель / Канон / Боль / Меняем / Создать / Изменить / Миграции / Image-assets / Validation-card mini / Smoke / Tests / Tech-writing / Прод-тег / Параллель / Зависимости / Анонс / Замечания). Полные validation-cards (по 7 воротам каждой) собраны в §11. Image-budget и LEXICON-планы — в §10.

### 1.8 «Что значит закрытая сессия»

По CLAUDE.md §«Что считается завершённой задачей»:

- ✅ Идея прошла фреймворк валидации (карточка в §11)
- ✅ Код написан, PHPUnit зелёный (`composer test`)
- ✅ Миграция применена и smoke-протестирована
- ✅ Документация в репо обновлена (если задели лор/процесс)
- ✅ Tech-writing нота в vault обновлена/создана
- ✅ Если значимое решение — ADR в `mmorpg-vault/decisions/`
- ✅ `mmorpg-vault/wiki/hot.md` обновлён
- ✅ Если новая картинка — LEXICON-запись + строка в `Config\ImageRegistry` + сгенерённая картинка (`php spark images:generate`)
- ✅ Прод-тег выкачен, smoke на проде зелёный
- ✅ Анонс игрокам (если применимо)

---

## §2 Constitutional rules (выжимка для каждой сессии)

### 2.1 7 ворот валидации (обзор)

```
0 Формулировка → 1 Канон → 2 10-персон → 3 Баланс/системы → 4 Техно-чек → 5 Smoke-план → 6 Релиз+vault
```

Каждая сессия проходит ворота **в указанном порядке**. Провал ворот = стоп до устранения. Мелкие задачи проскакивают за минуты; крупные — за несколько итераций.

### 2.2 4 категории правил

| Категория | Что значит | Действие |
|---|---|---|
| 🔴 СТРОГО ЗАПРЕЩЕНО | Стоп. Исключений нет. Если "очень надо" — мета-обсуждение смены философии. | Сессия не стартует. Если уже стартовали — откат. |
| 🟠 ЗАПРЕЩЕНО, но могут быть исключения | "Да" только с обоснованием, расчётом, ADR, (если видимо) анонсом. | Создаётся ADR в `mmorpg-vault/decisions/`, validation-card полная. |
| 🟡 РАЗРЕШЕНО, но подумать | "Да" после чек-листа категории. Без ADR, но через все ворота + tech-writing. | Стандартный workflow. |
| 🟢 ВСЕГДА НУЖНО | Гигиенический минимум **каждой** задачи. | Невыполнение = задача не закрыта. |

### 2.3 Smoke plans по типу изменения

| Тип | Обязательный smoke |
|---|---|
| Cutover / action-side ↔ handler-side | **Telegram smoke** на testbot (unit-тесты НЕ ловят desync — урок F2.2 Bandage prod-bug) |
| View / рендеринг | **HTTP smoke** `curl <route>` (phpstan + unit НЕ покрывают runtime view — урок F5 saga) |
| Background task | `php spark tasks:run` на testbot + проверка идемпотентности (двойной запуск без двойного эффекта) |
| Migration (контент-строка) | UPDATE + reread, повторный прогон = no-op (idempotent) |
| Migration (структурная) | `migrate` + `migrate:rollback` + `migrate` (round-trip) |

### 2.4 Stepwise release pattern

```
develop (feature work)
  ↓ commit
  ↓ tag v0.51.X-step1
  ↓ rsync → testbot.wildworld.fun
  ↓ smoke ✅
  ↓ cherry-pick → master
  ↓ tag v0.51.X-step1 на master
  ↓ rsync → prod
  ↓ smoke на проде ✅
  ↓ 12–24h pause (наблюдение в логах)
  ↓ next step ↗ повторить
  ↓ финал-анонс если видимо
```

### 2.5 Шаблон validation-card (для копипаста)

```markdown
## Идея: <one-liner>

**Тип:** content | refactor | balance | fix | infra
**Подсистемы:** crafting | building | resources | (другие)
**Источник:** ROADMAP-CRAFT.md §X S-N

### Ворота 0 — Формулировка
- Проблема:
- Что меняем:

### Ворота 1 — Канон & сеттинг
- GAME_DESCRIPTION conflict? [нет / да → diff]
- Постапок-сеттинг сохранён? [да]
- Тексты говорят правду? [да]

### Ворота 2 — 10-персон чек
| Персона | Голос | Комментарий |
|---|---|---|
| П1 Хардкорщик | | |
| П2 Казуал | | |
| П3 Лорщик | | |
| П4 PvP-хищник | | |
| П5 Билдер | | |
| П6 Социальщик | | |
| П7 Завершенец | | |
| П8 Экономист | | |
| П9 Анти-P2W | | |
| П10 Новичок | | |

### Ворота 3 — Баланс & системы
- Экономика:
- Прогрессия:
- Бой:
- Тайм-гейты:
- Крафт/базы:
- Фракции/эндгейм:
- Оффлайн-безопасность:

### Ворота 4 — Техно-чек
- Миграции:
- Тесты:
- phpstan:
- Идемпотентность:
- Tech-writing нота:

### Ворота 5 — Smoke-план
- Тип → smoke:
- Шаги:

### Ворота 6 — Релиз & vault
- Stepwise шаги:
- Commit message:
- Доки:
- ADR нужен?:
- hot.md / daily:
- Анонс:

### ИТОГ: категория [ 🔴 / 🟠 / 🟡 / 🟢 ]
```

---

## §3 Стратегическая карта 30 сессий

### 3.1 Граф зависимостей (ASCII)

```
                     ┌──────── ФАЗА 1: TECH FOUNDATION ────────┐
                     │                                          │
        S1 (cleanup legacy Build*) ─────────┐                   │
                                            │                   │
        S2 (required_resources → JSON) ─────┼─── precondition for
                                            │   S11–S15 upgrades
        S3 (building level wire) ───────────┤
                                            │
        S4 (durability decay) ──────────────┤
                                            │
        S5 (repair system) ─────────────────┘
                     │
                     ▼
                     ┌──────── ФАЗА 2: RESOURCE EXPANSION ─────┐
                     │                                          │
        S6 (Ironstone/Oil/Sulfur/RareMetals/Coal) ──── precondition for
                                              │       S11+ (refers existing)
        S7 (biome↔resource mapping) ──────────┤
                                              │
        S8 (english ingredient cleanup) ──────┤
                                              │
        S9 (price cron) ──────────────────────┤
                                              │
        S10 (rare late-game resources) ───────┘
                     │
                     ▼
                     ┌──────── ФАЗА 3: BUILDING UPGRADES ──────┐
                     │                                          │
        S11 (Workshop L2/L3) ───────────────┐
                                            │
        S12 (BlastFurnace L2/L3) ───────────┤
                          ⚡                 │
        S13 (Lab+Greenhouse L2/L3) ─────────┤
                                            │
        S14 (Robotics L2/L3 + robot T2/T3) ─┤
                                            │
        S15 (TeleportCenter L2/L3 + beacons)┘
                     │
                     ▼
                     ┌──────── ФАЗА 4: T3 CRAFT DEPTH ─────────┐
                     │                                          │
        S16 (T3 Workbench + ADR-024) ───────┐
                                            │
        S17 (T3 weapons ×5) ────────────────┤
                          ⚡                 │
        S18 (T3 armor ×4) ──────────────────┤
                          ⚡                 │
        S19 (T3 medical ×3) ────────────────┤
                          ⚡                 │
        S20 (T3 utility ×3) ────────────────┘
                     │
                     ▼
                     ┌──────── ФАЗА 5: ENDGAME FACTION ────────┐
                     │                                          │
        S21 (Bunker + Military quest + ADR-025) ─┐
                          ⚡                       │
        S22 (Technopark + Engineers quest) ──────┤
                          ⚡                       │
        S23 (GhostCity + Partisans quest) ───────┤
                          ⚡                       │
        S24 (Island-Farm + Farmers quest) ───────┤
                          ⚡                       │
        S25 (4 faction-unique weapons) ──────────┘
                     │
                     ▼
                     ┌──────── ФАЗА 6: POLISH + vNEXT ─────────┐
                     │                                          │
        S26 (defensive structures) ─────────┐
                                            │
        S27 (craft queue UI) ───────────────┤
                                            │
        S28 (seasonal craft rotation) ──────┤
                                            │
        S29 (content-pass lying descr.) ────┤
                                            │
        S30 (admin tools + ROADMAP-vNext) ──┘
```

### 3.2 Прод-теги план

| Сессия | Тег | Заметка |
|---|---|---|
| S1 | v0.51.180 | F2.1 cleanup — удаление legacy `Build*Construction.php` (8–11 файлов, ~−4200 LOC) |
| S2 | v0.51.181 | required_resources JSON migration |
| S3 | v0.51.182 | building.level wired to UI |
| S4 | v0.51.183 | durability decay handler |
| S5 | v0.51.184 | repair system |
| S6 | v0.51.185 | +5 resources (Ironstone, Oil, Sulfur, RareMetals, Coal) |
| S7 | v0.51.186 | biome→resource explicit mapping config |
| S8 | v0.51.187 | English ingredient cleanup |
| S9 | v0.51.188 | price update cron |
| S10 | v0.51.189 | rare resources (Spent Fuel Rods / Pre-Collapse Electronics / etc) |
| S11 | v0.51.190 | Workshop L2/L3 |
| S12 | v0.51.191 | BlastFurnace L2/L3 |
| S13 | v0.51.192 | Lab + Greenhouse L2/L3 |
| S14 | v0.51.193 | Robotics + robot T2/T3 |
| S15 | v0.51.194 | TeleportCenter + beacon network |
| S16 | v0.51.195 | T3 Workbench Professional (ADR-024) |
| S17 | v0.51.196 | T3 weapons (5 recipes) |
| S18 | v0.51.197 | T3 armor (4 recipes) |
| S19 | v0.51.198 | T3 medical (3 recipes, incl. synthetic-medicine) |
| S20 | v0.51.199 | T3 utility (3 recipes incl. combination tools) |
| S21 | v0.51.200 | Bunker + Military endgame quest + ADR-025 |
| S22 | v0.51.201 | Technopark + Engineers quest |
| S23 | v0.51.202 | GhostCity + Partisans quest |
| S24 | v0.51.203 | Island-Farm + Farmers quest |
| S25 | v0.51.204 | 4 faction-unique weapons |
| S26 | v0.51.205 | Defensive structures (walls/traps/towers) |
| S27 | v0.51.206 | Craft queue UI visualization |
| S28 | v0.51.207 | Seasonal craft rotation |
| S29 | v0.51.208 | Content-pass + lying descriptions cleanup |
| S30 | v0.51.209 | Admin tools + tech-writing back-fill + ROADMAP-vNext draft |

Резерв v0.51.210–v0.51.220 — хотфиксы (twin-hotfix grep, view smoke catches, FK shtorm fixes).

---

## §4 Фаза 1 (S1–S5): Tech Foundation

### S1 — Cleanup legacy `Build*Construction.php` 🟡

**Цель.** Удалить ~11 уже-замещённых legacy action-handler'ов `Build*Construction.php` после подтверждения, что `GenericBuildingAction` покрывает все 12 зданий.

**Канон-ссылка.** `GAME_DESCRIPTION.md` § «🏗️ Строительство баз» (контракт зданий не меняется); `mmorpg-vault/lore/base/Постройки.md`; `mmorpg-vault/decisions/` (искать ADR про F2.1).

**Текущая боль.** `app/Config/Buildings.php` уже содержит все 12 зданий (Arsenal, Workshop, BlastFurnace, Warehouse, Laboratory, SolarStation, Gym, Greenhouse, HandPump, RoboticsWorkshop, TeleportationCenter, CommunicationTower) — но legacy `app/Controllers/Telegram/Commands/Actions/Camp/Build*Construction.php` (8–11 файлов, ~4187 LOC) до сих пор лежат рядом. Дублируют логику, путают grep, не покрыты тестами рядом с generic.

**Что меняем.**
- Grep `genericStartBuild_` в `CallbackqueryCommand` — убедиться, что все 12 зданий маршрутятся через generic.
- Grep `class StartBuild*Construction` — список файлов на удаление.
- Удалить файлы, callback-routes (legacy), упоминания в `CallbackRouter`.

**Файлы к созданию.** Нет.

**Файлы к изменению/удалению.**
- `app/Controllers/Telegram/Commands/Actions/Camp/StartBuildArsenal*.php` — удалить
- `app/Controllers/Telegram/Commands/Actions/Camp/StartBuildWorkshopConstruction.php` — удалить
- `app/Controllers/Telegram/Commands/Actions/Camp/StartBuildLabConstruction.php` — удалить
- (полный список — после `grep "class StartBuild"` в S1 пред-этапе)
- `app/Controllers/Telegram/CallbackRouter.php` — удалить legacy routes
- `tests/unit/Actions/Camp/` — удалить тесты legacy (если есть) или мигрировать на generic

**Миграции.** Нет (cleanup кода без структурных изменений БД).

**Image-assets.** Нет.

**Validation-card mini.**
- Категория: 🟡 (рефактор с 0 behavior change)
- 10-персон: все нейтрально/+; П5 Билдер + (меньше шума в кодовой базе); никто не предан
- Smoke: Telegram smoke на каждое из 12 зданий — попытка построить (cancel сразу после старта = достаточно, проверяем callback wiring)
- ADR: не нужен (выполнение уже принятого ADR F2.1)

**Smoke plan.**
1. `php spark migrate:status` на testbot — все миграции applied.
2. Telegram smoke: «База → Строительство → каждое здание» × 12, нажать «Построить» (callback `genericStartBuild_<Key>` должен ответить корректным сообщением).
3. Для одного здания (Workshop) — довести до конца, дождаться completion-handler'а.
4. Проверить, что legacy callback `startBuild_Arsenal_old` (если был) возвращает 404 / no handler.

**Tests добавить.**
- Нет новых; убедиться, что `tests/unit/Actions/Camp/Building/GenericBuildingActionTest.php` (если есть) покрывает все 12 ключей через data provider.

**Tech-writing.**
- `mmorpg-vault/tech-writing/handlers/camp/GenericBuildingAction.md` — обновить `last_reviewed`, добавить запись "F2.1 cleanup завершён".
- `mmorpg-vault/apps/buildings/index.md` — снять отметку «legacy `Build*Construction.php` ждут удаления».

**Прод-тег.** `v0.51.180`.

**Параллелизация.** Можно с S2, если файлы не пересекаются (S2 трогает БД-схему).

**Зависимости.** Нет (предыдущая работа F2.1 уже выполнена).

**Анонс.** Не нужен (internal refactor).

**Замечания.** Перед удалением каждого файла — `grep -r "<ClassName>" app/ tests/` чтобы не оставить мёртвых ссылок. Стандартный twin-grep с aliases (`StartBuild`, `BuildArsenal`, `arsenalConstruction`).

---

### S2 — `crafted_items.required_resources` free-text → JSON 🟠

**Цель.** Перевести колонку `crafted_items.required_resources` из 3-форматного free-text в строгий JSON. Это **самый большой техдолг** crafting-системы и precondition для S16 (T3 верстак) и S22 (ввод новых typed-рецептов).

**Канон-ссылка.** `GAME_DESCRIPTION.md` § «🔨 Система крафта»; `mmorpg-vault/lore/craft/`.

**Текущая боль.**
- Поле хранится в трёх несовместимых форматах:
  1. **Free-text v1.** `"Травы - 2 шт., Кора деревьев - 2 шт."` — парсится регуляркой.
  2. **PHP-array-like v2.** `"['Травы' => 2, 'Кора деревьев' => 2]"` — `eval()`-парсинг (😱).
  3. **JSON v3 (часть buildings).** `{"Травы": 2}` — уже корректно.
- Дублирующий source-of-truth: `CraftRecipes.php` хранит то же самое в `resources`, `crafted_items` — но сама БД-таблица `crafted_items.required_resources` лежит в legacy формате.

**Что меняем.**
- Миграция: ALTER колонки тип не нужен (varchar TEXT остаётся), но **idempotent UPDATE** конвертирует все строки в JSON v3.
- Сервис: `CraftingService::parseRequiredResources()` → одна реализация (`json_decode` + fallback warning в лог при corrupt).
- Все callers старой регулярки (~5 мест) — снять.

**Файлы к созданию.**
- `app/Services/Crafting/RequiredResourcesParser.php` — единая точка парсинга
- `tests/unit/Services/Crafting/RequiredResourcesParserTest.php`

**Файлы к изменению.**
- `app/Models/CraftedItemsModel.php` — `parseRequiredResources()` метод
- `app/Services/CraftService.php` — заменить регулярки на парсер
- `app/Services/Player/CraftingValidator.php` — adopt парсер
- `app/Controllers/Admin/CraftedItemsController.php` — admin UI хранит JSON

**Миграции.**
```
app/Database/Migrations/2026-05-18-100000_NormalizeCraftedItemsRequiredResourcesToJson.php
```
**up:**
```php
public function up(): void
{
    $rows = $this->db->table('crafted_items')->select('id, required_resources')->get()->getResultArray();
    foreach ($rows as $row) {
        $raw = trim($row['required_resources'] ?? '');
        if ($raw === '' || $raw === '[]') {
            continue;
        }
        $json = $this->tryParseToJson($raw);
        if ($json === null) {
            log_message('warning', 'crafted_items #' . $row['id'] . ' required_resources unparseable: ' . $raw);
            continue;
        }
        if ($json === $raw) {
            continue; // already JSON
        }
        $this->db->table('crafted_items')->where('id', $row['id'])->update(['required_resources' => $json]);
    }
}

private function tryParseToJson(string $raw): ?string
{
    // 1. Already valid JSON
    json_decode($raw, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        return $raw;
    }
    // 2. Free-text v1: "Травы - 2 шт., Кора - 3 шт."
    if (preg_match_all('/([\p{L}\s]+?)\s*-\s*(\d+)\s*шт/u', $raw, $m)) {
        $out = [];
        foreach ($m[1] as $i => $name) {
            $out[trim($name)] = (int) $m[2][$i];
        }
        return json_encode($out, JSON_UNESCAPED_UNICODE);
    }
    // 3. PHP-array-like v2: "['Травы' => 2]"
    if (preg_match_all('/[\'"]([^\'"]+)[\'"]\s*=>\s*(\d+)/u', $raw, $m)) {
        $out = [];
        foreach ($m[1] as $i => $name) {
            $out[$name] = (int) $m[2][$i];
        }
        return json_encode($out, JSON_UNESCAPED_UNICODE);
    }
    return null;
}
```
**down:**
```php
public function down(): void
{
    // No-op: we cannot reliably reverse v1/v2 from JSON, and idempotent re-runs of up() are safe.
}
```

**Image-assets.** Нет.

**Validation-card mini.**
- Категория: 🟠 (ретро-миграция данных, требует ADR — `ADR-024-Crafted-items-required-resources-JSON.md`)
- 10-персон: П3 + (single source of truth), П7 + (backwards-compat — данные сохранены), П5 + (предсказуемые парсинги), П8 + (admin может править прозрачно)
- Без анонса (internal)
- Smoke: на testbot прогон, прогон с UPDATE + reread, double-run должен быть no-op

**Smoke plan.**
1. `php spark migrate` на testbot.
2. SELECT всех `required_resources` где `JSON_VALID(required_resources) = 0` — должно быть 0 (или warning'и в логе с conscious skip).
3. Создать через admin крафт с ru-resources → должен прочитаться корректно.
4. Запустить рецепт в Telegram («Аптечка») → completion-handler читает JSON → ресурсы списываются.
5. `php spark migrate` повторно → 0 rows updated (idempotent).

**Tests добавить.**
- `RequiredResourcesParserTest::testParseV1FreeText()` — `"Травы - 2 шт., Кора - 3 шт."`
- `RequiredResourcesParserTest::testParseV2PhpArray()` — `"['Травы' => 2]"`
- `RequiredResourcesParserTest::testParseV3Json()` — passthrough
- `RequiredResourcesParserTest::testCorruptInput()` — returns null + warning
- `RequiredResourcesParserTest::testEmptyInput()` — returns empty array

**Tech-writing.**
- `mmorpg-vault/tech-writing/services/RequiredResourcesParser.md` — НОВАЯ нота
- `mmorpg-vault/tech-writing/models/CraftedItemsModel.md` — обновить (упоминание JSON-формата)
- `mmorpg-vault/decisions/ADR-024-Crafted-items-required-resources-JSON.md` — НОВЫЙ ADR

**Прод-тег.** `v0.51.181`.

**Параллелизация.** Можно с S1 (разные файлы).

**Зависимости.** Нет.

**Анонс.** Не нужен (admin-видимая правка).

**Замечания.** Twin-hotfix grep: `required_resources` встречается в ~15 файлах. Все callers — через парсер.

---

### S3 — Building.level wiring → UI 🟡

**Цель.** Связать существующий `BuildingUpgrades.php` (config для L2–L10) с фактическим `character_buildings.level` — чтобы апгрейды S11–S15 могли работать.

**Канон-ссылка.** `GAME_DESCRIPTION.md` § «🏗️ Строительство баз»; `mmorpg-vault/lore/base/Постройки.md`.

**Текущая боль.**
- `BuildingUpgrades.php` (109 LOC) — config с ценами L2/L3 уровней для каждого здания **существует**, но не wired в UI.
- `character_buildings.level` — колонка есть, по умолчанию 1, никогда не инкрементируется.
- `BuildingFactionMap` смотрит на `level` → даёт +200 endgame очков **за апгрейд**, но триггер не срабатывает (некому инкрементить).

**Что меняем.**
- Action-handler `UpgradeBuildingAction` для показа диалога апгрейда.
- Task-handler `BuildingUpgradeCompletionHandler` (extends `BaseTaskHandler`).
- View: показывать текущий уровень здания в `DetailedBaseInfoAction`.
- Hooked endgame-scoring +200 на каждый успешный апгрейд (Building→Faction map уже есть).

**Файлы к созданию.**
- `app/Controllers/Telegram/Commands/Actions/Camp/Buildings/Upgrades/UpgradeBuildingAction.php`
- `app/TaskHandlers/Built/BuildingUpgradeCompletionHandler.php`
- `tests/unit/TaskHandlers/Built/BuildingUpgradeCompletionHandlerTest.php`

**Файлы к изменению.**
- `app/Controllers/Telegram/Commands/Actions/Camp/DetailedBaseInfoAction.php` — показать `level`
- `app/Config/Tasks.php` — зарегистрировать `buildingUpgrade` task
- `app/Controllers/Telegram/CallbackRouter.php` — `upgradeBuilding_<Key>` callback
- `app/Services/Endgame/EndgameProgressionService.php` — вызов `recordBuildingUpgrade()` (если ещё нет)

**Миграции.**
```
app/Database/Migrations/2026-05-19-100000_AddBuildingUpgradeTaskRow.php
```
Inserts `tasks` row для `buildingUpgrade` (duration 30 min для L2, 60 min для L3 — конкретные значения в `BuildingUpgrades.php`).

**Image-assets.** Нет (на этой сессии — общая инфра; картинки L2/L3 — в S11–S15 per building).

**Validation-card mini.**
- Категория: 🟡 (контент в существующих системах, есть BuildingUpgrades config)
- 10-персон: П5 + (deep building progression), П7 + (новая видимая прогрессия), П6 + (endgame очки за апгрейд → faction race), П2 0 (long task, оффлайн-дружественно)
- Smoke: cutover-обязателен (Telegram smoke); idempotency task-handler'а

**Smoke plan.**
1. testbot: персонажа Андрея довести до L1 Workshop.
2. «База → Workshop → ⬆️ Улучшить» → подтвердить → задача создалась.
3. Дождаться completion (или UPDATE end_time на testbot).
4. `php spark tasks:run` → completion срабатывает → `character_buildings.level = 2`.
5. Повторный `tasks:run` без новой задачи — no-op.
6. Endgame score проверить: `+200` в `faction_endgame_scores` под `farming` (или фракцию здания).

**Tests добавить.**
- `BuildingUpgradeCompletionHandlerTest::testIdempotentCompletion()`
- `BuildingUpgradeCompletionHandlerTest::testLevelIncrement()`
- `BuildingUpgradeCompletionHandlerTest::testEndgameScoreRecorded()`
- `UpgradeBuildingActionTest::testResourcesValidated()`
- `UpgradeBuildingActionTest::testLevelCapL3()` (пока only L2/L3 wired)

**Tech-writing.**
- `mmorpg-vault/tech-writing/handlers/camp/UpgradeBuildingAction.md` — НОВАЯ
- `mmorpg-vault/tech-writing/tasks/built/BuildingUpgradeCompletionHandler.md` — НОВАЯ
- `mmorpg-vault/tech-writing/config/BuildingUpgrades.md` — обновить (snimat «config есть, UI не работает»)

**Прод-тег.** `v0.51.182`.

**Параллелизация.** После S2 (нужен JSON parser для resources в upgrade-cost).

**Зависимости.** S2 (required_resources JSON).

**Анонс.** Да — «Теперь здания можно улучшать! Workshop L2 → +X% эффективности крафта». Драфт: см. §11 S3.

**Замечания.** L4–L10 в `BuildingUpgrades.php` остаются для будущих сессий (S26+). На этой сессии — только L2 и L3 wiring.

---

### S4 — Durability decay handler 🟡

**Цель.** Реализовать handler, декрементирующий `crafted_items.durability_count` при использовании инструмента в gather-задаче. Сейчас колонка `durability_count` существует, но dead — никто не пишет.

**Канон-ссылка.** `GAME_DESCRIPTION.md` § «⛏️ Добыча ресурсов» — «Система прочности — инструменты изнашиваются»; `mmorpg-vault/glossary/Прочность-инструмента.md`.

**Текущая боль.**
- Канон обещает: «инструменты изнашиваются при использовании».
- Реальность: `durability_count` существует, но handler декремента отсутствует. Инструменты вечны. **Lying description** (🔴 категория) → должны починить либо механику, либо канон.
- Решение: реализовать (фикс) — это уже в каноне, ожидаемо игроками.

**Что меняем.**
- Сервис `DurabilityService::decrement(int $characterCraftedItemId, int $amount = 1): void`
- Hook в `gather`/`fish`/`chop` completion-handler'ы — вызов decrement(1) на каждом задействованном инструменте.
- При `durability_count <= 0` — `is_broken = 1`, инструмент не даёт бонус, Telegram-уведомление.

**Файлы к созданию.**
- `app/Services/Crafting/DurabilityService.php`
- `tests/unit/Services/Crafting/DurabilityServiceTest.php`

**Файлы к изменению.**
- `app/TaskHandlers/CompleteGatherResourceHandler.php` — call DurabilityService
- `app/TaskHandlers/CompleteFishingHandler.php` — same
- `app/TaskHandlers/CompleteChopHandler.php` — same
- `app/Services/Player/ToolBonusService.php` — отфильтровать `is_broken = 1`
- `app/Models/CraftedItemsModel.php` — `markBroken()` метод

**Миграции.** Нет (колонки `durability_count`, `is_broken` уже есть).

**Image-assets.**
- LEXICON entry уже есть (`loot.tool`).
- Для «сломанный инструмент» уведомления — добавить LEXICON ключ `loot.tool-broken`:
  - **`tool.broken`** (`uploads/telegram/craft/tool_broken.jpg`): scene-tail: "the same hand-forged tool now visibly broken — the head snapped off a corded handle, the haft split, on a workbench amid wood shavings". Mode: V4. Price: $0.04. Status: pending.

**Validation-card mini.**
- Категория: 🟡 (fix: канон обещал, не было реализовано) — но трогает кор-добычу, поэтому близко к 🟠
- 10-персон: П1 + (риск/реворд), П3 + (текст не врёт), П5 0 (новая зависимость в цепочке), П2 − (наказание за активность) — нужна митигация: декремент **только на successful gather**, при поломке — Telegram-уведомление за 24h до этого
- П7 ✗ предательство? Нет — durability_count уже хранится в БД, игроки видят его в инвентаре.

**Smoke plan.**
1. Testbot: дать персонажу `IronPickaxe` с `durability_count = 3`.
2. Запустить 3 gather-задачи на камень.
3. После 3-й — `is_broken = 1`, Telegram-уведомление получено.
4. 4-я gather-задача — bonus не применяется (durability check).
5. Idempotency: повторный tasks:run не декрементит дважды (флаг `decrement_applied` в task_settings).

**Tests добавить.**
- `DurabilityServiceTest::testDecrementHappyPath()`
- `DurabilityServiceTest::testBreaksAtZero()`
- `DurabilityServiceTest::testBrokenItemNoDecrement()`
- `DurabilityServiceTest::testIdempotentDecrement()` — двойной вызов = одно списание

**Tech-writing.**
- `mmorpg-vault/tech-writing/services/DurabilityService.md` — НОВАЯ
- `mmorpg-vault/glossary/Прочность-инструмента.md` — обновить (status: implemented)
- `GAME_DESCRIPTION.md` — таблица расхождений: убрать запись «durability dead column» если была

**Прод-тег.** `v0.51.183`.

**Параллелизация.** Зависит от S2 (через models).

**Зависимости.** S2.

**Анонс.** Да — «Инструменты теперь действительно изнашиваются — следите за прочностью в инвентаре». Драфт: см. §11 S4.

**Замечания.** Балансовый расчёт: средняя добыча 10 единиц/инструмент/час = 240/день. С durability=100 инструмент живёт ~10ч активной добычи. Это «расход ресурсов на крафт инструментов» → S5 repair сделает альтернативу.

---

### S5 — Repair system 🟡

**Цель.** Дать игрокам возможность ремонтировать сломанные инструменты за часть от изначальных ресурсов (50%). Закрывает цепочку S4 (decay → repair) и сокращает «инструмент сломался — крафти заново».

**Канон-ссылка.** Нет прямого упоминания в GAME_DESCRIPTION; **lore tail #N открыт** — предлагается дополнить канон.

**Текущая боль.**
- После S4 инструменты ломаются.
- Без repair — единственный путь — крафт нового (полная цена).
- Игрок ощущает «крафт-конвейер тяжёлый» (П5 голос негатив).

**Что меняем.**
- Action-handler `RepairCraftedItemAction` — выбор сломанного инструмента → подтверждение → задача `repair` 15 min.
- Стоимость ремонта: 50% от `required_resources` (округление вверх).
- Completion-handler `RepairCompletionHandler` — восстанавливает `durability_count` к 100%, `is_broken = 0`.

**Файлы к созданию.**
- `app/Controllers/Telegram/Commands/Actions/Crafting/RepairCraftedItemAction.php`
- `app/TaskHandlers/Craft/RepairCompletionHandler.php`
- `tests/unit/Controllers/Telegram/Actions/Crafting/RepairCraftedItemActionTest.php`
- `tests/unit/TaskHandlers/Craft/RepairCompletionHandlerTest.php`

**Файлы к изменению.**
- `app/Controllers/Telegram/Commands/Actions/CraftedResourcesAction.php` — добавить кнопку «🔧 Ремонт» при показе сломанного инструмента
- `app/Config/Tasks.php` — `repairCraftedItem` task (duration 15 min, name_rus «Ремонт»)
- `app/Controllers/Telegram/CallbackRouter.php`

**Миграции.**
```
app/Database/Migrations/2026-05-21-100000_AddRepairTaskRow.php
```

**Image-assets.**
- **`craft.repair-in-progress`** (`uploads/telegram/craft/repair_in_progress.jpg`): LEXICON-key `loot.tool` + scene-tail: "a survivor's hands at a workbench bending steel back into shape on the broken tool — a vice, a hammer, ash and metal shavings on the bench; the tool partly disassembled, mid-fix". Mode: V4. Price: $0.04. Status: pending.
- **`craft.repair-done`** (`uploads/telegram/craft/repair_done.jpg`): LEXICON-key `loot.tool` + scene-tail: "the same hand-forged tool re-assembled on the workbench, the handle re-corded, the head re-seated — clearly mended, slightly different from new, ready to use". Mode: V4. Price: $0.04. Status: pending.

**Validation-card mini.**
- Категория: 🟡
- 10-персон: П1 + (extends gear lifecycle), П5 ++ (deep cycle), П2 + (cheaper than re-craft), П8 0 (sink осталась, цена 50%)
- Lore-tail: канон надо допилить — `GAME_DESCRIPTION.md` § «Система прочности» — добавить абзац про repair

**Smoke plan.**
1. Testbot: сломать `IronPickaxe` (durability=0).
2. «Крафт → Сломанные инструменты → Ремонт» → подтвердить.
3. Дождаться 15 мин (или UPDATE end_time).
4. `tasks:run` → durability=100, is_broken=0.
5. Idempotent: повторный run = no-op.

**Tests добавить.**
- `RepairCompletionHandlerTest::testRestoresDurability()`
- `RepairCompletionHandlerTest::testIdempotent()`
- `RepairCraftedItemActionTest::testRejectsIfNotBroken()`
- `RepairCraftedItemActionTest::testRejectsInsufficientResources()`

**Tech-writing.**
- `mmorpg-vault/tech-writing/handlers/crafting/RepairCraftedItemAction.md` — НОВАЯ
- `mmorpg-vault/tech-writing/tasks/craft/RepairCompletionHandler.md` — НОВАЯ
- `GAME_DESCRIPTION.md` — добавить «Ремонт сломанного — 50% ресурсов, 15 мин»

**Прод-тег.** `v0.51.184`.

**Параллелизация.** После S4.

**Зависимости.** S2, S4.

**Анонс.** Да — «Сломанный инструмент теперь можно починить за половину ресурсов».

**Замечания.** Финал Фазы 1 — техфундамент готов: JSON, building.level, durability, repair.

---

## §5 Фаза 2 (S6–S10): Resource Expansion

### S6 — Missing resources (Ironstone / Oil / Sulfur / RareMetals / Coal) 🟠

**Цель.** Завести в БД 5 ресурсов, упоминаемых в `Config\Buildings.php` (Arsenal, CommunicationTower) и сайт-каноне `wildworld.fun`, но **отсутствующих** в `resources` таблице. Это закрывает lore tail #4 (GAME_DESCRIPTION.md «Расхождения»).

**Канон-ссылка.** `GAME_DESCRIPTION.md` § «⛏️ Добыча ресурсов» — «Базовые материалы»; lore-tail #4.

**Текущая боль.**
- `Config\Buildings.php` требует `Ironstone => 200`, `RareMetals => 60`, `Oil => 70`, `Sulfur => 50` для Arsenal.
- В БД `resources` этих имён нет → Arsenal **недостижим** (П8 трейдер: «не могу построить ничего сверх Workshop»).
- 🔴 lying — рецепт обещает то, что нет в игре.

**Что меняем.**
- Миграция вставляет 5 ресурсов в `resources` таблицу с rarity, base_price, биом-маппингом.
- Биомы-источники: Ironstone → Mountains/Caves; Oil → Deserts/Volcanic; Sulfur → Volcanic; RareMetals → Caves/Volcanic; Coal → Caves/Mountains.

**Файлы к созданию.** Нет (только миграция + LEXICON).

**Файлы к изменению.**
- `app/Config/ImageRegistry.php` — добавить 5 LEXICON-записей (`resource.ironstone`, `resource.oil`, `resource.sulfur`, `resource.rare-metals`, `resource.coal`)

**Миграции.**
```
app/Database/Migrations/2026-05-22-100000_AddMissingMineableResources.php
```
**up:**
```php
public function up(): void
{
    $resources = [
        ['name_eng'=>'Ironstone','name_rus'=>'Железная руда','rarity'=>7,'base_price'=>15,'description'=>'Грубая железная руда. Сырьё для металлофрагментов.'],
        ['name_eng'=>'Oil','name_rus'=>'Сырая нефть','rarity'=>6,'base_price'=>22,'description'=>'Густая чёрная жидкость из недр. Топливо и сырьё.'],
        ['name_eng'=>'Sulfur','name_rus'=>'Сера','rarity'=>5,'base_price'=>28,'description'=>'Жёлтые кристаллы из вулканических жил. Сырьё для боеприпасов.'],
        ['name_eng'=>'RareMetals','name_rus'=>'Редкие металлы','rarity'=>9,'base_price'=>80,'description'=>'Сплавы и фрагменты редких металлов. Высокотехнологичный крафт.'],
        ['name_eng'=>'Coal','name_rus'=>'Уголь','rarity'=>4,'base_price'=>8,'description'=>'Чёрный пласт из карьеров. Топливо для печи.'],
    ];
    foreach ($resources as $r) {
        $exists = $this->db->table('resources')->where('name_eng', $r['name_eng'])->get()->getRow();
        if ($exists) {
            $this->db->table('resources')->where('name_eng', $r['name_eng'])->update($r);
            continue;
        }
        $this->db->table('resources')->insert($r);
    }
    // biome→resource mapping (rates per gather block)
    $biomeMap = [
        'Mountains' => ['Ironstone' => 30, 'Coal' => 25],
        'Caves'     => ['Ironstone' => 25, 'RareMetals' => 5, 'Coal' => 35],
        'Volcanic'  => ['Sulfur' => 25, 'Oil' => 15, 'RareMetals' => 8],
        'Deserts'   => ['Oil' => 18, 'Sulfur' => 8],
    ];
    // ... idempotent UPSERT into biome_resources (table exists)
}
```
**down:** No-op (idempotent UPDATE — повторный run обновляет).

**Image-assets.**
- **`resource.ironstone`** (`uploads/telegram/craft/ironstone.jpg`): LEXICON `resource.junk` + scene-tail: "a heap of raw iron ore — rust-red rocky chunks streaked with darker iron veins, on torn sacking by a workbench". Mode: V1. Price: $0.04.
- **`resource.oil`** (`uploads/telegram/craft/oil.jpg`): LEXICON `resource.junk` + scene-tail: "two scuffed jerrycans of dark crude oil, lids loose, oil staining the wood beneath; one improvised funnel set aside". Mode: V1. Price: $0.04.
- **`resource.sulfur`** (`uploads/telegram/craft/sulfur.jpg`): LEXICON `resource.junk` + scene-tail: "a wooden bowl of bright yellow sulphur crystals, a small pile spilled, picked from volcanic rock; one chunk shows the dull crystalline interior". Mode: V3. Price: $0.04.
- **`resource.rare-metals`** (`uploads/telegram/craft/rare_metals.jpg`): LEXICON `resource.rare` + scene-tail: "a small padded tin holding a few pieces of bright unidentified pre-collapse alloy, set apart from the rust around it, on a workbench". Mode: V1. Price: $0.04.
- **`resource.coal`** (`uploads/telegram/craft/coal.jpg`): LEXICON `resource.junk` + scene-tail: "a sooted basket of jet-black coal chunks beside a pile of similar lumps; the surface dusty, fingers and bench smudged with black". Mode: V1. Price: $0.04.

Subtotal: 5 картинок × $0.04 = **$0.20**.

**Validation-card mini.**
- Категория: 🟠 (новые ресурсы — балансовое решение; требует ADR `ADR-025-Missing-canonical-resources.md`)
- 10-персон: П3 ++ (канон теперь не врёт), П5 ++ (полная цепочка Arsenal достижим), П8 + (новые ценники), П7 + (5 новых achievement-ресурсов)

**Smoke plan.**
1. testbot: `migrate` → 5 ресурсов в БД.
2. Биом Mountains — gather → выпадает Ironstone (вероятность).
3. Биом Volcanic — gather → Sulfur, Oil.
4. Стоимость Arsenal в callback'е реальна (ресурсы существуют).
5. Repeat migrate — no-op (UPSERT-логика).

**Tests добавить.**
- `MissingResourcesMigrationTest::testFiveResourcesInserted()`
- `BiomeResourceMappingTest::testMountainsHasIronstoneAndCoal()`
- `BiomeResourceMappingTest::testVolcanicHasSulfurAndOilAndRareMetals()`

**Tech-writing.**
- `mmorpg-vault/tech-writing/db/resources.md` — обновить
- `mmorpg-vault/lore/world/Биомы.md` — добавить новые ресурсы в таблицу биом→ресурс
- `mmorpg-vault/decisions/ADR-025-Missing-canonical-resources.md` — НОВЫЙ ADR

**Прод-тег.** `v0.51.185`.

**Параллелизация.** После S2 (миграции зависят от JSON формата).

**Зависимости.** S2.

**Анонс.** Да — «5 новых ресурсов теперь добываются в горах, пещерах, пустынях и вулканах. Арсенал стал реально достижим».

**Замечания.** Балансовый риск: новые ресурсы → пик предложения на рынке. S9 (price cron) корректирует.

---

### S7 — Biome↔Resource explicit mapping config 🟡

**Цель.** Перенести implicit hardcoded biome→resource rates из `GatherService` в `Config\BiomeResources` (явный конфиг).

**Канон-ссылка.** `GAME_DESCRIPTION.md` § «⛏️ Добыча ресурсов» — «Уникальные ресурсы — каждый биом содержит специфические материалы»; `mmorpg-vault/lore/world/Биомы.md`.

**Текущая боль.**
- `GatherService::getResourceRates(int $biomeId): array` использует hardcoded match-statement (~150 LOC).
- Любая правка ratenum требует git diff в hot-path коде.
- Невозможно посмотреть «что добывается в биоме N» из админки без чтения исходников.

**Что меняем.**
- `Config\BiomeResources` с массивом `[biome_eng => [resource_eng => weight]]`.
- `GatherService` читает из конфига.
- Admin UI: `/admin/biomes/<id>/resources` — read-only превью.

**Файлы к созданию.**
- `app/Config/BiomeResources.php`
- `app/Controllers/Admin/BiomeResourcesController.php`
- `tests/unit/Config/BiomeResourcesTest.php`

**Файлы к изменению.**
- `app/Services/Player/GatherService.php` — удалить hardcoded match, читать из конфига
- `app/Views/admin/biomes/*` — добавить раздел

**Миграции.** Нет.

**Image-assets.** Нет.

**Validation-card mini.** 🟡 — рефактор + admin расширение.

**Smoke plan.**
1. `composer test` — `GatherServiceTest` зелёный (поведение не изменено).
2. HTTP smoke `curl /admin/biomes/1/resources` → таблица.
3. Telegram smoke: gather в каждом биоме (хотя бы 3) — ресурсы выпадают как раньше.

**Tests добавить.**
- `BiomeResourcesTest::testAllBiomesHaveAtLeastOneResource()`
- `BiomeResourcesTest::testWeightsAreNonNegativeIntegers()`
- `GatherServiceTest::testReadsFromConfig()` — после рефактора

**Tech-writing.**
- `mmorpg-vault/tech-writing/config/BiomeResources.md` — НОВАЯ
- `mmorpg-vault/tech-writing/controllers/BiomeResourcesController.md` — НОВАЯ

**Прод-тег.** `v0.51.186`.

**Зависимости.** S6.

**Анонс.** Не нужен (internal + admin).

---

### S8 — English ingredient cleanup (CraftRecipes) 🟠

**Цель.** Заменить английские имена ингредиентов (`Iron Rods`, `Rubber`, `Gears`, `Wheels`, `Nails`, `Leather`, `Cloth`, `Electronics`, `Batteries`) в `CraftRecipes.php` на существующие ресурсы из БД. Эти recipe-ингредиенты **не существуют** в `resources` → крафты dead.

**Канон-ссылка.** GAME_DESCRIPTION.md (косвенно — крафт «массовое производство»); lore tail (open).

**Текущая боль.**
- Mountain Bike L12, Wooden Fence L15, Light Cart, Portable Radio, Autonomous Drone — все ссылаются на ингредиенты, которых нет в `resources`.
- Игроки видят рецепт в админ-листе, но не могут его сделать («не хватает Iron Rods» — а такого ресурса нет вообще).
- 🔴 lying description.

**Что меняем.**
- Каждый english ingredient mapped на существующий ru-resource:
  - `Iron Rods` → «Железная руда» + «Металлические фрагменты»
  - `Rubber` → «Кора деревьев» (плантация каучуковых деревьев — постапок-натяжка, но укладывается)
  - `Gears` → «Металлические фрагменты»
  - `Wheels` → новый crafted item `WoodenWheel` (или existing `WoodMaterials`)
  - `Nails` → «Металлические фрагменты»
  - `Leather` → «Шкуры животных»
  - `Cloth` → «Ткань» (уже crafted item)
  - `Electronics` → «Электронные компоненты» (уже crafted item)
  - `Batteries` → новый crafted item `Battery` (S9 предложит как content расширение)

**Файлы к созданию.** Нет (только UPDATE config).

**Файлы к изменению.**
- `app/Config/CraftRecipes.php` — заменить ингредиенты во всех recipe entries

**Миграции.**
```
app/Database/Migrations/2026-05-23-100000_FixEnglishIngredientsInCraftedItems.php
```
Idempotent UPDATE на `crafted_items.required_resources` (теперь JSON после S2) — пересохранить с ru-именами.

**Image-assets.** Нет (рецепты уже имели картинки).

**Validation-card mini.** 🟠 — фикс lying descriptions + content rebalance.

**Smoke plan.**
1. testbot: попытаться сделать `Mountain Bike` — рецепт показывает ru-ресурсы, парсит из БД корректно.
2. Если есть ресурсы — создаётся задача, доходит до completion.
3. Repeat migrate — no-op.

**Tests добавить.**
- `CraftRecipesTest::testAllResourcesExistInDb()` — итеративный assertion для каждого рецепта/каждого ингредиента
- `EnglishIngredientCleanupMigrationTest::testIdempotent()`

**Tech-writing.**
- `mmorpg-vault/tech-writing/config/CraftRecipes.md` — обновить, описать миграцию

**Прод-тег.** `v0.51.187`.

**Зависимости.** S2, S6.

**Анонс.** Да — «5 крафтов исправлены: теперь Mountain Bike, Wooden Fence, Light Cart, Portable Radio и Autonomous Drone используют реальные ресурсы. Раньше были недоступны».

---

### S9 — Scheduled price update cron 🟡

**Цель.** Перевести `ResourceModel::updateResourcePrices()` на cron-расписание (раз в час) вместо реактивного вызова на каждом SellAction.

**Канон-ссылка.** `GAME_DESCRIPTION.md` § «💰 Экономическая система» — «Динамические цены».

**Текущая боль.**
- Цены обновляются только при `SellAction`. Если никто не продаёт — цены замороженные.
- Реактивное обновление = N+1 query на каждой продаже.
- Игроки не видят «рыночный пульс».

**Что меняем.**
- Регистрация в `app/Config/Tasks.php` recurring task `updateResourcePrices` (interval 1h).
- Handler `UpdateResourcePricesHandler` (extends `BaseTaskHandler`).
- В `SellAction` — снять inline update (теперь cron).

**Файлы к созданию.**
- `app/TaskHandlers/Economy/UpdateResourcePricesHandler.php`
- `tests/unit/TaskHandlers/Economy/UpdateResourcePricesHandlerTest.php`

**Файлы к изменению.**
- `app/Config/Tasks.php` — recurring task entry
- `app/Controllers/Telegram/Commands/Actions/Market/SellAction.php` — снять inline call

**Миграции.** Нет (task registered в конфиге).

**Validation-card mini.** 🟡 — экономический рефактор, идемпотентный handler.

**Smoke plan.**
1. testbot: `tasks:run` каждый час — цены обновляются.
2. SellAction — цены НЕ обновляются inline, но видны актуальные из БД.
3. Idempotent: 2 запуска в одну минуту — нет двойного обновления.

**Tests добавить.**
- `UpdateResourcePricesHandlerTest::testPriceMovesDownOnOversupply()`
- `UpdateResourcePricesHandlerTest::testPriceMovesUpOnScarcity()`
- `UpdateResourcePricesHandlerTest::testIdempotentWithinTickWindow()`

**Tech-writing.**
- `mmorpg-vault/tech-writing/tasks/economy/UpdateResourcePricesHandler.md` — НОВАЯ
- `mmorpg-vault/tech-writing/models/ResourceModel.md` — обновить (price model now cron)

**Прод-тег.** `v0.51.188`.

**Зависимости.** S6 (новые ресурсы участвуют).

**Анонс.** Да — «Рынок теперь живёт: цены обновляются каждый час даже если никто не продаёт».

---

### S10 — Rare late-game resources 🟡

**Цель.** Добавить 3–4 редких ресурса для T3 крафта (S16+): Spent Fuel Rods (отработанные топливные стержни), Pre-Collapse Electronics (электроника до катастрофы), Industrial Plastic (промышленный пластик), Medical Compound (медицинский компонент).

**Канон-ссылка.** GAME_DESCRIPTION.md § «Редкие компоненты»; lore-доп нужен.

**Текущая боль.** T3 верстак (S16) нужны редкие материалы, чтобы tier 3 не был «пересказ tier 2 с цифрами повыше».

**Что меняем.**
- 4 новых ресурса rarity 9–10, base_price 100+.
- Источники: events (loot drops с событий), strategic objects (S21–S25).
- НЕ добываются gather'ом из биомов — только через события.

**Файлы к созданию.** Нет.

**Файлы к изменению.**
- `app/Config/ImageRegistry.php` — 4 LEXICON-записи
- `app/Services/EventService.php` — loot table расширяется

**Миграции.**
```
app/Database/Migrations/2026-05-25-100000_AddRareLateGameResources.php
```

**Image-assets.**
- **`resource.fuel-rods`** — LEXICON `resource.rare` + "a wrapped bundle of lead-shielded spent fuel rods on a wheeled cart, a faint warning daub on the side (no text), gloves and tongs laid aside; ominous, precious". Mode: V1. Price: $0.04.
- **`resource.pre-collapse-electronics`** — `resource.rare` + "a pristine circuit board from before the collapse, set on cloth, the lettering on chips visibly etched (but unreadable scrubbed at micro-scale), copper traces clean; museum-piece-on-a-bench". Mode: V4. Price: $0.04.
- **`resource.industrial-plastic`** — `resource.mid` + "stacked sheets and folded rolls of clean dense plastic on a workbench; bright clean against the rust around, clearly pre-collapse manufacture". Mode: V1. Price: $0.04.
- **`resource.medical-compound`** — `resource.rare` + "a sealed glass ampoule of clear medical-grade compound on padded cloth, faint label scuffed to abstract marks, set apart from the dirty bench around". Mode: V4. Price: $0.04.

Subtotal: 4 × $0.04 = **$0.16**.

**Validation-card mini.** 🟡 — content extension, нет балансового сдвига (loot rare).

**Smoke plan.**
1. Trigger MeteorImpact event — Spent Fuel Rods может выпасть (1% chance).
2. Quest StrategicCaptureBunker reward — гарантированно Pre-Collapse Electronics.

**Tests добавить.**
- `RareResourceLootTableTest::testFuelRodsFromMeteor()`
- `RareResourceLootTableTest::testStrategicQuestRewards()`

**Tech-writing.**
- `mmorpg-vault/tech-writing/db/resources.md` — обновить
- `mmorpg-vault/lore/craft/Редкие-ресурсы.md` — НОВАЯ нота

**Прод-тег.** `v0.51.189`.

**Зависимости.** S6, S9.

**Анонс.** Да — «Редкие материалы (топливные стержни, до-катастрофная электроника) теперь падают с событий и стратегических объектов».

---

## §6 Фаза 3 (S11–S15): Building progression upgrades

### S11 — Workshop L2 + L3 🟡

**Цель.** Активировать L2 и L3 апгрейды Мастерской: L2 даёт +10% скорости общего крафта, L3 — +25%.

**Канон-ссылка.** `GAME_DESCRIPTION.md` § «🔧 Мастерская — базовый крафт»; `mmorpg-vault/lore/base/Постройки.md`.

**Текущая боль.** S3 wired generic handler; на этой сессии — конкретные эффекты для Workshop.

**Что меняем.**
- `BuildingUpgrades.php` → Workshop L2 и L3 cost + effect.
- `CraftService::getCraftTime()` — учесть workshop level multiplier.

**Файлы к созданию.** Нет.

**Файлы к изменению.**
- `app/Config/BuildingUpgrades.php` — Workshop entry
- `app/Services/CraftService.php`

**Миграции.**
```
app/Database/Migrations/2026-05-26-100000_AddWorkshopLevelsConfig.php
```
(пишет дефолтные `building_upgrades` row для всех 358 chars — idempotent, level=1 baseline).

**Image-assets.**
- **`building.workshop.l2`** (`uploads/telegram/camp/workshop_l2.png`): LEXICON `building.workshop` + "the workshop visibly expanded — a longer roof, a second workbench, more tools mounted on a board, racks of sorted parts; clearly an upgraded version of the same shed". Mode: V1. Price: $0.04. Iterations: 2 (×$0.08).
- **`building.workshop.l3`** (`uploads/telegram/camp/workshop_l3.png`): LEXICON `building.workshop` + "the workshop now a sprawling hand-built complex — three workbenches under a long sheet roof, a small forge in one corner, racks heavy with tools, an apprentice at a vice; expanded, productive, prosperous-for-postapoc". Mode: V1. Price: $0.04. Iterations: 2.

Subtotal: 4 generations × $0.04 = **$0.16**.

**Validation-card mini.** 🟡 — content (apgrade contract в каноне).

**Smoke plan.**
1. testbot: апгрейд Workshop до L2 — endgame score +200 farming faction.
2. Краф Bandage — на L1 = 15min, на L2 = 13.5min (90%).
3. Апгрейд до L3 — Bandage = 11.25min (75%).

**Tests добавить.**
- `WorkshopLevelEffectTest::testCraftTimeMultiplierL2()`
- `WorkshopLevelEffectTest::testCraftTimeMultiplierL3()`

**Tech-writing.**
- `mmorpg-vault/tech-writing/config/BuildingUpgrades.md` — обновить
- `mmorpg-vault/lore/base/Постройки.md` — добавить уровни

**Прод-тег.** `v0.51.190`.

**Зависимости.** S3.

**Анонс.** Да — «Мастерская теперь улучшается: L2 = крафт на 10% быстрее, L3 = на 25%».

---

### S12 — BlastFurnace L2 + L3 🟡

**Цель.** L2 = выход переплавки +15% (metal fragments per ironstone), L3 = +35%.

**Канон-ссылка.** `GAME_DESCRIPTION.md` § «🔥 Доменная печь — переработка металлов».

**Текущая боль.** До S6 у BlastFurnace не было сырья (Ironstone отсутствовал). После S6 — есть; теперь нужна прогрессия.

**Что меняем.**
- `BuildingUpgrades.php` → BlastFurnace L2/L3
- `CraftService` для рецептов, требующих BlastFurnace — multiplier на output

**Файлы к изменению.**
- `app/Config/BuildingUpgrades.php`
- `app/Services/CraftService.php` (или `MetalFragmentService` если выделен)

**Миграции.** Аналогично S11 (idempotent UPSERT building_levels).

**Image-assets.**
- **`building.blast-furnace.l2`** — LEXICON `building.blast-furnace` + "the smelting furnace now twin-mouthed — two stoke holes, twin bellows, doubled slag heap, a second worker tending; brighter glow, more smoke". Mode: V3 (тёплое освещение). Price: $0.04. Iterations: 2.
- **`building.blast-furnace.l3`** — `building.blast-furnace` + "a small forge complex — three furnaces in a row, a covered cooling rack of ingots, a water trough, soot blackened ground; an industrial mini-yard built from scrap". Mode: V3. Price: $0.04. Iterations: 2.

Subtotal: **$0.16**.

**Validation-card mini.** 🟡

**Smoke plan.** Аналогично S11 — апгрейд → переплавка Ironstone в metalFragments → output растёт.

**Tests.** `BlastFurnaceLevelTest::testSmeltingOutputMultiplierL2/L3()`.

**Tech-writing.** Обновить.

**Прод-тег.** `v0.51.191`.

**Зависимости.** S3, S6, S11.

**Анонс.** Да — «Доменная печь L2/L3: переплавка железной руды эффективнее».

---

### S13 — Laboratory + Greenhouse L2/L3 ⚡ 🟡

**Цель.** Лаборатория L2/L3 = быстрее медицинский крафт (−10%/−25%); Теплица L2/L3 = больше еды (+20%/+45%).

**Канон-ссылка.** GAME_DESCRIPTION.md «🥼 Лаборатория», «🌱 Теплица».

**Что меняем.**
- `BuildingUpgrades.php` — два entry
- `CraftService` — multiplier для medical recipes (Laboratory)
- `GreenhouseProductionHandler` — yield multiplier

**Image-assets.**
- **`building.laboratory.l2`** — `building.laboratory` + "the lab corner expanded — two benches, a wider tarp roof, a methodical row of drying racks with herbs hung upside down, a hand-cranked centrifuge". Mode: V1. Price: $0.04.
- **`building.laboratory.l3`** — `building.laboratory` + "a proper hand-built lab tent — partitioned, glass-fronted, a vacuum hand pump, a small still, a survivor in an improvised apron mixing; serious chemistry". Mode: V1. Price: $0.04.
- **`building.greenhouse.l2`** — `building.greenhouse` + "the polytunnel doubled in length — twin tunnels under cloudy plastic, rows of bigger plants, a hand-cranked irrigation pump, a survivor weeding". Mode: V1. Price: $0.04.
- **`building.greenhouse.l3`** — `building.greenhouse` + "a small greenhouse complex — three tunnels, raised beds, a hand-cranked water mill, baskets of harvested produce stacked outside; productive". Mode: V1. Price: $0.04.

Subtotal: 4 × $0.04 (×2 iterations) = **$0.32**.

**Validation-card mini.** 🟡

**Smoke / Tests / Tech-writing.** Аналогично S11/S12.

**Прод-тег.** `v0.51.192`.

**⚡ Параллель** с S12 — независимые здания.

**Зависимости.** S3.

**Анонс.** Да (комплексный «Лаборатория и теплица улучшаются»).

---

### S14 — Robotics Workshop L2/L3 + Robot T2/T3 🟠

**Цель.** Roboticum L2/L3 = быстрее крафт роботов (−15%/−30%), плюс открывает Robot T2 (durability ×2) и T3 (durability ×3 + faster).

**Канон-ссылка.** `GAME_DESCRIPTION.md` § «🤖 Система роботов»; `mmorpg-vault/lore/base/Роботы.md`.

**Текущая боль.** Каноном описано «Время работы: 6h × уровень роб. мастерской» — но фактически работает только level=1. Нет T2/T3 робота-варианта.

**Что меняем.**
- `BuildingUpgrades.php` — RoboticsWorkshop entry
- 2 новых крафт-рецепта: `RobotExplorerT2`, `RobotGathererT2`, и T3
- 4 новых task-handler completion'ов

**Файлы к созданию.**
- `app/TaskHandlers/Craft/CraftCompletionRobotExplorerT2Handler.php`
- `app/TaskHandlers/Craft/CraftCompletionRobotExplorerT3Handler.php`
- `app/TaskHandlers/Craft/CraftCompletionRobotGathererT2Handler.php`
- `app/TaskHandlers/Craft/CraftCompletionRobotGathererT3Handler.php`

**Миграции.** `2026-05-29-100000_AddRobotT2T3CraftedItems.php`.

**Image-assets.**
- **`building.robotics-workshop.l2`** — `building.robotics-workshop` + "the robotics workbench widened — twin clamping stands, two half-built robots in progress, a wall of organised parts bins". Mode: V1. Price: $0.04 × 2 iter.
- **`building.robotics-workshop.l3`** — `building.robotics-workshop` + "a small robotics yard — three workbenches, a finished gatherer being tested on a treadmill, a wall of robot heads on hooks, sparks flying from welding". Mode: V1. Price: $0.04 × 2.
- **`robot.explorer.t2`** — `robot.explorer` + "a sturdier mid-tier scout robot — bigger treads, a thicker chassis, two camera-eye lenses, taller antenna with a small dish; still scrap-built but clearly better engineered than the T1". Mode: V4. Price: $0.04.
- **`robot.explorer.t3`** — `robot.explorer` + "an advanced scout robot — twin antenna, a small jury-rigged solar panel on top, articulated leg-tread hybrids, a tool arm folded against its body; clearly the best a scrap shop can build". Mode: V4. Price: $0.04.
- **`robot.gatherer.t2`** — `robot.gatherer` + "a sturdier worker robot — bigger scoop, reinforced frame, twin sacks slung on each side, lower-and-wider stance". Mode: V4. Price: $0.04.
- **`robot.gatherer.t3`** — `robot.gatherer` + "an advanced worker robot — articulated multi-tool arm, treads with cleats, a hopper instead of a sack, a small steam vent on top". Mode: V4. Price: $0.04.

Subtotal: 8 generations × $0.04 = **$0.32**.

**Validation-card mini.** 🟠 — новые крафт-предметы влияют на gather/explore баланс.

**Smoke plan.**
1. testbot: апгрейд Robotics до L2.
2. Крафт `RobotExplorerT2` — требует L2 робомастерской → доступен.
3. Запуск T2-исследователя — durability 2× больше.

**Tests добавить.**
- `RobotT2T3CraftTest::testT2RequiresLevel2()`
- `RobotT2T3CraftTest::testT3RequiresLevel3()`
- `RobotExplorerT2DurabilityTest::testDoubleDurability()`

**Tech-writing.**
- `mmorpg-vault/tech-writing/tasks/craft/CraftCompletionRobotExplorerT2Handler.md` — НОВАЯ (и 3 другие)
- `mmorpg-vault/lore/base/Роботы.md` — добавить тиры

**Прод-тег.** `v0.51.193`.

**Зависимости.** S3, S4 (durability), S6, S10.

**Анонс.** Да — «Робомастерская теперь улучшается до L3. Роботы T2 и T3 живут дольше и работают эффективнее».

---

### S15 — TeleportationCenter L2/L3 + Beacon network 🟡

**Цель.** Центр L2/L3 = больше доступных маяков одновременно (5/8 от base 3), снижение стоимости телепортации.

**Канон-ссылка.** `GAME_DESCRIPTION.md` § «🌀 Центр телепортации»; `mmorpg-vault/lore/teleport/`.

**Что меняем.**
- `BuildingUpgrades.php` — TeleportationCenter entry
- `BeaconService::getMaxBeacons(int $teleportLevel): int`

**Image-assets.**
- **`building.teleport-center.l2`** — `building.teleport-center` + "the teleport platform stabilised — sandbagged edges, additional copper coils, twin power feeds from battery banks, faint steadier blue arc-light". Mode: V1. Price: $0.04 × 2.
- **`building.teleport-center.l3`** — `building.teleport-center` + "a refined teleport rig — wider platform, mast-mounted ozone vent, banks of capacitors, a controlled steady glow; the most polished scrap-tech on the camp". Mode: V1. Price: $0.04 × 2.

Subtotal: **$0.16**.

**Validation-card mini.** 🟡

**Smoke / Tests / Tech-writing.** Standard.

**Прод-тег.** `v0.51.194`.

**Зависимости.** S3, S11 (apgrade pattern).

**Анонс.** Да — «Центр телепортации L2/L3: больше маяков одновременно, дешевле прыжки».

---

## §7 Фаза 4 (S16–S20): Craft depth — Tier 3

### S16 — Tier 3 Professional Workbench (ADR-026) 🟠

**Цель.** Ввести 3-й тир верстака — **Professional Workbench**, локация для редких крафтов. Это **расширение канона** (канон молчит про T3) — требует ADR.

**Канон-ссылка.** GAME_DESCRIPTION.md § «🔨 Система крафта» — «двухуровневая система». T3 — расширение.

**Текущая боль.**
- Канон молчит про T3 (резерв).
- Игра упирается в потолок T2: L13–L17 progression gap (4 уровня без новых рецептов).
- П5 (Билдер) — «после T2 — пустота».

**Что меняем.**
- Новый crafted item `ProfessionalWorkbench` (тип `workbench`).
- Требует: уровень персонажа 16, BlastFurnace L3, Lab L3, 1 RareMetals 30, 1 Pre-Collapse Electronics, ...
- `Config\CraftRecipes` → entry `ProfessionalWorkbench`.

**Файлы к созданию.**
- `app/TaskHandlers/Craft/CraftCompletionProfessionalWorkbenchHandler.php`
- `tests/unit/TaskHandlers/Craft/CraftCompletionProfessionalWorkbenchHandlerTest.php`
- `mmorpg-vault/decisions/ADR-026-Tier3-Professional-Workbench.md` — **НОВЫЙ ADR**

**Миграции.** `2026-05-31-100000_AddProfessionalWorkbenchCraftedItem.php`.

**Image-assets.**
- **`craft.professional-workbench`** (`uploads/telegram/craft/professional_workbench.jpg`): scene-tail: "a heavy hand-built workbench — twin vices, a small forge attached, a wall of tool-hooks bristling with hand-forged precision tools, a stained tarp roof; this is where the master-tier work gets done". Mode: V1. Price: $0.04 × 3 iter (важный hero-объект).

Subtotal: **$0.12**.

**Validation-card mini.** 🟠 — расширение канона (требует ADR-026). См. полную карточку §11 S16.

**Smoke plan.**
1. testbot: персонаж L16, все требования.
2. Крафт T3 верстака — 6h задача.
3. После завершения — T3 рецепты unlocked.

**Tests.** `ProfessionalWorkbenchTest::testRequiresL16AndBuildings()`, `testUnlocksT3Recipes()`.

**Tech-writing.**
- ADR-026
- `mmorpg-vault/tech-writing/tasks/craft/CraftCompletionProfessionalWorkbenchHandler.md`
- `mmorpg-vault/lore/craft/Крафт-Workbench-Professional.md` — НОВАЯ нота канона

**Прод-тег.** `v0.51.195`.

**Зависимости.** S6, S10, S11, S12, S13.

**Анонс.** Да — «Профессиональный верстак: новый уровень мастерства. Требует уровень 16 и развитую базу».

---

### S17 — T3 weapons (5 recipes) ⚡ 🟡

**Цель.** 5 T3 рецептов оружия: `MasterCrossbow` (мастер-арбалет), `HeavyRebar` (тяжёлая арматура), `PipeShotgun` (трубчатый дробовик), `SteelMachete` (сталь-мачете), `ScrapRifle` (самопальная винтовка).

**Канон-ссылка.** `GAME_DESCRIPTION.md` § «Продвинутый крафт — Оружие».

**Файлы к созданию.**
- 5 new task-handlers `app/TaskHandlers/Craft/WorkbenchStandard/Weapons/T3/*`

**Файлы к изменению.**
- `app/Config/CraftRecipes.php` — 5 новых entry
- `app/Models/WeaponModel.php` — может быть data-only через миграцию

**Миграции.** `2026-06-02-100000_AddT3Weapons.php` — INSERT 5 oruzhia (weapons + crafted_items rows).

**Image-assets.** 5 weapon-картинок (LEXICON `loot.epic-weapon`):
- `craft.master-crossbow` (V4, $0.04 × 2)
- `craft.heavy-rebar` (V4, $0.04 × 2)
- `craft.pipe-shotgun` (V4, $0.04 × 2)
- `craft.steel-machete` (V4, $0.04 × 2)
- `craft.scrap-rifle` (V4, $0.04 × 2)

Subtotal: 10 generations × $0.04 = **$0.40**.

**Validation-card mini.** 🟡 (content в существующем тире T3, ADR-026 уже создан в S16).

**Smoke plan.** Standard — craft каждого на testbot, проверить, что появляется в инвентаре, equippable, damage values корректны.

**Tests.** 5 craft-tests + 5 weapon-damage-tests.

**Tech-writing.** Нота на каждое оружие в `mmorpg-vault/tech-writing/tasks/craft/`.

**Прод-тег.** `v0.51.196`.

**⚡ Параллель** с S18 (T3 armor).

**Зависимости.** S16.

**Анонс.** Да — «5 новых видов оружия Tier 3 доступны на профессиональном верстаке».

---

### S18 — T3 armor (4 recipes) ⚡ 🟡

**Цель.** 4 T3 брони: `PlatedArmor` (бронепластины), `KevlarPatchwork` (кевларовая лоскутка), `TacticalVest` (тактический жилет), `HardenedHelmet` (укреплённый шлем).

**Канон.** GAME_DESCRIPTION.md «Броня».

**Файлы.** Аналогично S17 (4 handler'а в `Craft/WorkbenchStandard/Armor/T3/`).

**Миграции.** `2026-06-03-100000_AddT3Armor.php`.

**Image-assets.** 4 × LEXICON `loot.armor` mode V4 × 2 iter = **$0.32**.

**Smoke / Tests / Tech-writing.** Standard.

**Прод-тег.** `v0.51.197`.

**⚡ Параллель** с S17.

**Зависимости.** S16.

**Анонс.** Объединить с S17 в один анонс «Tier 3: 5 оружий + 4 брони».

---

### S19 — T3 medical (3 recipes) ⚡ 🟡

**Цель.** 3 T3 medical: `SyntheticMedicine` (синтетическое лекарство — закрывает любую болезнь), `EmergencyTransfusion` (экстренное переливание +80HP), `SurgicalKit` (хирургический набор — multi-use).

**Канон.** GAME_DESCRIPTION.md «Медицинские предметы».

**Файлы.** 3 handler'а + миграция.

**Image-assets.** 3 × LEXICON `loot.medicine` mode V4 × 2 iter = **$0.24**.

**Smoke / Tests.** Standard + integration с EventService (epidemic, fever).

**Прод-тег.** `v0.51.198`.

**⚡ Параллель** с S20.

**Зависимости.** S16.

**Анонс.** Объединить с S20.

---

### S20 — T3 utility (3 recipes) ⚡ 🟡

**Цель.** 3 T3 utility: `CombinationTool` (комбо-инструмент: пика+топор+лопата в одном, durability shared), `PortableForge` (переносная кузница — repair anywhere), `AdvancedFishingNet` (продвинутая рыболовная сеть).

**Канон.** GAME_DESCRIPTION.md «Инструменты».

**Файлы.** 3 handler'а + миграция.

**Image-assets.** 3 × LEXICON `loot.tool` mode V4 × 2 iter = **$0.24**.

**Smoke / Tests / Tech-writing.** Standard.

**Прод-тег.** `v0.51.199`.

**Зависимости.** S16.

**Анонс.** «Tier 3: 3 медицинских + 3 утилит».

---

## §8 Фаза 5 (S21–S25): Endgame faction content

### S21 — Bunker (Military) + quest StrategicCaptureBunker + ADR-027 🟠

**Цель.** Реализовать **Bunker как открываемый объект на карте** (а не только quest-trigger). Закрывает lore tail #3.

**Канон-ссылка.** `GAME_DESCRIPTION.md` § «🌍 Финальные сценарии — Военное господство»; `mmorpg-vault/lore/endgame/Endgame-scenarios.md`.

**Текущая боль.**
- Bunker упоминается как +500 endgame очков для Military (v0.51.116-117), но **объект на карте не реализован** — quest auto-completes по name, никакого реального discovery flow нет.
- П3 Лорщик: «канон обещал стратегические объекты — где они?».

**Что меняем.**
- Миграция вставляет 1 Bunker world_object на координаты в Volcanic biome (rare spawn, 1 per server).
- Action-handler `DiscoverBunkerAction` — при наступлении на клетку (через ExploreCellAction handler).
- Visual marker на карте (зелёная пиктограмма).
- Quest StrategicCaptureBunker уже реализован (v0.51.117) — теперь триггерится реально.

**Файлы к созданию.**
- `app/Controllers/Telegram/Commands/Actions/World/DiscoverStrategicObjectAction.php` (универсальный для S21–S24)
- `app/TaskHandlers/StrategicObjects/BunkerCaptureHandler.php`
- `mmorpg-vault/decisions/ADR-027-Strategic-objects-implementation.md`

**Файлы к изменению.**
- `app/Services/World/ObjectDiscoveryService.php`
- `app/Models/WorldObjectModel.php`

**Миграции.** `2026-06-08-100000_AddBunkerWorldObject.php`.

**Image-assets.**
- **`world.bunker`** (`uploads/telegram/world/bunker.jpg`): scene-tail: "a half-buried concrete bunker dug into a volcanic ridge — a steel blast door pitted with rust, ash heaped against the base, a faded warning mark scratched into the steel (no letters), the silhouette of an antenna up top". Mode: V2. Price: $0.04 × 3 iter = $0.12.
- **`world.bunker.discovered`** (`uploads/telegram/world/bunker_discovered.jpg`): "the same bunker with the blast door now cracked open — a slice of dark interior visible, a cracked lamp inside, a survivor about to step in; tense, unknown". Mode: V2. Price: $0.04 × 2 = $0.08.

Subtotal: **$0.20**.

**Validation-card mini.** 🟠 — endgame mechanic (требует ADR-027), 1:1 mapping Bunker→Military не нарушается.

**Smoke plan.**
1. testbot: handler-spawn Bunker на известную клетку.
2. Двинуть Андрея на клетку — должен пройти discovery flow.
3. `+500` endgame score → Military faction.
4. Если у игрока quest StrategicCaptureBunker — `+100` (quest completion) + 5000 gold.
5. Idempotent: повторное наступание = no re-trigger.

**Tests.**
- `DiscoverBunkerActionTest::testDiscoveryFlow()`
- `DiscoverBunkerActionTest::testIdempotentDiscovery()`
- `EndgameBunkerScoreTest::testFiveHundredPointsToMilitary()`

**Tech-writing.**
- ADR-027
- `mmorpg-vault/tech-writing/handlers/world/DiscoverStrategicObjectAction.md`
- `mmorpg-vault/lore/endgame/Endgame-scenarios.md` — обновить (objects now real)

**Прод-тег.** `v0.51.200`.

**Зависимости.** S6, S10, S16.

**Анонс.** Да — большой анонс: «**Бункер** теперь существует на карте. Найди — и Военные получат +500 очков к финалу».

---

### S22 — Technopark (Engineers) ⚡ 🟠

**Цель.** Аналогично S21 — реализовать Technopark в Mountains/Caves biome.

**Канон.** `mmorpg-vault/lore/endgame/Endgame-scenarios.md` — Engineers→Technopark→Scientific Breakthrough.

**Файлы / миграция / handler.** Шаблон S21.

**Image-assets.**
- **`world.technopark`** (`uploads/telegram/world/technopark.jpg`): "a half-collapsed pre-collapse research compound clinging to a mountainside — broken concrete pillars, dish antennas tipped over, solar panels half-buried; eerie silent technology". Mode: V2. Price: $0.04 × 3 = $0.12.
- **`world.technopark.discovered`** — V2 × 2 = $0.08.

Subtotal: **$0.20**.

**Прод-тег.** `v0.51.201`.

**⚡ Параллель** с S23.

**Зависимости.** S21 (общий handler infrastructure).

**Анонс.** «Технопарк появился в горах».

---

### S23 — GhostCity (Partisans) ⚡ 🟠

**Цель.** GhostCity в Fields/Jungles.

**Канон.** `Partisans→GhostCity→Anarchy`.

**Image-assets.**
- **`world.ghost-city`** — "the silhouette of an abandoned pre-collapse small town visible at distance — empty windows, leaning walls, vegetation reclaiming streets, smoke rising from one chimney suggesting hidden survivors; eerie partisan territory". Mode: V2. Price: $0.04 × 3 = $0.12.
- **`world.ghost-city.discovered`** — V2 × 2 = $0.08.

Subtotal: **$0.20**.

**Прод-тег.** `v0.51.202`.

**⚡ Параллель** с S22.

**Зависимости.** S21.

**Анонс.** «Город-призрак найден».

---

### S24 — Island-Farm (Farmers) ⚡ 🟠

**Цель.** Стратегический объект для Фермеров — древняя плодородная ферма (Island-Farm) в Fields. Реализует quest `FarmersHarvest` через discovery.

**Канон.** Не указано в lore — этой сессией добавляется. ADR-028 (Farmers strategic object).

**Image-assets.**
- **`world.island-farm`** — "a forgotten pre-collapse farmstead in tall grass — barns with sun-bleached wood, an empty silo, fruit trees still bearing, a working windmill; calm productive ruin". Mode: V3. Price: $0.04 × 3 = $0.12.
- **`world.island-farm.discovered`** — V3 × 2 = $0.08.

Subtotal: **$0.20**.

**Прод-тег.** `v0.51.203`.

**⚡ Параллель** с S25.

**Зависимости.** S21.

**Анонс.** «Старая ферма найдена в полях — Фермеры получают +500».

---

### S25 — Faction-unique weapons (×4) ⚡ 🟠

**Цель.** 4 faction-exclusive weapons (1 per фракция), доступны только после quest faction-strategic-capture:
- Military: `BunkerRifle` (после Bunker capture)
- Engineers: `TechnoBeamShotgun` (после Technopark — старый pre-collapse coilgun)
- Partisans: `GhostCityKnife` (после GhostCity — нож контрабандистов)
- Farmers: `FarmersHarvestScythe` (после Island-Farm)

**Канон.** Лор-расширение (требует ADR-029).

**Файлы / handlers.** 4 craft-completion handler'а в `Craft/WorkbenchStandard/Weapons/Faction/`.

**Миграция.** `2026-06-12-100000_AddFactionExclusiveWeapons.php`.

**Image-assets.** 4 weapons × LEXICON `loot.epic-weapon` mode V4 × 2 iter = $0.32. Subtotal: **$0.32**.

**Validation-card mini.** 🟠 — faction-exclusive content (требует ADR-029 для fairness audit).

**Smoke plan.**
1. testbot: завершить Bunker quest → unlock `BunkerRifle` рецепт.
2. Crafted → больший урон чем T3 generic crossbow (ниша).
3. Без quest completion — рецепт hidden.

**Tests.** `FactionWeaponLockTest::testRecipeHiddenWithoutQuest()` × 4.

**Прод-тег.** `v0.51.204`.

**Зависимости.** S16, S21, S22, S23, S24.

**Анонс.** Большой эндгейм-анонс: «Каждая фракция получила своё уникальное оружие. Только тех, кто захватил свой стратегический объект».

---

## §9 Фаза 6 (S26–S30): Polish + ROADMAP-vNext

### S26 — Defensive structures (walls / traps / towers) 🟠

**Цель.** Закрыть P4 (PvP) голос — defensive base structures: `WoodenWall` (деревянная стена, base damage reduction), `BarbedFence` (колючая ограда, slow-down + minor damage), `WatchTower` (наблюдательная вышка, alert + range).

**Канон-ссылка.** GAME_DESCRIPTION.md § «🏗️ Строительство баз» — нет такого раздела; **лор-расширение** (требует ADR-030).

**Текущая боль.** PvP-хищники могут просто прийти и забрать. Сейчас нет defensive infrastructure.

**Что меняем.**
- 3 новых типа building (walls/fence/tower). Не отдельные `building_type`, а сущность `defensive_structure`.
- При PvP-нападении на базу — структуры активируются: -% к urgency damage, +% chance escape.
- Tax: walls 200💰/day, fence 350💰/day, tower 700💰/day.

**Файлы / handlers.** Стандарт.

**Миграция.** `2026-06-13-100000_AddDefensiveStructures.php`.

**Image-assets.** 3 структуры × $0.04 × 2 = $0.24. Subtotal: **$0.24**.

**Validation-card mini.** 🟠 — балансовое — boosts defender (П4 неоднозначно: за / против). См. полную карточку §11 S26.

**Smoke / Tests.** Standard + PvP damage formula integration test.

**Прод-тег.** `v0.51.205`.

**Зависимости.** S2 (resources), S3 (level up infrastructure).

**Анонс.** Да, большой — «Защита базы: стены, колючка, башня».

---

### S27 — Craft queue UI visualization 🟡

**Цель.** Закрыть техдолг craft-queue (v0.51.129) — UI визуализация: «у тебя в очереди X крафтов, ближайший через Y минут».

**Канон.** Уже реализована queue в коде; пока без UI.

**Что меняем.**
- View `app/Views/telegram/craft/queue.php`.
- Action-handler `ShowCraftQueueAction`.

**Files.** Standard.

**Image-assets.** Нет (UI-сообщение, без hero-картинки).

**Smoke / Tests.** HTTP smoke `curl /telegram/craft/queue` (если есть веб-vue), Telegram smoke.

**Прод-тег.** `v0.51.206`.

**Зависимости.** Нет.

**Анонс.** Да — «Очередь крафта видно».

---

### S28 — Seasonal craft rotation 🟠

**Цель.** Ввести **сезонные рецепты** — 5–10 рецептов, доступных только N дней. Дать ощущение «события» и причину захода в игру.

**Канон.** Не указано — расширение (ADR-031).

**Что меняем.**
- `Config\SeasonalCrafts` config с расписанием.
- `EventService` запускает «сезоны» (3 per год, по ~14 дней).
- Каждый сезон unlock'ает 5 рецептов.

**Image-assets.** Зависит от первой сезонной партии — 5 × $0.04 × 1 = **$0.20**.

**Validation-card.** 🟠 — FOMO concern (П2 голос: bad). Mitigation: «сезон возвращается каждый год».

**Прод-тег.** `v0.51.207`.

**Зависимости.** S16.

**Анонс.** Да — «Сезонные крафты: первый сезон — Зима выживания».

---

### S29 — Content-pass: lying descriptions cleanup 🟢

**Цель.** Audit всех user-facing строк (`crafted_items.description`, `events.description`, `buildings.completion_text`) на «lying descriptions». Idempotent UPDATE миграция исправляет.

**Канон-ссылка.** `GAME_DESCRIPTION.md` § расхождения; `mmorpg-vault/feedback_content_pass_workflow.md`.

**Что меняем.**
- Grep всех user-facing строк, сравнить с tech-writing нотами и lore.
- Каждый найденный «врёт» — UPDATE base description + inline edit в коде.

**Миграция.** `2026-06-16-100000_ContentPassFixLyingDescriptions.php` — idempotent UPDATE.

**Image-assets.** Нет.

**Smoke / Tests.**
1. Прогон по чек-листу всех recipe descriptions.
2. Все события — описание соответствует фактической механике.

**Tech-writing.** Все затронутые ноты обновить + `feedback_content_pass_workflow.md` дополнить.

**Прод-тег.** `v0.51.208`.

**Зависимости.** S4, S5 (durability+repair описаны в каноне).

**Анонс.** Не нужен (полировка).

---

### S30 — Admin tools + ROADMAP-vNext 🟢

**Цель.** Закрыть фазу: admin craft-tree view, tech-writing back-fill, написать ROADMAP-vNext для следующих 30 сессий.

**Канон.** ADR-009 (constitutional tech-writing).

**Что меняем.**
- `/admin/craft-tree` — визуализация всех 46+ рецептов и их зависимостей.
- Прогон всех `mmorpg-vault/tech-writing/` нот через `last_reviewed` — обновить устаревшие.
- ROADMAP-CRAFT-vNext.md — следующие 30 сессий по итогам обратной связи.

**Image-assets.** Нет.

**Smoke.** HTTP smoke `curl /admin/craft-tree`.

**Tech-writing.** Все ноты `last_reviewed = today`.

**Прод-тег.** `v0.51.209`.

**Зависимости.** Все S1–S29.

**Анонс.** «Roadmap-CRAFT v1 закрыт. Версия v2 — см. ROADMAP-CRAFT-vNext.md».

---

## §10 Image generation budget

### 10.1 Сводная таблица

| Сессия | Картинок | Стоимость | Cumulative |
|---|---|---|---|
| S1 | 0 | $0.00 | $0.00 |
| S2 | 0 | $0.00 | $0.00 |
| S3 | 0 | $0.00 | $0.00 |
| S4 | 1 | $0.04 | $0.04 |
| S5 | 2 | $0.08 | $0.12 |
| S6 | 5 | $0.20 | $0.32 |
| S7 | 0 | $0.00 | $0.32 |
| S8 | 0 | $0.00 | $0.32 |
| S9 | 0 | $0.00 | $0.32 |
| S10 | 4 | $0.16 | $0.48 |
| S11 | 2 (×2 iter) | $0.16 | $0.64 |
| S12 | 2 (×2 iter) | $0.16 | $0.80 |
| S13 | 4 (×2 iter) | $0.32 | $1.12 |
| S14 | 6 (4 buildings ×2 + 4 robots ×1) | $0.32 | $1.44 |
| S15 | 2 (×2 iter) | $0.16 | $1.60 |
| S16 | 1 (×3 iter) | $0.12 | $1.72 |
| S17 | 5 (×2 iter) | $0.40 | $2.12 |
| S18 | 4 (×2 iter) | $0.32 | $2.44 |
| S19 | 3 (×2 iter) | $0.24 | $2.68 |
| S20 | 3 (×2 iter) | $0.24 | $2.92 |
| S21 | 2 (×3+2 iter) | $0.20 | $3.12 |
| S22 | 2 (×3+2 iter) | $0.20 | $3.32 |
| S23 | 2 (×3+2 iter) | $0.20 | $3.52 |
| S24 | 2 (×3+2 iter) | $0.20 | $3.72 |
| S25 | 4 (×2 iter) | $0.32 | $4.04 |
| S26 | 3 (×2 iter) | $0.24 | $4.28 |
| S27 | 0 | $0.00 | $4.28 |
| S28 | 5 (×1 iter) | $0.20 | $4.48 |
| S29 | 0 | $0.00 | $4.48 |
| S30 | 0 | $0.00 | $4.48 |
| **Резерв** | 50% buffer | **$2.24** | **$6.72** |

**Итог: ~$5–7** (с резервом на перегенерации после фейл-картинок от gpt-image-2).

### 10.2 LEXICON additions

В `app/Config/ImageRegistry.php::$lexicon` нужно добавить:

```php
// — Уровни зданий (S11–S15) —
'building.workshop.l2'    => '...',  // см. S11 image-assets
'building.workshop.l3'    => '...',  // см. S11
'building.blast-furnace.l2' => '...', // S12
'building.blast-furnace.l3' => '...', // S12
'building.laboratory.l2'  => '...',   // S13
'building.laboratory.l3'  => '...',   // S13
'building.greenhouse.l2'  => '...',   // S13
'building.greenhouse.l3'  => '...',   // S13
'building.robotics-workshop.l2' => '...', // S14
'building.robotics-workshop.l3' => '...', // S14
'building.teleport-center.l2'   => '...', // S15
'building.teleport-center.l3'   => '...', // S15

// — Роботы T2/T3 (S14) —
'robot.explorer.t2'       => '...',
'robot.explorer.t3'       => '...',
'robot.gatherer.t2'       => '...',
'robot.gatherer.t3'       => '...',

// — Ресурсы (S6, S10) —
'resource.ironstone'      => '...',  // S6
'resource.oil'            => '...',  // S6
'resource.sulfur'         => '...',  // S6
'resource.rare-metals'    => '...',  // S6
'resource.coal'           => '...',  // S6
'resource.fuel-rods'      => '...',  // S10
'resource.pre-collapse-electronics' => '...', // S10
'resource.industrial-plastic' => '...', // S10
'resource.medical-compound' => '...', // S10

// — Стратегические объекты (S21–S24) —
'world.bunker'            => '...',  // S21
'world.bunker.discovered' => '...',  // S21
'world.technopark'        => '...',  // S22
'world.technopark.discovered' => '...', // S22
'world.ghost-city'        => '...',  // S23
'world.ghost-city.discovered' => '...', // S23
'world.island-farm'       => '...',  // S24
'world.island-farm.discovered' => '...', // S24

// — Tier 3 крафт (S16–S20) —
'craft.professional-workbench' => '...', // S16
'craft.master-crossbow'   => '...', // S17 (5 weapons)
'craft.heavy-rebar'       => '...', // S17
'craft.pipe-shotgun'      => '...', // S17
'craft.steel-machete'     => '...', // S17
'craft.scrap-rifle'       => '...', // S17
'craft.plated-armor'      => '...', // S18 (4 armor)
'craft.kevlar-patchwork'  => '...', // S18
'craft.tactical-vest'     => '...', // S18
'craft.hardened-helmet'   => '...', // S18
'craft.synthetic-medicine'=> '...', // S19 (3 medical)
'craft.emergency-transfusion' => '...', // S19
'craft.surgical-kit'      => '...', // S19
'craft.combination-tool'  => '...', // S20 (3 utility)
'craft.portable-forge'    => '...', // S20
'craft.advanced-fishing-net' => '...', // S20

// — Faction-exclusive weapons (S25) —
'craft.bunker-rifle'      => '...',
'craft.techno-beam-shotgun' => '...',
'craft.ghost-city-knife'  => '...',
'craft.farmers-scythe'    => '...',

// — Defensive structures (S26) —
'building.wooden-wall'    => '...',
'building.barbed-fence'   => '...',
'building.watch-tower'    => '...',

// — Repair / durability (S4, S5) —
'loot.tool-broken'        => '...',  // S4
// (S5 reuses existing loot.tool)

// — Сезонные крафты (S28) — placeholders, специфика — по сезону
'craft.seasonal.winter-1' => '...',
'craft.seasonal.winter-2' => '...',
'craft.seasonal.winter-3' => '...',
'craft.seasonal.winter-4' => '...',
'craft.seasonal.winter-5' => '...',
```

**Итого новых LEXICON-записей:** 44.

### 10.3 Hard rules (reminder)

- ZERO TEXT EVER на любой картинке (V2-стиль активирует «выскоблено все, что провоцирует нейронку рисовать буквы»).
- NO real flags, NO fantasy magic, NO sleek sci-fi.
- Все артефакты плёнки на фоне, никогда не на главном объекте.
- Карта мира — НЕ генерируем (GD canvas, фейл-инцидент 2026-05-13).
- Иконки/малые preview — тест на читаемость в Telegram preview (~256px).

---

## §11 Validation cards (полные, для каждой сессии)

> Здесь только полная карточка для **первой сессии каждой фазы** (S1, S6, S11, S16, S21, S26) — в качестве шаблона. Для остальных сессий см. mini-card в §4–§9. Если потребуется полная — копи-пасты шаблон из §2.5 и заполни по mini-card.

### §11.1 Validation Card — S1 (Cleanup legacy Build*Construction)

**Идея:** Удалить ~11 legacy `Build*Construction.php` action-handlers после подтверждения покрытия `GenericBuildingAction`.

**Тип:** refactor
**Подсистемы:** building
**Источник:** ROADMAP-CRAFT.md §4 S1

#### Ворота 0 — Формулировка
- Проблема: legacy дубль 4187 LOC, путает grep, дублирует logic.
- Что меняем: удалить файлы + legacy callback routes.

#### Ворота 1 — Канон
- GAME_DESCRIPTION conflict? **Нет** — контракт зданий не меняется.
- Постапок? Да.
- Тексты правдивы? Да.

#### Ворота 2 — 10-персон
| П | Голос | Комментарий |
|---|---|---|
| П1 | 0 | Не видит |
| П2 | 0 | Не видит |
| П3 | 0 | Не видит |
| П4 | 0 | Не видит |
| П5 | + | Меньше шума |
| П6 | 0 | Не видит |
| П7 | 0 | Не видит |
| П8 | 0 | Не видит |
| П9 | 0 | Не видит |
| П10 | 0 | Не видит |
- Предательства? Нет.
- Вердикт: ✅

#### Ворота 3 — Баланс
- Экономика: ok
- Прогрессия: ok
- Бой: ok
- Тайм-гейты: ok
- Крафт/базы: ok (поведение неизменно)
- Фракции/эндгейм: ok
- Оффлайн: ok

#### Ворота 4 — Техно
- Миграции: нет
- Тесты: existing зелёные
- phpstan: baseline не регрессит (LOC −4187 → возможно −20–50 ignored issues)
- Идемпотентность: N/A
- Tech-writing: `GenericBuildingAction.md` last_reviewed update

#### Ворота 5 — Smoke
- Тип → Telegram smoke (на 12 зданий)
- Шаги: 12 раз нажать «Построить» в Telegram, проверить callback ответ; для одного — довести до completion.

#### Ворота 6 — Релиз
- Stepwise: 1 шаг
- Commit message: «refactor(buildings): F2.1 cleanup — удалены 11 legacy Build*Construction.php (-4187 LOC, generic уже покрывает все 12)»
- Доки: tech-writing нота update.
- ADR: не нужен.
- hot.md / daily: да.
- Анонс: нет.

### ИТОГ: 🟡 — рефактор 0 behavior change, прозрачный smoke-план.

---

### §11.2 Validation Card — S6 (Missing resources)

**Идея:** Добавить 5 ресурсов (Ironstone, Oil, Sulfur, RareMetals, Coal) в `resources` таблицу — закрытие lore tail #4.

**Тип:** content + balance
**Подсистемы:** resources, crafting, building
**Источник:** ROADMAP-CRAFT.md §5 S6

#### Ворота 0 — Формулировка
- Проблема: рецепты ссылаются на не-существующие ресурсы → 🔴 lying.
- Что меняем: миграция вставляет 5 ресурсов + biome→resource mapping.

#### Ворота 1 — Канон
- GAME_DESCRIPTION conflict? Нет — § «Расхождения» уже фиксирует это как open tail.
- Постапок? Да (всё salvaged).
- Тексты правдивы? **Да** после миграции.

#### Ворота 2 — 10-персон
| П | Голос | Комментарий |
|---|---|---|
| П1 | + | Больше mining gameplay |
| П2 | 0 | Не ломает оффлайн |
| П3 | ++ | Канон теперь не врёт |
| П4 | + | Больше PvP-целей за ресурсами |
| П5 | ++ | Полная цепочка крафта работает |
| П6 | + | Endgame резон |
| П7 | + | 5 новых ачив-целей |
| П8 | + | Новые ценники |
| П9 | + | Прозрачные diff-rates по биомам |
| П10 | 0 | Roby опционально объясняет |
- Предательства? Нет.
- Вердикт: ✅

#### Ворота 3 — Баланс
- Экономика: новые ресурсы → новые ценники; S9 cron корректирует.
- Прогрессия: Arsenal (lvl 15) теперь достижим.
- Бой: ok.
- Тайм-гейты: ok (gather в [10min, 12h] коридоре).
- Крафт: новые рецепты в Arsenal — есть.
- Фракции: будет влиять на Engineers через RareMetals; ok.
- Оффлайн: ok.

#### Ворота 4 — Техно
- Миграция: новая, idempotent UPSERT.
- Тесты: 3 новых.
- phpstan: ok.
- Идемпотентность: yes.
- Tech-writing: `mmorpg-vault/tech-writing/db/resources.md` + `lore/world/Биомы.md`.

#### Ворота 5 — Smoke
- DB-row smoke: после migrate — gather на каждом из 4 биомов → выпадает каждый из 5 ресурсов с нужной вероятностью.
- Idempotent test: rerun migrate, 0 changes.

#### Ворота 6 — Релиз
- Stepwise: 1 step (миграция атомарна).
- Commit message: «content(resources): +5 канон-ресурсов (Ironstone/Oil/Sulfur/RareMetals/Coal), биом-маппинг (закрывает lore tail #4)».
- Доки: GAME_DESCRIPTION таблица расхождений — отметить #4 как ✅; lore/world/Биомы.md — обновить.
- ADR: ADR-025-Missing-canonical-resources.md (новый).
- hot.md / daily: да.
- Анонс: да — «Канон догнал реальность: Железная руда, Сырая нефть, Сера, Редкие металлы, Уголь добываются».

### ИТОГ: 🟠 — закрытие канон-расхождения с балансовым влиянием, ADR обязателен.

---

### §11.3 Validation Card — S11 (Workshop L2/L3)

**Идея:** Активировать L2 и L3 апгрейды Workshop — +10%/+25% скорости общего крафта.

**Тип:** content (apgrade contract)
**Подсистемы:** building, crafting
**Источник:** ROADMAP-CRAFT.md §6 S11

#### Ворота 2 — 10-персон
| П | Голос | Комментарий |
|---|---|---|
| П1 | + | Прогрессия видимая |
| П2 | + | Долгие задачи дружелюбны оффлайну |
| П3 | + | Канон обещал улучшение |
| П4 | 0 | Не влияет на PvP |
| П5 | ++ | Глубина прогрессии |
| П6 | + | Endgame +200 очков за апгрейд |
| П7 | + | Видимый новый contentbar |
| П8 | + | Sink ресурсов = инфл-контроль |
| П9 | + | Прозрачный multiplier |
| П10 | 0 | Уровень 1 — пока не видит |
- Предательств: нет.
- Вердикт: ✅

#### Ворота 3 — Баланс
- Краф-время Bandage L1=15min → L2=13.5min → L3=11.25min. Сбалансировано.
- Sink ресурсов на апгрейд = sink для late-game игроков; П8 одобряет.

### ИТОГ: 🟡

---

### §11.4 Validation Card — S16 (T3 Professional Workbench, ADR-026)

**Идея:** Tier 3 верстак — расширение канона за пределы 2-уровневой системы.

**Тип:** content (canon extension)
**Подсистемы:** crafting

#### Ворота 1 — Канон
- GAME_DESCRIPTION conflict? **Частично** — канон описывает 2-tier систему, T3 — расширение. **ADR-026 обоснует**.
- Постапок? Да (heavy hand-built workbench).
- Тексты правдивы? Да.

#### Ворота 2 — 10-персон
| П | Голос |
|---|---|
| П1 | ++ depth |
| П2 | 0 high-level, оффлайн ok |
| П3 | + canon extended consciously |
| П4 | + T3 weapons → PvP relevance |
| П5 | ++ deep progression unlocked |
| П6 | + endgame relevance |
| П7 | ++ new tier to chase |
| П8 | + new sink + premium prices |
| П9 | + transparent multiplier |
| П10 | 0 too high level |
- Предательств: нет.

### ИТОГ: 🟠 — расширение канона требует ADR-026 + анонс.

---

### §11.5 Validation Card — S21 (Bunker + ADR-027)

**Идея:** Сделать Bunker реальным открываемым объектом мира.

**Тип:** content (endgame infrastructure)
**Подсистемы:** world, endgame

#### Ворота 2
| П | Голос |
|---|---|
| П1 | ++ exploration urgency |
| П2 | 0 long-term goal, no FOMO |
| П3 | ++ канон обещал, реализуем |
| П4 | + цель захвата |
| П5 | + tied to T3 |
| П6 | ++ faction race becomes real |
| П7 | ++ achievement target |
| П8 | + rare resource source |
| П9 | + transparent quest reward |
| П10 | 0 high-level only |
- Предательств: нет.

### ИТОГ: 🟠 — endgame mechanic (ADR-027) + анонс.

---

### §11.6 Validation Card — S26 (Defensive structures)

**Идея:** Ввести стены/колючку/башню для defending бази.

#### Ворота 2
| П | Голос |
|---|---|
| П1 | + риск/реворд балансирован |
| П2 | + лучшая защита оффлайна |
| П3 | + канон обещает Военных |
| П4 | **−/+** Меняет PvP экономику — нужен расчёт |
| П5 | ++ новые цепочки |
| П6 | + клановая защита |
| П7 | + 3 новых сооружения |
| П8 | + sink |
| П9 | + прозрачные множители |
| П10 | 0 high-level |
- П4 голос смешан — нужен балансовый чек (ADR-030 включит математику).

### ИТОГ: 🟠 — затрагивает PvP-экономику, требует ADR-030.

---

### §11.7 Краткие mini-cards для S2–S5, S7–S10, S12–S15, S17–S20, S22–S25, S27–S30

Уже описаны в §4–§9 (секция «Validation-card mini» каждой сессии). Они достаточны для классификации; полные карточки только при необходимости — для 🟠 сессий ADR покроет.

---

## §12 Финальные замечания

### 12.1 Что НЕ вошло в roadmap (и почему)

- **5-я фракция.** 🔴 — ломает 1:1 mapping faction↔scenario. Возможно в ROADMAP-vNext с full ADR.
- **Permadeath режим.** 🟠 — opt-in only, не дефолт. Может появиться в S30+ vNext.
- **Crafted items как currency / market for crafted goods.** 🟠 — балансовый риск (П8 inflation). Vault — потенциальный S31+.
- **Glow / particles / sleek sci-fi visuals.** 🔴 — нарушает image style. Все картинки строго в каноне «Найденная фотоплёнка».
- **Magic-системы крафта (зелья из канона D&D и т.п.).** 🔴 — ломает постапок-сеттинг.

### 12.2 Открытые вопросы для пользователя

1. **S5 Repair cost.** Я заложил 50% от ресурсов. Альтернатива — 30% (агрессивнее) или 70% (консервативнее). Решит Андрей.
2. **S10 Rare resources sources.** Я предложил «only events + strategic objects». Хочешь ли разрешить gather из rare biome-spots (например, Caves деep node)?
3. **S16 T3 Workbench level требование.** Я заложил персонажа L16. Хочешь L20 для большего gap'а L13–L20?
4. **S26 Defensive structures damage cap.** Я не зафиксировал точные числа. ADR-030 нужно проектировать с конкретной формулой урон-снижения.
5. **S28 Сезонность.** 3 сезона/год по 14 дней — достаточно? Или 4 сезона по 21 дню?
6. **Image-генерации.** Хочешь все 44 LEXICON-картинки сразу после S1–S5, или per-session по факту?
7. **ROADMAP-vNext в S30** — приоритетные направления (на ваш выбор): farming (земледелие как процесс), exotic biomes (новые), pvp-arena, faction-войны, world events redesign?

### 12.3 Mермейд-диаграмма последовательности фаз (опционально)

```
gantt
    title 6 фаз × 5 сессий
    dateFormat  YYYY-MM-DD
    axisFormat  %Y-%m-%d
    section Phase 1 — Foundation
    S1 :2026-05-18, 1d
    S2 :2026-05-19, 1d
    S3 :2026-05-20, 1d
    S4 :2026-05-21, 1d
    S5 :2026-05-22, 1d
    section Phase 2 — Resources
    S6  :2026-05-23, 1d
    S7  :2026-05-24, 1d
    S8  :2026-05-25, 1d
    S9  :2026-05-26, 1d
    S10 :2026-05-27, 1d
    section Phase 3 — Building Upgrades
    S11 :2026-05-28, 1d
    S12 :2026-05-29, 1d
    S13 :2026-05-30, 1d
    S14 :2026-05-31, 1d
    S15 :2026-06-01, 1d
    section Phase 4 — T3 Craft
    S16 :2026-06-02, 1d
    S17 :2026-06-03, 1d
    S18 :2026-06-04, 1d
    S19 :2026-06-05, 1d
    S20 :2026-06-06, 1d
    section Phase 5 — Endgame Faction
    S21 :2026-06-08, 1d
    S22 :2026-06-09, 1d
    S23 :2026-06-10, 1d
    S24 :2026-06-11, 1d
    S25 :2026-06-12, 1d
    section Phase 6 — Polish + vNext
    S26 :2026-06-13, 1d
    S27 :2026-06-14, 1d
    S28 :2026-06-15, 1d
    S29 :2026-06-16, 1d
    S30 :2026-06-17, 1d
```

(Калькуляция в днях для AI-pacing — НЕ для людей. Календарь — только timestamp.)

---

**Конец ROADMAP-CRAFT.md v1.0**
