---
story: transport-06
spec: transport-system
status: todo
tier: 3
worker: worker-code
model: sonnet
tracer: false
wave: 1
blocked_by: [transport-02]
---

# Пять машин: рецепты, гейты уровня и фракции, чистка каталога

## Goal

Категория `type='transport'` перестаёт быть BUILT-BUT-DEAD: пять позиций получают рецепты в
`Config\CraftRecipes` (generic-путь, без custom Recipe-классов — машины отличаются данными,
а не поведением), с гейтами `required_level` и `required_faction`. Каталог приводится в
порядок: «Конная повозка» → «Тягловая повозка», Воздушный шар → `status: deprecated`,
цены сверены с анти-эксплойтом ADR-157.

## Requirements

> То есть должно быть минимум три-четыре вида: один общий для всех фракций и остальные уникальные для разных фракций.

> начиная от крафта, логики, интеграции, доступности от разных уровней, и даже чтобы фракция влияла на транспорт

## Files
- app/Config/CraftRecipes.php
- app/Database/Migrations/*TransportCatalogCleanup.php
- tests/unit/Transport/VehicleRecipesTest.php

## Non-goals
- 🔴 Ничего не удалять из `crafted_items_log`: 21 строка владения от бага PvE-крана — грандфазер по решению владельца.
- Не трогать Плот, Лодку с мотором, Парусник (заморожены за будущим водным ADR) и Верблюда (пустынная линия позже).
- Не переносить легаси-поле `required_resources` каталога: оно ссылается на несуществующие «Wooden Beams», «Rope», «Wheels», «Horse», «Rubber», «Propane Burner». Рецепты авторятся заново.
- Не класть числа бонусов в рецепт: скорость/усталость/груз живут в `world.vehicle.*` (story 02). В рецепте — только вход, время, гейты, цена.
- Не писать custom Recipe-класс по образцу `PortableTeleportRecipe`: пять копий одного completion-handler'а не нужны.
- Не регистрировать callback и не править `Worker.php` — это story 07.

## Map slice
`memory/map/craft.md` (generic-путь, поля гейтов, грабли), `memory/map/data-layer.md`

## Acceptance criteria
- [ ] Пять рецептов с ключами `LightCart`, `MountainBike`, `Snowmobile`, `DraftCart`, `AutonomousDrone`; гейты: 6/нет фракции, 12/Партизаны(2), 14/Милитари(1), 14/Фермеры(4), 16/Инженеры(3).
- [ ] Вход каждого рецепта собран **только** из реально существующих ресурсов и компонентов: Минералы, Редкие металлы, Кристаллы, Солнечные камни, Нефть, Промышленный пластик, Шкура/Кожа животных, Древесина; Проводка, Электронные компоненты, Ткань, Металл фрагменты, Каменные блоки, Материал из древесины, Угольные брикеты. Тест проверяет, что каждое имя входа резолвится в существующую строку каталога.
- [ ] Тематика входа совпадает с именем и лором машины (урок «предмет = 4 источника правды»): повозка — древесина + ткань; велосипед — древесина + шкура; снегоход — нефть + металл-фрагменты; тягловая повозка — древесина + шкура/кожа (свой продукт Фермеров); дрон — проводка + электронные компоненты + солнечные камни.
- [ ] 🔴 Анти-эксплойт ADR-157 для **каждого** из пяти: `price × 1.10 ≤ gold_required + стоимость сырья`. Тест считает это по каталогу; при промахе правится `gold_required` или `crafted_items.price` миграцией, а не комментарием.
- [ ] Гейт enforced на **старте** крафта, не только в превью: прямой `genericCraft_<Key>_1` от чужой фракции или низкого уровня → отказ (тест зовёт путь старта, а не рисует превью).
- [ ] `agility_bonus`/`intellect_bonus` заданы (обязательны при `output_type=crafted_item`).
- [ ] Миграция каталога: «Конная повозка» → «Тягловая повозка» (переименование, не новая строка); Воздушный шар получает `status: deprecated`; миграция идемпотентна и **не удаляет ни одной строки**.
- [ ] Тест краснеет, если удешевить любой рецепт ниже порога ADR-157.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Transport/VehicleRecipesTest.php`
`git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`

## Implementation notes

- `app/Config/CraftRecipes.php`: пять новых записей `LightCart`/`MountainBike`/`Snowmobile`/
  `DraftCart`/`AutonomousDrone`, `item_name_eng` 1:1 с ключом (контракт story 04). Вход собран
  из реально существующих ресурсов/компонентов (сверено на testbot 2026-08-19), никаких
  легаси-имён. `gold_required` не задан ни у одной — входа ресурсов/компонентов хватает на
  ADR-157 с запасом. Картинки — временный плейсхолдер `standard_craft_area.jpg` (у транспорта
  своего арта ещё нет, story 07/ImageRegistry заменит). `info_callback` намеренно не задан —
  экрана категории транспорта в крафт-меню ещё нет (story 07).
- `app/Database/Migrations/2026-11-29-100000_TransportCatalogCleanup.php`: добавляет
  `crafted_items.status` ENUM(active/deprecated); id 48 «Воздушный шар» → deprecated; id 46
  «Конная повозка»/«Horse Cart» → «Тягловая повозка»/«DraftCart»; id 43/47/49 `name_eng`
  нормализован без пробела под контракт. Ничего не удалено (проверено dry-run транзакцией на
  локальной БД — 11 строк type='transport' до и после). WipeManifest не тронут — `crafted_items`
  уже KEEP, новая колонка не player-связана.
- `tests/unit/Transport/VehicleRecipesTest.php`: изолированная схема (паттерн соседнего
  `VehicleActivationServiceTest`, НЕ общая `wildworld_tests` — та мигрируется параллельными
  сессиями). ADR-157 и «ингредиент реален» проверяются по значениям, вручную сверенным на
  testbot (см. докблок класса), а не по live-подключению — детерминировано, не зависит от
  состояния расшаренной тестовой БД. Гейт «enforced на старте» — тест зовёт РЕАЛЬНЫЙ приватный
  `GenericCraftActionStart::characterFactionId()` через reflection на фикстуре БД (тот же
  читатель, которым `handle()` резолвит фракцию в строке required_faction-проверки), а не
  `handle()` целиком: последний бьёт в Telegram API и на CI без ключа падает
  `TelegramException` до gate-проверки (см. memory `feedback_taskhandler_telegram_init_in_tests`).
  Это честный, но частичный тест старта — не end-to-end вызов Action.
- Локальная `mmorpg`-БД оказалась устаревшим дампом (нет `wiring`/`electronicComponents` в
  `crafted_items`, `tasks` таблица без строк для новых рецептов) — сверка велась по testbot
  через SSH, не по локальной БД.
- `tasks`-строки (`craftLightCart` и т.д.) для этих пяти рецептов НЕ созданы — это explicit
  scope story 07 (`plan.md`: «строки tasks»). До story 07 реальный крафт стартовать не может
  (`GenericCraftActionStart` упадёт на «Задача не найдена в базе»), это ожидаемо на этой стадии
  волны.

## Findings
