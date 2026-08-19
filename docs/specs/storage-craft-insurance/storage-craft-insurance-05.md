---
story: storage-craft-insurance-05
spec: storage-craft-insurance
status: todo
tier: 3
worker: worker-code
tracer: false
wave: 2
blocked_by: [storage-craft-insurance-01]
---

# Крафт считает и тратит из пула

## Goal
`GenericCraftActionStart` проверяет достаточность сырья и списывает его через `ResourcePoolService`.
Игрок, стоящий на базе, где на складе тысячи ресурсов, запускает крафт, не перекладывая ничего руками.
Экран нехватки перестаёт советовать «добыть/купить» то, что уже лежит на складе.

## Requirements
> #bug [19.08.2026 05:13] Анжела: 1. Крафт ингредиенты доступны только из рюкзака, даже когда я стою на складе, где их тысячи😳.

## Files
- app/Controllers/Telegram/Commands/Actions/Craft/GenericCraftActionStart.php
- app/Services/Craft/CraftShortageService.php
- tests/unit/Craft/CraftPoolConsumptionTest.php

## Non-goals
- НЕ трогать `GenericCraftCompletionHandler` — сырьё списывается на старте, завершение его не читает.
- НЕ трогать 40+ превью-экранов `*Craft1Action.php`: они показывают выбор количества и остаток не
  проверяют. Соблазн «заодно обновить все» — это диff на сорок файлов ради нуля поведения.
- НЕ менять рецепты, цены, длительность и `RequiredResourcesParser`.
- НЕ ломать инвариант «крафт не печатает золото» — проверка стоимости не меняется, меняется источник сырья.

## Map slice
`memory/map/craft.md` (крафт, нехватка), `memory/map/bases.md` (склад).

## Acceptance criteria
- [ ] Проверка достаточности и списание идут через `ResourcePoolService`, а не напрямую по
      `CharacterResourceModel` — обе точки, не одна из двух.
- [ ] На базе крафт запускается, когда сырья хватает суммарно, даже если в рюкзаке пусто.
- [ ] Списание внутри существующей транзакции старта; при откате не уходит ни рюкзак, ни склад.
- [ ] Вне базы поведение прежнее — только рюкзак.
- [ ] Экран нехватки, когда игрок не на базе, а недостающее лежит на складе, говорит об этом прямо:
      «столько-то ждёт на складе базы» — вместо совета добывать заново.
- [ ] Тексты самодостаточны (MEDIA-OFF), Markdown с парными `*`.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Craft/CraftPoolConsumptionTest.php`

## Implementation notes

- `GenericCraftActionStart::checkResources()` теперь резолвит `resourceId` через
  `resolveResourceId()` (новый приватный метод, использует уже существующий
  `BaseAction::$resourceModel`) и читает `ResourcePoolService::breakdown()`. `have` =
  `backpack + (pooled ? storage : 0)` — то есть достаточность считается пулом, когда
  игрок на базе, и только рюкзаком иначе (прежнее поведение сохранено). В `missing[...]`
  добавлены поля `storage`/`pooled` — нужны экрану нехватки.
- `subtractResources()` переписан на `ResourcePoolService::consumeByName()` — тратит
  сначала рюкзак, остаток со склада; вызывается внутри уже открытой транзакции старта
  (`consume()` своей транзакции не открывает, ровно контракт story-01).
  `checkCraftedItems`/`subtractCraftedItems` (crafted_items_log, не ресурсы) не тронуты —
  ResourcePoolService покрывает только `character_resources`+`base_storage`.
- **Правка после ревью (team-lead):** первая версия ловила `RuntimeException` гонки прямо
  в `subtractResources()` и молча продолжала — это меняло режим отказа «недоплатил»
  (старый silent-clamp) на «бесплатно» (крафт стартует, ресурс не списан вовсе), что хуже
  исходного бага. Теперь `subtractResources()` НЕ ловит исключение — оно поднимается в
  `handle()`, где обёрнуто в `try/catch` вокруг блока списания (внутри `$db->transStart()`):
  при `RuntimeException` — `$db->transRollback()`, лог, и `sendError('Сырьё разошлось,
  пока ты выбирал — проверь запас и попробуй ещё раз.')`; задача не создаётся, реального
  списания в БД нет (транзакция целиком не закоммичена). Переиспользован тот же путь
  ответа игроку (`sendError()`), что и у соседних отказов этого экрана — не изобретал
  новый. Тест `testSubtractResourcesPropagatesRaceInsteadOfSwallowing` подтверждает
  проброс исключения (не проглатывание) на уровне метода; сама «ничего не осталось после
  отката» — гарантия `$db->transRollback()`, того же паттерна, что уже используется ниже
  по файлу для транзакции создания задачи.
- Убрано неиспользуемое `private CharacterResourceModel $characterResourceModel` — весь
  доступ теперь либо через `ResourcePoolService`, либо через унаследованный
  `BaseAction::$resourceModel` (уже существовал, свой заводить не пришлось).
- `CraftShortageService::resourceLines()` — новая строка «🏠 на складе базы ждёт ещё N —
  вернись на базу, чтобы использовать» рендерится, когда `!pooled && storage > 0`
  (игрок не на базе, но у него есть запас на складе). Остальной текст (где добыть, купить
  у торговца) не менялся — сообщение теперь просто честнее, а не переписано.
- Sanity: правка НЕ трогает `subtractCraftedItems`, `GenericCraftCompletionHandler`,
  превью-экраны `*Craft1Action.php`, рецепты/цены/длительность — как и требовали Non-goals.
- **Рекомендуемый рецепт для живого смока: `Bandage`** (бинт) — рецепт первого тира,
  требует один дешёвый ресурс (`Бинты`/тряпьё), не гейтится зданиями/квестами/фракцией,
  крафтится за минуты. Сценарий: положить сырьё для бинта на склад базы, очистить рюкзак
  от него, вызвать `genericCraft_Bandage_1` стоя на базе — должен запуститься без ошибки
  «недостаточно ресурсов». Затем повторить вне базы с тем же раскладом — экран нехватки
  должен показать «🏠 на складе базы ждёт ещё N».
- phpstan: убрал 2 записи из `phpstan-baseline.neon` для
  `GenericCraftActionStart.php` (offset `'id'` count 2→1, offset `'quantity'` count 1→0),
  потому что старый код, дававший эти ошибки, удалён вместе со старой реализацией
  `checkResources`/`subtractResources`. Баланс актуализирован под меньшее число ошибок,
  не под большее.

## Findings

- `phpstan analyse` (полный прогон, без путей-аргументов) на HEAD рабочего дерева
  показывает 2 ошибки в `app/TaskHandlers/GreenhouseProductionHandler.php` — этот файл
  чужой (не в `## Files` этой story), модифицирован параллельно другим воркером
  (`git status` подтверждает `M`, не мой diff). Не трогал, отчитываюсь на случай если
  ревью увидит «phpstan не совсем чист» — источник ошибок вне этой story.
