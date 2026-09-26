---
story: w2-n1-hud-character-02
spec: w2-n1-hud-character
status: todo
returned:
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

## Findings
