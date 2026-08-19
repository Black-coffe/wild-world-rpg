---
story: storage-craft-insurance-11
spec: storage-craft-insurance
status: todo
tier: 2
worker: worker-code
tracer: false
wave: 4
blocked_by: [storage-craft-insurance-03]
---

# Экран склада: доказать поведение, а не наличие строк в файле

## Goal
Выдача со склада «покрыта» четырьмя тестами, которые читают исходник и ищут в нём подстроки —
сломай `retrieveOne()` целиком, они останутся зелёными. Плюс на самом экране три реальных
дефекта: исход транзакции не проверяется (игрок читает «Забрано × N», ресурс остался на
складе), имя ресурса не экранируется перед подстановкой в Markdown (пустое имя даёт `**` →
400 → сообщение молча не уходит уже ПОСЛЕ перемещения), и строка «…и ещё N видов» считает по
одному списку, а кнопки строятся из другого, после отсева.

## Requirements
> 2. Кнопка «забрать со склада» должна работать так же, как и кнопка «положить на склад»: «выбор ресурса» или «забрать все», иначе для запуска крафта приходится забирать в рюкзак весь склад и потом выкладывать его обратно по частям ⬇️ [Image #3]

## Files
- app/Controllers/Telegram/Commands/Actions/Storage/BaseStorageListAction.php
- tests/unit/Storage/BaseStorageRetrieveTest.php

## Non-goals
- НЕ вводить ввод произвольного количества.
- НЕ трогать `BaseStorageDepositAction` и `CallbackRoutes`.
- НЕ переписывать сортировку.

## Map slice
`memory/map/bases.md`, `memory/map/telegram.md`.

## Acceptance criteria
- [ ] Исход транзакции забора проверяется; при откате игрок НЕ читает, что ресурс забран.
- [ ] Имя ресурса экранируется перед подстановкой в разметку — тем же способом, что на экране
      страховки.
- [ ] Число в «…и ещё N видов» сходится с числом реально показанных кнопок.
- [ ] Тесты проверяют ПОВЕДЕНИЕ забора, а не наличие подстрок в исходнике: сломанный
      `retrieveOne()` обязан валить тест. Тесты-сканеры исходника заменить, а не дополнить.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Storage/BaseStorageRetrieveTest.php`

## Implementation notes

- `BaseStorageListAction.php`: `retrieveOne()` теперь делегирует всю логику забора (транзакция +
  текст) в приватный `performRetrieveOne()`, который: 1) ловит исключения из моделей и проверяет
  `$db->transStatus()` после `transComplete()`, возвращая `null` при откате, вместо использования
  посчитанного до commit'а `$withdrawn`; 2) строит текст через `formatRetrieveMessage()`, где имя
  ресурса идёт через `MarkdownSafe::name($name, 'ресурс')` (тот же приём, что у страховки). То же
  экранирование добавлено в `renderList()`'s построчный вывод.
  `retrieveAll()` получил тот же класс фикса (try/catch + `transStatus()`) — twin-баг в соседнем
  методе того же файла.
- Дефект 3 (расхождение «…и ещё N видов» с кнопками) вынесен в `buildResourceButtonRows()`:
  `hiddenCount = count($entries) - count($buttons)` (было `count($entries) - MAX_BUTTONS`, что
  игнорировало мусорные строки, отфильтрованные ВНУТРИ среза `MAX_BUTTONS`).
- Отказ «не на базе» вынесен в `offBaseDenialText()` — общий для `retrieveAll()`/`retrieveOne()`,
  убирает дублирование текста и даёт тестируемую точку без сети.
- `tests/unit/Storage/BaseStorageRetrieveTest.php` переписан полностью: все 4 source-scan теста
  (`file_get_contents()` + `assertStringContainsString`) заменены на поведенческие. Роутинг-тест
  (`testRetrieveCallbacksResolveToTheAction`) оставлен как есть — он и раньше проверял поведение
  (реальный `CallbackRoutes::resolve()`), не грепал исходник.
  `retrieveOne()`/`retrieveAll()`/`renderList()`/`handle()` не вызываются напрямую — они
  оканчиваются `Request::sendMessage()` (реальный сетевой вызов к Telegram), и ни один
  Action-тест в проекте так не делает (сверено с `MediaSenderTest`, `PoolAdoptionRepairUpgradeTest`).
  Вместо этого тесты дёргают через рефлексию `performRetrieveOne()` / `formatRetrieveMessage()` /
  `buildResourceButtonRows()` / `offBaseDenialText()` — именно в них перенесена вся логика,
  подверженная трём дефектам; тонкая Telegram-обвязка вокруг (isOnBase-гейт, финальный
  `sendMessage`) намеренно не покрыта юнит-тестом (нужен Tier-3 smoke).
- Реальные SQLite... на деле MySQL-таблицы `resources`/`character_resources`/`base_storage`
  создаются в `setUp()` по паттерну `GreenhouseProductionWaterTest` (соединение `tests`, локально
  это MySQL `wildworld_tests`, не SQLite — конфиг `Config\Database::$tests` вводит в заблуждение
  названием драйвера по умолчанию, но `.env` локально переопределяет на MySQLi).
- Как убедился, что тесты умеют краснеть: по очереди вручную откатывал каждый из трёх фиксов
  прямо в `BaseStorageListAction.php`, гонял `BaseStorageRetrieveTest.php`, фиксировал красный
  результат, возвращал фикс обратно. Дефект 1 (откат `try/catch`+`transStatus()`) →
  `testPerformRetrieveOneRollsBackAndReturnsNullWhenCreditingBackpackFails` падает с
  необработанным `RuntimeException` (реальный откат никто не ловит без фикса). Дефект 2 (откат
  `MarkdownSafe::name()` на прямую подстановку `$name`) → оба
  `testFormatRetrieveMessage*` теста падают (`**` в выводе; счётчик `*` расходится с чистым
  контролем). Дефект 3 (откат `hiddenCount` на `count($entries) - MAX_BUTTONS`) →
  `testBuildResourceButtonRowsHiddenCountMatchesActuallyShownButtons` падает (2 вместо 5). Каждый
  из трёх откатов проверен ОТДЕЛЬНО, не пакетом.

## Findings
