---
story: w2-n1-hud-character-02
spec: w2-n1-hud-character
status: done
returned: DONE
tier: 2
worker: worker-code
model: opus
wave: 2
blocked_by: [w2-n1-hud-character-01]
---

# Модель инвентаря и нативный единый список

## Goal
`InventoryViewService::forCharacter(int $id)` отдаёт модель инвентаря: всё, что несёт персонаж
(добытые ресурсы, крафтовые ресурсы и предметы), с категорией, количеством, редкостью и доступными
действиями. `ResourcesGatheredAction` и `CraftedResourcesAction` бота берут списки из модели (навигация
бота и хаб `InventoryAction` не меняются). В вебе `view=inventory` — единый список с вкладками по
категориям и поиском по имени; без JS вкладки работают через параметр, поиск — улучшение в JS.

## Requirements
> 3. «Инвентарь» — одна модель инвентаря в сервисе; в вебе единый список всего, что несёт игрок, с вкладками по категориям и поиском по имени (без JS список работает, фильтр — улучшение); списки ресурсов в боте берут данные из той же модели.

## Files
- app/Services/Player/InventoryViewService.php
- app/Controllers/Telegram/Commands/Actions/ResourcesGatheredAction.php
- app/Controllers/Telegram/Commands/Actions/CraftedResourcesAction.php
- app/Services/Web/WebNativeScreenService.php
- app/Views/site/_play/native_inventory.php
- app/Views/site/_play/native_me.php
- public/assets/js/wildworld-play.js
- public/assets/css/wildworld-ui.css
- public/ui-kit.html
- app/Views/site/_layout/meta.php
- tests/unit/Services/Player/InventoryViewServiceTest.php
- tests/unit/Display/CraftedItemTypeHeadingCoverageTest.php
- tests/database/PlayViewControllerTest.php
- tests/unit/Craft/CraftedResourcesFoodMarkerTest.php
- phpstan-baseline.neon

## Non-goals
- Склад базы, продажа, «Куда ушло» — не нативно (мост); склад — N6.
- Не менять сортировку `InventorySortService` для бота, только переиспользовать.
- Не вводить вес или ёмкость как новую механику: показывать, только если модель уже её знает.

## Map slice
`memory/map/player.md` (инвентарь); `memory/map/craft.md` (крафтовые ресурсы).

## Acceptance criteria
- [ ] Списки бота «Добытые» и «Крафтовые» показывают те же позиции и количества, что до рефакторинга.
- [ ] Веб-список показывает то же, что бот; вкладки работают без JS; поиск фильтрует на месте.
- [ ] Пустой инвентарь и пустая вкладка — понятное состояние с подсказкой, откуда брать ресурсы.
- [ ] Компонент списка — в `ui-kit.html`; на 375 px нет горизонтального скролла.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress && vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes
- Модель: InventoryViewService - gathered()/crafted() (сырые строки, те же SELECT, что были в handler'ах), build() - единый список с полками (resources + типы крафта по CRAFTED_TYPES, неизвестный - other), пометка еды, стоимость только добытого. Карта полок, FOOD_MARKER и FOOD_PATH_LINE переехали в модель - их читают бот и веб.
- Бот: ResourcesGatheredAction и CraftedResourcesAction берут данные из модели; рендер, сортировка InventorySortService, клавиатуры и хаб InventoryAction без изменений.
- Веб: view=inventory - полки подряд; вкладки без JS - якоря к полкам (Play.php не в Files story, параметр вкладки не заводили), с JS - фильтр на месте + поиск по имени (строка поиска скрыта без JS). Пустой рюкзак - lock-блок с путём: «🧑 Я» → «🧑‍🌾 Действия 🛠️» → добыча; предметы - «🔨 Крафт». «🎒 Инвентарь» на нативном «Я» открывает нативный экран, а не мост.
- Мост обобщён: BRIDGE_ROUTES - путь от карточки «Я» до сообщения бота с кнопкой (Склад базы / Куда ушло / Все мои ресурсы: карточка → inventory → кнопка; intent :card, :s0, :cb).
- Сверх Files: гейт CraftedItemTypeHeadingCoverageTest парсил карту из исходника handler'а - переведён на InventoryViewService::CRAFTED_TYPES; PlayViewControllerTest (story 01) - тест моста жал inventory, который стал нативным, добавлены тесты инвентаря и маршрута; из baseline убраны 6 записей удалённых свойств/конструкторов.
- CraftedResourcesFoodMarkerTest собирал handler через Reflection и подставлял удалённое свойство craftedItemsLogModel (первый прогон close-story упал на этом); строки теперь берёт из InventoryViewService::crafted() на тестовом соединении, рендер - прежними private-методами. Локально его маскировала ещё и ошибка окружения (остатки таблиц characters/tasks в wildworld_tests).
- Проверено: phpstan L9 OK; PlayView/PlayController/InventoryView/CharacterSheet - 49 тестов OK.

## Findings
