---
story: craft-shortfall-buy-fix-09
spec: craft-shortfall-buy
status: done
tier: 3
worker: worker-code
tracer: false
wave: 6
blocked_by: []
---

# Семь легаси-рецептов зарегистрированы в конфиге — докупка у них заработала

## Goal
Броня и маяки показывают экран нехватки и замок, но докупка у них не работает: обработчик сделки
ищет рецепт в конфиге, а этих семи там нет. Решение владельца — зарегистрировать их, чтобы охват
«для всего абсолютно крафта» был закрыт делом, а не оговоркой.

## Requirements
> для всего абсолютно крафта (ТОЛЬКО КОРАФТ) строения не входят сюда
> сделать возможность крафтить даже если недостаточно какого-то материала или компонента для крафта вещи

## Files
- app/Config/CraftRecipes.php
- tests/unit/Config/CraftRecipesTest.php

## Non-goals
- Не переводить сами стартовые классы на общую точку старта: их успешный путь остаётся своим.
- Не менять требования, стоимость и время сборки этих рецептов — переносим как есть.
- Не трогать `CraftShortfallBuyAction`, `CraftShortfallBuyService`, `CraftShortageService`.

## Map slice
memory/map/craft.md; `.claude/rules/telegram-ux.md`, `.claude/rules/balance.md`.

## Acceptance criteria
- [x] Все семь рецептов резолвятся `Config\CraftRecipes::get()` по тем ключам, которые их стартовые классы уже кладут в `craft_again_callback`.
- [x] Требования (сырьё, компоненты, золото, уровень) перенесены **точно** как в стартовых классах — сверено поштучно, расхождений нет.
- [x] Кнопка докупки у этих рецептов появляется и ведёт в рабочую сделку, а не в «Неизвестный рецепт».
- [x] Успешный путь старта этих семи не изменился.
- [x] Тест краснеет, если любой из семи ключей перестанет резолвиться.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Config/ tests/unit/Services/Craft/`

## Notes
Решение владельца 2026-08-21 в ответ на критическую C1 повторного ревью: «Зарегистрировать семь рецептов». Прежняя формулировка плана про полный охват становится правдой.

## Implementation notes
- `app/Config/CraftRecipes.php` — семь новых ключей добавлены в конец `$recipes`:
  `DrifterClothes2`, `RaggedShirt2`, `LeatherJacket2`, `ReinforcedLeather2`,
  `PortableTeleport2`, `TeleportBackpack2`, `TeleportBeaconBasic2`. Ключи взяты
  дословно из `craft_again_callback` соответствующих `StartCraft*2Action` классов
  (`genericCraft_<Key>_1` -> `<Key>`), не выдуманы.
- `tests/unit/Config/CraftRecipesTest.php` — `testLegacyShortfallBuyRecipesResolve()`
  (краснеет, если любой из семи ключей перестанет резолвиться `get()`/потеряет
  `craft_again_callback`) + по одному сверочному тесту на каждый рецепт
  (`test<Key>MatchesLegacyStart()` / `...MatchesLegacyRecipeService()` для телепорта).
- `tests/unit/Services/Craft/CraftShortageServiceTest.php` — вне `## Files` story, но
  правка неизбежна: `testShortfallBuyBlockLocksWhenRecipeKeyUnknownToHandler` (story
  fix-08) использовала `DrifterClothes2` как пример ключа, которого в
  `Config\CraftRecipes` НЕТ — после регистрации в этой story фикстура стала ложной,
  тест падал (кнопка «Докупить и собрать» теперь появляется, чего тест не ожидал).
  Заменил фикстуру на синтетический ключ `NoSuchLegacyRecipeXyz`, который не
  зарегистрирован и не будет — сам факт проверки «неизвестный ключ -> замок» остался
  нетронутым, поведение `CraftShortageService` не менял.

### Поштучная сверка (сырьё / компоненты / золото / гейты / уровень / статы) — источник: `handle()` каждого легаси-класса

1. **DrifterClothes2** (`StartCraftArmorDrifterClothes2Action`): сырых ресурсов нет;
   `crafted_items` = Ткань x8 (`Fabric`) + Складной нож x1 (`FoldingKnife`); золото 500;
   гейт — база (`requires_base`) + Верстак 1 в инвентаре (`WorkbenchOne` в
   `crafted_items_log`, перенесено как `required_crafted_items => ['WorkbenchOne'=>1]`,
   а не как постройка — легаси проверяет ИМЕННО инвентарный предмет). Строгих
   требований силы/уровня нет — в конфиге их нет тоже.
2. **RaggedShirt2** (`StartCraftArmorRaggedShirt2Action`): сырых ресурсов нет;
   `crafted_items` = Ткань x6; золото 300; тот же гейт WorkbenchOne+база. Строгих
   требований силы/уровня нет.
3. **LeatherJacket2** (`StartCraftLeatherJacket2Action`): ресурсы Кожа животных x4 +
   Древесина x2; `crafted_items` = Складной нож x1 + Ткань x8; золото 700; тот же гейт
   WorkbenchOne+база. Строгих требований силы/уровня нет.
4. **ReinforcedLeather2** (`StartCraftReinforcedLeather2Action`): ресурсы Кожа
   животных x6 + Древесина x4 + Редкие металлы x3; `crafted_items` = Складной нож x1 +
   Ткань x12 + Металл фрагменты x1 (`metalFragments`); золото 1200; гейт WorkbenchOne+
   база; **required_strength=5, required_level=3** — единственный из семи с явным
   требованием силы/уровня (строки 79-92 легаси).
5. **PortableTeleport2** (`StartCraftPortableTeleport2Action` -> единственный источник
   чисел `PortableTeleportRecipe`): ресурсы Кристаллы x12 + Солнечные камни x18 +
   Редкие металлы x10 + Минералы x20; `crafted_items` = Проводка x30 (`wiring`) +
   Электронные компоненты x12 (`electronicComponents`) + Ткань x10; золото — дефолт
   `PortableTeleportRecipe::DEFAULT_GOLD`=30000 (в проде живёт в GameSettings
   `craft.portable_teleport.gold_cost` — у конфига рецептов моста для золота нет,
   см. `## Findings`); гейт — база + постройки `TeleportationCenter` + `Workshop`
   (легаси проверяет РЕАЛЬНЫЕ постройки, не инвентарные предметы — перенесено как
   `required_buildings`, не `required_crafted_items`); required_level=15 с мостом
   `required_level_setting_key='craft.portable_teleport.min_level'` (тот же паттерн,
   что уже применён к `AutonomousDrone` в этом же файле) — уровень остаётся живым
   через GameSettings, не замораживается в конфиге.
6. **TeleportBackpack2** (`StartCraftTeleportBackpack2Action`): ресурсы Янтарь x23 +
   Минералы x15 + Солнечные камни x12 + Кристаллы x7; `crafted_items` = Проводка x4 +
   Электронные компоненты x8 + Ткань x36; золото 21000; **гейта на базу/постройки в
   легаси `handle()` НЕТ** (модели `claimedCellModel`/`buildingModel` создаются в
   конструкторе, но ни разу не вызываются в `handle()`) — `requires_base`/
   `required_buildings` в конфиге сознательно НЕ проставлены, перенос «как есть».
7. **TeleportBeaconBasic2** (`StartCraftTeleportBeaconBasic2Action`): ресурсы
   Угольная порода x10 + Железная руда x8 + Редкие металлы x12; `crafted_items` =
   Проводка x26 + Электронные компоненты x4 + Ткань x8; золото 12500; тот же случай —
   легаси `handle()` не проверяет базу/постройки, конфиг не добавляет гейт.

Соответствие русских названий английским `name_eng` компонентов (взято из уже
существующих записей `Config\CraftRecipes`, не придумано): Ткань->`Fabric`,
Складной нож->`FoldingKnife`, Металл фрагменты->`metalFragments`, Проводка->`wiring`,
Электронные компоненты->`electronicComponents`.

## Findings
- **task_name без гарантии строки в `tasks`**: `GenericCraftActionStart::handle()`
  делает `taskModel->where('name', $recipe['task_name'])->first()` и отказывает
  `"Задача '...' не найдена в БД"`, если строки нет — в отличие от легаси-классов,
  которые сами вставляют строку `tasks` при первом обращении. Для персонажа, который
  НИ РАЗУ не запускал этот легаси-крафт, покупка-и-сборка (`craftBuyGo_*`) упадёт на
  этом гейте до первого ручного старта того же рецепта хоть одним игроком на сервере.
  Не чинил — `GenericCraftActionStart`/легаси-классы вне `## Files` этой story и вне
  `## Non-goals` (запрещено трогать общие сервисы). Наблюдение для будущей story.
- **Длительность крафта после докупки отличается от легаси-таймера**: покупка-и-сборка
  идёт через `GenericCraftActionStart`, который считает время через
  `CraftDurationService` (формула от `tasks.min_duration`/`max_duration` + статы
  персонажа + бафы построек), а не через фиксированный `timeForOne x qty` легаси-
  класса. Числа `tasks.min_duration/max_duration`, которые вставляют легаси-классы
  при первом запуске (5-15 мин у DrifterClothes и т.п.), не совпадают с их же
  `timeForOne` (8 мин/шт у DrifterClothes) — это расхождение уже жило в легаси-коде
  ДО этой story (два параллельных источника времени), story его не создаёт и не
  лечит: `## Non-goals` запрещает трогать `GenericCraftActionStart`.
- **`gold_required` PortableTeleport2 — статичный дефолт, не мост к GameSettings**:
  в отличие от `required_level` (для него в `CraftRecipes` уже есть механизм моста
  `required_level_setting_key`, использован), для `gold_required` такого моста в
  схеме конфига нет ни у одного из ~105 существующих рецептов. Если админ поменяет
  `craft.portable_teleport.gold_cost` в GameSettings, экран докупки будет требовать
  старую сумму 30000, пока кто-то не тронет `gold_required` в конфиге руками — тот
  же класс дефекта «два источника правды», но лечить его — отдельная story (создание
  моста для gold было бы изменением схемы `CraftRecipes`/`GenericCraftActionStart`,
  вне `## Files` и `## Non-goals` этой).
- Локальная БД (dev dump) не содержит строк `crafted_items` для шести из семи
  предметов (кроме `PortableTeleport`, id=78) — это ожидаемо: пять из семи
  выдаются через таблицу `outfits` (не `crafted_items`), а `TeleportBackpack`/
  `TeleportBeaconBasic` создают свою строку `crafted_items` сами при первом
  завершении крафта (`CraftCompletionTeleportBackpackHandler`/`...BeaconBasicHandler`,
  `insert` при отсутствии). `item_name_eng` в конфиге не влияет на выдачу предмета
  (завершение идёт по `tasks.name` -> выделенный TaskHandler, не по
  `Config\CraftRecipes`), только на текст экрана докупки и (при включённом
  `profit_gate`, по умолчанию выключен) на попытку найти цену продажи.
