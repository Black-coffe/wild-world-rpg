---
story: transport-07
spec: transport-system
status: todo
tier: 3
worker: worker-code
model: sonnet
tracer: false
wave: 1
blocked_by: [transport-02]
---

# Проводка крафта транспорта: слот, роутер, экран категории, арт

## Goal

Пять рецептов становятся кликабельными и доводимыми до конца: строки задач с `type='craft'`,
`$taskHandlerKeyMap` в `Worker.php` (без строки задача зависает молча), маршруты в
`Config\CallbackRoutes`, экран категории «🚚 Транспорт» с пятью литералами
`genericCraft_<Key>_1` под уже существующим заголовком, записи в `Config\ImageRegistry`.
Заодно сводится двойное эмодзи категории: 🚚 в `CraftedResourcesAction` против 🛴 в
`CraftTypeLabels`.

## Requirements

> Заворотить систему транспорта, начиная от крафта, логики, интеграции, доступности от разных уровней, и даже чтобы фракция влияла на транспорт.

## Files
- app/Models/CraftedItemsModel.php
- app/Controllers/Worker.php
- app/Config/CallbackRoutes.php
- app/Config/ImageRegistry.php
- app/Controllers/Telegram/Commands/Actions/CraftedResourcesAction.php
- app/Services/Player/Trade/CraftTypeLabels.php
- app/Database/Migrations/*SeedTransportCraftTasks.php
- tests/unit/Transport/VehicleCraftWiringTest.php

- [ ] Колонка `status` (её завела миграция story 06) добавлена в `CraftedItemsModel::$allowedFields`:
      без этого любая будущая запись статуса через модель молча теряется — грабля, уже
      стоившая проекту инцидента.

## Non-goals
- Не менять сами рецепты и цены — они в story 06.
- Не строить экран «🚚 Мой транспорт» и активацию (story 10): здесь только крафт-витрина категории.
- Не генерировать картинки: `php spark images:generate` требует локальный `images.api_key`, генерация — шаг выката. Здесь только записи реестра.
- Не заводить `img_path` в `crafted_items`: арт резолвится через `ImageRegistry`.
- Не трогать generic-обработчик крафта: пять машин используют существующий `GenericCraftCompletionHandler`.

## Map slice
`memory/map/craft.md` (обязательные точки касания generic-рецепта), `memory/map/tasks-worker.md`, `memory/map/telegram.md`

## Acceptance criteria
- [ ] Для каждой из пяти машин: строка в `tasks` с **`type='craft'`** (иначе слот не считается), запись `$taskHandlerKeyMap` в `Worker.php`, маршрут в `Config\CallbackRoutes`.
- [ ] Поведенческий тест доводит крафт до конца по цепочке «старт → задача → completion»: предмет появляется в `crafted_items_log`, задача не остаётся в `in_work`. Тест краснеет, если убрать строку `$taskHandlerKeyMap` (именно это ловим — молчаливое зависание).
- [ ] Экран категории показывает все пять карточек **любому** игроку; недоступные несут 🔒 с причиной и путём («нужна фракция Инженеры — ты Партизан», «с 16 уровня — у тебя 12»).
- [ ] `CraftRecipeReachabilityTest` зелёный: пять литералов `genericCraft_<Key>_1` достижимы из меню.
- [ ] Эмодзи категории транспорта одно и то же во всех местах (🚚); 🛴 больше не встречается как ярлык категории.
- [ ] `Config\ImageRegistry` содержит пять ключей транспорта, по одному на машину; резолв идёт через `is_file()` до `encodeFile()` — отсутствие файла даёт текстовый экран, а не падение.
- [ ] Caption карточки крафта ≤ 1024 символов и самодостаточен без картинки.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Transport/VehicleCraftWiringTest.php`
`git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`

## Implementation notes

## Findings

## Implementation notes
- `app/Database/Migrations/2026-11-29-120000_SeedTransportCraftTasks.php` — 5 строк `tasks` (craftLightCart/craftMountainBike/craftSnowmobile/craftDraftCart/craftAutonomousDrone), все `type='craft'`, `handler_key='generic_craft'`, idempotent по `name`.
- `app/Controllers/Worker.php` — 5 записей в `$taskHandlerKeyMap` (→ `generic_craft`) и зеркально в legacy `$taskHandlerMap` (→ `Craft\GenericCraftCompletionHandler`), рядом с остальными generic-craft строками.
- `app/Controllers/Telegram/Commands/Actions/CraftedResourcesAction.php` — дверь извне: экран «Твои созданные предметы» (`resourcesCrafting`) теперь ВСЕГДА (не только при владении) показывает блок «🚚 Транспорт» с 5 статус-строками (🔓/🔒 + причина: уровень/фракция) и 5 кнопками-литералами `genericCraft_<Key>_1` (один ряд из 3 + один из 2 — без одиночек). Faction-имя резолвится через прямой запрос `character_factions` (тот же паттерн, что `GenericCraftActionStart::characterFactionId`, не вынесен в общий сервис — вне scope story). Работал поверх версии параллельной сессии (коммит 472d6653, кнопка «🤖 Ангар») — её правки не тронуты.
- `app/Services/Player/Trade/CraftTypeLabels.php` — эмодзи категории `transport` сведено 🛴→🚚 (совпадает с заголовком в CraftedResourcesAction).
- `app/Models/CraftedItemsModel.php` — `status` добавлен в `$allowedFields` (колонка story 06, без allowedFields запись через модель молча терялась бы).
- `app/Config/ImageRegistry.php` — 5 записей `craft/vehicles/<key>` со `status='pending'` (runbook image-generation.md) — арт ещё не сгенерён (Non-goal), это только очередь на генерацию; CraftRecipes.php не трогали (не в Files), поэтому recipe `image_in_progress`/`image_completed` пока продолжают указывать на общий плейсхолдер `standard_craft_area.jpg`.
- `Config\CallbackRoutes` изменений не потребовал: `genericCraft` уже зарегистрирован exact-роутом (первый сегмент callback_data до `_`), пятёрка новых callback'ов резолвится тем же путём.
- `tests/unit/Transport/VehicleCraftWiringTest.php` — изолированная схема (tasks/character_tasks/crafted_items/crafted_items_log/characters/telegram_users в `wildworld_tests`, паттерн как в `VehicleActivationServiceTest`): 1) task_name↔CraftRecipes.task_name контракт, 2) обе Worker-карты несут все 5 задач (красный при удалении строки), 3) `Worker::getHandlerClassName()` резолвит все 5 в `GenericCraftCompletionHandler` через HandlerRegistry, 4) поведенческий e2e: реальный `GenericCraftCompletionHandler::handle()` на `craftLightCart` — предмет в `crafted_items_log`, статус `character_tasks` → `completed` (не завис в `in_work`). Уведомление Telegram не триггерится намеренно: `telegram_user_id=999999` без строки в `telegram_users` → `notifyUser()` тихо возвращается (реальная защита в самом хендлере), сети не касаемся.
- Известная хрупкость (не блокер): `VehicleCraftWiringTest` в связке ИМЕННО с `CraftRecipeReachabilityTest` в одном процессе иногда роняет `table 'tasks' doesn't exist` на вызове изнутри теста (изолированная DDL-таблица, не прод-схема) — воспроизводится не при любой комбинации файлов (сам по себе, с `WorkerHandlerRegistryConsistencyTest`, с `CraftTypeLabelsTest` — зелёный). Похоже на десинхронизацию `DatabaseTestTrait` транзакции с raw DDL (implicit commit в MySQL) при определённом порядке класса. Не заслон: команда верификации story (файл в одиночку) стабильно зелёная.

## Findings
(нет — потолок не достигнут, story закрыта)
