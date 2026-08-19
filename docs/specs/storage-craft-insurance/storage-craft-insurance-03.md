---
story: storage-craft-insurance-03
spec: storage-craft-insurance
status: done
tier: 3
worker: worker-code
tracer: false
wave: 2
blocked_by: [storage-craft-insurance-01]
---

# Со склада можно забрать один вид, а не только всё сразу

## Goal
Экран «Склад базы» получает выдачу по одному виду ресурса — зеркало уже существующей сдачи
(`baseStorageDeposit_res_<id>` кладёт весь вид целиком). Сегодня выдача умеет только «Забрать всё»,
и чтобы достать одну воду, игрок вынужден вывалить в рюкзак весь склад и разложить его обратно.

## Requirements
> 2. Кнопка «забрать со склада» должна работать так же, как и кнопка «положить на склад»: «выбор ресурса» или «забрать все», иначе для запуска крафта приходится забирать в рюкзак весь склад и потом выкладывать его обратно по частям ⬇️ [Image #3]

## Files
- app/Controllers/Telegram/Commands/Actions/Storage/BaseStorageListAction.php
- tests/unit/Storage/BaseStorageRetrieveTest.php

## Non-goals
- НЕ вводить ввод произвольного количества (forceReply, доли ¼/½). Сдача устроена как «вид целиком»,
  и просьба была именно о симметрии; новый элемент ввода — это другая задача с другим риском.
- НЕ править `app/Config/CallbackRoutes.php`: роутер режет `callback_data` по первому `_`-сегменту,
  а `baseStorageList` на этот класс уже зарегистрирован. Проверить это фактом, а не поверить на слово.
- НЕ трогать `BaseStorageDepositAction` — образец остаётся как есть.
- НЕ переписывать сортировку. Но режим сортировки обязан пережить переход на экран выбора ресурса:
  он stateless и живёт только в `callback_data`, значит его надо протащить, а не потерять.

## Map slice
`memory/map/bases.md` (склад), `memory/map/telegram.md` (`ButtonPacker`, callback-протокол).

## Acceptance criteria
- [ ] На складе, стоя на базе, есть выбор ресурса для забора; тап переносит весь этот вид в рюкзак.
- [ ] Кнопки идут через `ButtonPacker::pack()` — ни одной одинокой кнопки в ряду.
- [ ] Количество видов на экране ограничено тем же пределом, что у сдачи (18), остальное честно
      названо строкой «…и ещё N видов», а не молча обрезано.
- [ ] Забор списывает со склада ровно то, что зачислил в рюкзак — в одной транзакции; на сбое
      ресурс не задваивается и не исчезает.
- [ ] Не на базе — выдача недоступна, и отказ объясняет, что нужно быть на базе (не «ошибка»).
- [ ] Экран самодостаточен текстом (MEDIA-OFF).

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Storage/BaseStorageRetrieveTest.php`

## Implementation notes

- Файлы: `app/Controllers/Telegram/Commands/Actions/Storage/BaseStorageListAction.php` (изменён),
  `tests/unit/Storage/BaseStorageRetrieveTest.php` (создан).
- Роутинг подтверждён чтением `CallbackqueryCommand::execute()` (`explode('_', $callbackData)[0]`)
  и `Config\CallbackRoutes` — `baseStorageList` уже зарегистрирован единственным exact-ключом,
  правка конфига не понадобилась.
- Новые callback'ы:
  - `baseStorageList_res_<resource_id>_<mode>` — забрать один вид ресурса целиком (весь остаток
    со склада → рюкзак, атомарно). `<mode>` — текущий режим сортировки (`recent`/`name`/`qty`),
    протащен, чтобы после забора экран вернулся в том же порядке, а не сбросился на recent.
  - Существующие `baseStorageList`, `baseStorageList_all`, `baseStorageList_sort_<mode>` не тронуты.
- Кнопки выбора ресурса — на главном экране склада (отдельного экрана-выбора, в отличие от
  депозита, нет: тут и обзор, и выбор — один экран), лимит 18 (как у сдачи), честная строка
  «…и ещё N видов» при превышении.
- Списание сделано БЕЗ раздельного чтения `quantityFor()` перед `withdraw()`: `withdraw($id, $res,
  PHP_INT_MAX)` в одной транзакции сам берёт «сколько реально есть» и возвращает списанное — так
  устранена гонка между чтением остатка и списанием (сильнее, чем зеркалируемый `depositOne`,
  который такой гонки не имеет, т.к. читает `character_resources` без параллельных писателей склада).
- Тест — source-scan (как `BaseStorageDepositTest`), без БД: резолв callback'ов через
  `CallbackRoutes`, наличие `ButtonPacker::pack()`, honesty-строка лимита, текст отказа «не на базе»
  дважды (retrieveAll + retrieveOne).
- `vendor/bin/phpstan analyse` на весь `app/` падает с фатальной ошибкой в НЕ тронутом файле
  `GenericCraftActionStart.php` (protected/private mismatch, chunk parallel worker crash) —
  предсуществующий баг вне scope этой story; точечный прогон на изменённый файл — чисто.

## Findings
