---
story: s2-conditional-writes-10
spec: s2-conditional-writes
status: todo
returned:
tier: 3
worker: worker-code
wave: 3
blocked_by: []
red: required
red_reason: "bulk sale committed although increaseGold refused, or withdraw-all issued the storage twice"
---

# Опт: `bulkSellResources` читает исход `increaseGold`; регресс-тест двойного «Забрать всё»

## Goal

`ResourceTradeService::bulkSellResources()` (`:600-666`) после хотфикса 08.10 уже идёт в транзакции с `FOR UPDATE`,
токеном превью и `decrementIfAtLeast`, но результат `increaseGold` (`:657`) не читается: `false` оставляет ресурсы
списанными, а золото — не начисленным. После story `false` → откат всей продажи и существующий текст «Не удалось
выполнить оптовую продажу…» по образцу `sellResource` (`:187`, `$fail()`). Вторая половина — только тест:
регресс одновременного «🎒 Забрать всё» со склада базы (`BaseStorageService::withdrawAll()`, условная запись
`BaseStorageModel::withdraw` уже есть по `memory/map/bases.md`): два забора от одного снимка выдают склад один раз.
Продовый код склада не меняется, если тест зелёный; красный тест = находка в отчёт и `WALL`, не правка вне `## Files`.

## Requirements

> 11. Опт — `bulkSellResources` проверяет результат `increaseGold`; при `false` — откат всей продажи и текст «Не удалось
> `bulkSellResources` не проверяет `increaseGold` + регресс-тест одновременного «Забрать всё»

## Files

- app/Services/Player/Trade/ResourceTradeService.php
- tests/database/BulkSellIncreaseGoldRefusalTest.php
- tests/database/BaseStorageWithdrawAllOnceTest.php

## Non-goals

- `sellResource()` не трогать — закрыт хотфиксом 08.10 (ask 13). `buyResource()`, цены банка, `bulkSellPreview()`
  и токен — не трогать.
- Не править существующий `tests/database/BulkSellResourcesTest.php` (8 методов): новый файл.
- Не менять `BaseStorageService`/`BaseStorageModel`: их нет в `## Files`.
- Не добавлять текст: отказ — существующая строка опта.

## Map slice

`memory/map/player.md` → Gotchas («Опт-продажа исполняется один раз»: `bulkSellResources($char, $pct, ?$rarity,
string $confirmToken)`, `FOR UPDATE`, токен); `memory/map/bases.md` → Entry points («Ядро склада базы —
`BaseStorageService`», коды `ok|…|missing|short|empty|failed`), Gotchas («Склад: … выдача/сдача — условные записи»).
Правило транзакций и форма теста — `plan.md` → `## Contracts`.

## Acceptance criteria

- [ ] `increaseGold` `false` → `transRollback`, возврат отказа с текстом «Не удалось выполнить оптовую продажу…»;
      ни одна строка ресурсов не списана, золото не изменено, запись в банк не сделана.
- [ ] Транзакция опта завершается ровно один раз на каждом пути, включая этот; исход — по возврату.
- [ ] Тест `BulkSellIncreaseGoldRefusalTest`: `increaseGold` принуждён вернуть `false` через существующий шов
      инъекции сервиса (двойник модели/сервиса золота; без шва — опциональная зависимость конструктора с дефолтом,
      сигнатура существующих вызывающих не меняется, `feedback_signature_change_breaks_test_subclasses`); результат —
      ресурсы на месте, золото не изменено. Сообщение ассерта при провале:
      `bulk sale committed although increaseGold refused, or withdraw-all issued the storage twice`.
- [ ] Тест `BaseStorageWithdrawAllOnceTest`: склад с N единиц ресурса, персонаж на базе; две `withdrawAll()` от
      одного снимка модели склада → в рюкзаке ровно N, склад пуст, второй вызов отдаёт `empty`/`missing`, не `ok`.
      Тот же текст ассерта.

## Verification

`vendor/bin/phpunit --no-coverage --no-progress tests/database/BulkSellIncreaseGoldRefusalTest.php && vendor/bin/phpunit --no-coverage --no-progress tests/database/BaseStorageWithdrawAllOnceTest.php && vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes

## Findings
