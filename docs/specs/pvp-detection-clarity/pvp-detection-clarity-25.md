---
story: pvp-detection-clarity-25
spec: pvp-detection-clarity
status: done
tier: 1
worker: worker-code
tracer: false
wave: 8
blocked_by: []
---

# Сосед не глохнет из-за сообщения, которое не дошло

## Goal

После story отметка «сосед показан» ставится по факту доставки, а не по факту намерения отправить:
если сообщение обнаружения не ушло, сосед не закрывается на кулдаун пары и появится на следующем
шаге.

## Requirements

> BLOCK-3, minor 5: строка в `player_detection_history` не должна глушить соседа, которого игрок так и не увидел: запись идёт ДО `Request::sendMessage()`, и неудачная отправка (исключение или `ok=false`) всё равно закрывает пару на кулдаун. Это остаток того же #8, сдвинутый на шаг: было «до обрезки», стало «до доставки».

> Исходная находка #8, которую это доводит: 111 из 123 соседей помечались показанными, не будучи показанными, и глохли по кулдауну пары — игрок их не видел вовсе.

## Files
- app/Services/Player/PlayerDetectionService.php
- tests/database/PlayerDetectionRenderTest.php

## Non-goals
- Не менять потолок списка, радиус обнаружения и длительность кулдауна пары — правятся не величины, а момент записи.
- Не глотать ошибку отправки молча: если сообщение не ушло, это должно быть видно в логе так же, как видно сейчас.
- Не трогать `AttackPlayerAction` и `PvPRestrictionService`.
- Не писать тест, который сканирует исходник на порядок строк: проверять поведение — при неудачной отправке строки истории нет.
- Не запускать полный набор; не делать `DROP` / `migrate` на общей `wildworld_tests`; не делать `git stash` / `git checkout`.

## Map slice

`PlayerDetectionService.php:205-211` — запись в `player_detection_history` по `shown_ids`,
сейчас до `Request::sendMessage()`; `canSendNotification()` — кулдаун пары, который этой записью
и армируется; `detectNearbyPlayers()` — публичный путь, по которому ходит игра (именно на нём
story `-22` вскрыла мёртвый экран, поэтому тест обязан идти через него же).

- [x] При неудачной отправке (исключение транспорта или ответ `ok=false`) строки в `player_detection_history` не появляется: сосед не закрыт на кулдаун и придёт на следующем шаге. Тест доказывает это через настоящий `detectNearbyPlayers()`, а не через рендер.
- [x] При удачной отправке поведение прежнее: история пишется ровно по показанным соседям, тест `-22` (`testDetectNearbyPlayersWritesHistoryOnlyForShownNeighbors`) остаётся зелёным.
- [x] Неудачная отправка по-прежнему попадает в лог — диагностику не теряем.
- [x] Проверь свой тест откатом: верни запись истории обратно перед отправкой и убедись, что тест краснеет. Скажи в отчёте, что это выполнено — не «должно падать», а «падает, вот как».

## Verification

`vendor/bin/phpunit --no-coverage --no-progress tests/database/PlayerDetectionRenderTest.php`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes

- `PlayerDetectionService::detectNearbyPlayers()`: поход в Telegram вынесен в новый
  `protected sendDetectionNotification(int|string $chatId, array $rendered): bool` —
  ловит `TelegramException` и проверяет `$response->isOk()`, в обоих случаях логирует
  через `log_message('error', ...)` и возвращает `false`. `detectNearbyPlayers()` пишет
  в `player_detection_history` только если `sendDetectionNotification()` вернул `true`
  (было: `insert()` цикл ДО `Request::sendMessage()`).
- Извлечение в protected-метод понадобилось потому, что фейковый `ServerResponse` под
  `PHPUNIT_TESTSUITE` (см. `vendor/longman/telegram-bot/src/Request.php:433`) всегда
  `ok=true` — через реальный `Request::sendMessage()` смоделировать `ok=false` или
  исключение в тесте было нечем. Тест подменяет метод в анонимном наследнике
  `PlayerDetectionService`.
- `tests/database/PlayerDetectionRenderTest.php`: новый тест
  `testFailedDeliveryDoesNotArmCooldownAndNeighborStaysDetectable` идёт через настоящий
  `detectNearbyPlayers()` (не `renderDetectionMessage()`), форсит `sendDetectionNotification()`
  → `false`, проверяет отсутствие строки в `player_detection_history` и что повторный
  вызов `detectNearbyPlayers()` снова находит того же соседа (кулдаун не взведён).
- Откат проверен вручную: временно вернул `insert()` в цикл ДО
  `sendDetectionNotification()` (без изменения самого protected-метода) и прогнал
  только новый тест — он красным: `Failed asserting that actual size 1 matches
  expected size 0` на строке assert-а «неудачная отправка не должна армировать
  кулдаун пары» (`tests/database/PlayerDetectionRenderTest.php:569`). Затем вернул
  фикс обратно и весь файл снова зелёный.

## Findings

Постороннего блокера не встретил — единственная сложность (фейковый `ServerResponse`
всегда `ok=true` под `PHPUNIT_TESTSUITE`) решена извлечением protected-метода, без
изменения не названных story файлов.
