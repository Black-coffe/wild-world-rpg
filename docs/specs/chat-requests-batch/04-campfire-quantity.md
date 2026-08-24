---
story: chat-requests-batch-04
spec: chat-requests-batch
status: done
tier: 1
worker: worker-code
tracer: false
wave: 1
blocked_by: []
---

# Готовка на костре с выбором количества

## Goal
Выбрав блюдо на костре, игрок выбирает количество — как в обычном крафте, — а не
запускает по одной порции за раз.

## Requirements
> [18.08.2026] Анжела: «Так надо просто ввести возможность при крафте на костре сразу выбирать количество. Желаете скрафтить тушняк? Выберите количество: 1шт; 5шт; 50шт и т.д.»

## Files
- app/Controllers/Telegram/Commands/Actions/Craft/Cooking/CampfireCookingSelect.php
- tests/unit/Craft/CampfireQuantityTest.php

## Notes
Механизм уже есть: `GenericCraftActionStart` разбирает `genericCraft_<Key>_<qty>` и
умножает ресурсы, золото и время на `qty`. Экран костра сегодня жёстко шлёт `_1`
(строка ~151). Нужен промежуточный шаг выбора количества по образцу обычного крафта,
включая «своё число».
Готовка — 🔒-задача (`parallel_execution_allowed = 0`), поэтому гейт ADR-167
отрабатывает как прежде; следить, чтобы новый шаг его не обошёл.

## Non-goals
- Не менять рецепты, время и стоимость готовки.
- Не трогать обычный крафт и его экран выбора количества.
- Не снимать 🔒-статус готовки ради удобства пачки.

## Acceptance criteria
- [ ] Создан и зелёный `tests/unit/Craft/CampfireQuantityTest.php` — он обязан падать на доправочном поведении,
      иначе гейт зелёный впустую (урок «скан исходника ≠ покрытие»).
- [ ] `vendor/bin/phpunit --no-coverage --no-progress` целиком зелёный.

## Verification
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress app/Controllers/Telegram/Commands/Actions/Craft/Cooking/CampfireCookingSelect.php`

## Findings

Реализовано: клик по блюду теперь открывает промежуточный шаг выбора количества
(`cook_qty_<Key>` / `cookPreserves_qty_<Key>`), а не сразу стартует крафт одной
штуки (`genericCraft_<Key>_1`). Шаг количества показывает кнопки
`QUANTITY_STEPS = [1, 5, 10, 25, 50, 100]` — ступени сверены рефлексией с
`WoodMaterialsCraft1Action::$craftQuantities` (самый распространённый набор среди
~30 файлов обычного крафта, не «своя» выдумка и не 1/5/50 из дословной
формулировки заявки), каждая ведёт на уже существующий
`genericCraft_<Key>_<qty>` → `GenericCraftActionStart` сам умножает
ресурсы/золото/время — логика не задублирована.

Роутинг: `CallbackqueryCommand` резолвит хендлер по ПЕРВОМУ сегменту
callback_data (`explode('_', $data)[0]`), поэтому `cook_qty_<Key>` и
`cookPreserves_qty_<Key>` продолжают попадать в `CampfireCookingSelect` через
уже зарегистрированные exact-роуты `cook`/`cookPreserves` — `CallbackRoutes.php`
трогать не понадобилось (проверено тестом `testQuantityStepCallbackKeepsRoutableFirstSegment`).

ADR-167 (🔒 не стартует поверх 🔒): новый шаг — чистый рендер меню
(`handleQuantityStep()`), никаких INSERT/task-создающего кода в нём нет —
гейт занятости как и раньше отрабатывает только в `GenericCraftActionStart`
на клике по кнопке количества. Это видно из диффа (метод не трогает
`character_tasks`/модели задач), но полноценный DB-регресс-тест через реальный
`handle()` не поставлен — см. ниже про ограничение окружения.

**Скоуп-отклонение (сообщаю, не молчу):** «своё число» из Notes/брифа НЕ
реализовано. Требует ForceReply-перехвата ответа игрока — единственная точка,
где это делается в проекте (`GenericmessageCommand::execute()`), работает по
явным маркерам в тексте промпта (`SELL:123`, `✍ NAME`, `RelocationRequestService::PROMPT_MARKER`)
и НЕ обобщена: для готовки понадобилась бы новая ветка в этом файле, а он вне
`## Files` этой story. Ставить кнопку «Своё число», которая никуда не ведёт
(без правки `GenericmessageCommand.php`), — само по себе баг (правило проекта
«ни одной кнопки без действия»), поэтому кнопку не добавил вовсе. Нужно
решение владельца: либо расширить Files этой story, либо завести
follow-up-story на добавление маркера `🔥 COOK_QTY:<key>` в
`GenericmessageCommand`.

**Тест-стратегия:** `tests/unit/Craft/CampfireQuantityTest.php` бьёт по новым
чистым статическим методам (`parseCallback`, `dishStepCallback`,
`quantityButtons`, `renderQuantityText`) — до фикса ни один не существовал
(`Undefined constant QUANTITY_STEPS`, `Call to undefined method ...` ×6) —
проверено `git stash` диффа файла + прогон (7 ошибок) + `git stash pop` +
прогон (7 тестов, 36 assertions, зелёные). Полный end-to-end `handle()` с
реальным `CallbackQuery` НЕ прогонялся: экран фото-based
(`Request::encodeFile(base_url(...))`), а `phpunit.xml.dist` намеренно ставит
`app.baseURL=http://example.com/` для тестов — попытка реально уйти в сеть за
картинкой (`fopen(...): HTTP/1.1 404 Not Found`), падает и на ДОФИКСОВОМ
`handle()` тоже (это не регрессия этой story, это существующее ограничение
среды — единственный найденный в репо прецедент «реальный `CallbackQuery` +
реальный `handle()`», `VehicleCraftWiringTest`, бьёт по text-only экрану
`CraftedResourcesAction`, не photo-экрану). Рендер caption/кнопок в живом
Telegram — Tier-3 smoke (правило `telegram-ux.md`), не запускался в рамках
этой сессии.

**Полный `phpunit` НЕ целиком зелёный на момент сдачи** — но не из-за этой
story: `git stash`/`git status` показывают параллельные изменения от других
воркеров в этой же сессии (Robots/MarchAction/RobotService и др.), а падения
(`BiomeGatherProfileServiceTest`, `CraftInsuranceServiceTest`,
`GreenhouseProductionWaterTest`, `BaseStorageRetrieveTest`, ...) идут по
файлам, которые эта story не трогала и не упоминает; в общем прогоне
`CampfireQuantityTest`/`CampfireCookingSelect` не встречаются ни разу. Похоже
на гонку за общей `wildworld_tests` (несколько сессий пишут в одну БД
одновременно). `CampfireQuantityTest.php` в изоляции — зелёный
(7/7, 36 assertions).
