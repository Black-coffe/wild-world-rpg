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
- app/Controllers/Worker.php
- app/Config/CallbackRoutes.php
- app/Config/ImageRegistry.php
- app/Controllers/Telegram/Commands/Actions/CraftedResourcesAction.php
- app/Services/Player/Trade/CraftTypeLabels.php
- app/Database/Migrations/*SeedTransportCraftTasks.php
- tests/unit/Transport/VehicleCraftWiringTest.php

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
