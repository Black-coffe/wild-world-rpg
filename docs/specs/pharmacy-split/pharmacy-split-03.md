---
story: pharmacy-split-03
spec: pharmacy-split
status: done
tier: 2
worker: worker-test
tracer: false
wave: 3
blocked_by: [pharmacy-split-01, pharmacy-split-02]
---

# Тесты полок: разделение, строка «Снимает», длина текста

## Goal
Поведение полок закрыто тестами, которые упадут при регрессии: перепутанная классификация,
пропавшая строка снимаемых ран, экран длиннее лимита Telegram, кнопка-сосед, исчезающая на
пустой полке.

## Requirements
> В описание лекарств надо добавить дебаффы, которые они снимают.
> Возможно продукты и консервы надо убрать из аптечки в отдельную кнопку

## Files
- tests/unit/Player/ConsumableShelfTest.php
- tests/unit/Config/ConsumablesCatalogTest.php

## Что проверяем (поведением, не сканом исходника)

1. `split()` на наборе из тушёнки, бинта, ухи и аптечки кладёт каждый предмет на свою полку.
2. Неизвестное имя уходит в провизию.
3. Полка лекарств печатает «Снимает» для `Bandage` / `Antiseptic` / `Regenerator` /
   `FirstAidKit`, и не печатает для `StewPreserve`.
4. `MEDICINE ∩ PROVISION = ∅`; каждый `cured_by` из `Config\Debuffs` лежит в `MEDICINE`.
5. 🔴 **Длина текста.** Прогнать `screen()` по ПОЛНОМУ каталогу каждой полки (28 провизий и
   15 лекарств, по одной строке на предмет) и проверить, что текст укладывается в 4096
   символов по метрике `MediaSender::visibleTgLength`-эквивалента; если метод приватный —
   считать `mb_strlen`. Повод: экран с 43 предметами уже сегодня перерастает caption-лимит
   1024 и уходит текстом; тихая деградация — не «нет бага».
6. Разметка: количество символов `*` в тексте каждой полки чётное.

## Non-goals
- Не поднимать БД и не ходить в `crafted_items` — сервис чистый, кормить его массивами.
- Не писать тестов, которые ищут строки в файле через `file_get_contents` — такой тест
  останется зелёным при сломанном методе.
- Не менять код из story 01/02 ради удобства теста; если API мешает — записать в `## Findings`.

## Map slice
`tests/unit/Craft/CraftHubKeyboardTest.php` — образец стиля (проверяем выход, а не замысел).

## Acceptance criteria
- [x] Оба файла тестов зелёные и падают, если поменять полку одного предмета в `Consumables`.
- [x] Тест длины падает, если в строку предмета добавить ещё один абзац текста.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Player/ConsumableShelfTest.php tests/unit/Config/ConsumablesCatalogTest.php`

## Implementation notes

Написаны `tests/unit/Config/ConsumablesCatalogTest.php` (5 тестов, каталог) и
`tests/unit/Player/ConsumableShelfTest.php` (10 тестов, `split()`/`screen()` через публичный
вход, без `file_get_contents`). Все проверки — на выходе сервиса (текст/кнопки), не на исходнике.

`ConsumableExpiryService` и `GameSettingsService` — оба `final`, подставной подкласс сделать
нельзя (см. `## Findings`). Обошёл без правки кода story 01/02: тестовые строки не задают
`durability_time`, поэтому ветка годности в `itemLine()` не участвует независимо от того, что
`enabled()` вернёт на этой машине (боевая деградация `GameSettingsService::get` к default при
недоступной БД уже документирована в его докблоке) — тест детерминирован что с БД, что без неё.
Прогнал так и с локальной MySQL, и убедился, что путь не зависит от её состояния.

Три сборки регрессии прогнаны вручную (правка/прогон/откат, diff подтверждён identical):
1. Перенос `FishSoup` из `PROVISION` в `MEDICINE` в `app/Config/Consumables.php` — упали
   `ConsumableShelfTest::testSplitPutsEachItemOnItsOwnShelf` и
   `ConsumablesCatalogTest::testShelfOfKnownItems` (кейс «уха → провизия»).
2. Добавление лишнего абзаца в `itemLine()` (`ConsumableShelfService.php`) на КАЖДЫЙ предмет —
   упал `testFullCatalogScreenFitsTelegramTextLimit` (5365 симв. на полке лекарств вместо
   ожидаемых < 4096, ассерт сработал ровно на этой ветке).
Оба файла возвращены в исходное состояние, `diff` против копии до правки — пусто.

## Findings

`ConsumableExpiryService` (final) и `GameSettingsService` (final) нельзя подсабклассить —
инструкция про «анонимный подкласс с переопределённым `enabled()`» из задания технически
невыполнима для этих классов. Обошёл без правки story 01/02: не заполнял `durability_time` в
тестовых строках — ветка `expiry->enabled()`/`isExpired()` в `itemLine()` тогда безвредна вне
зависимости от результата `enabled()`, а `GameSettingsService::get()` и так безопасно
деградирует к default при недоступной БД (см. его собственный докблок «Безопасная деградация»).
БД для этих тестов не требуется.
