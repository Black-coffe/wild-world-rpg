---
story: community-chat-bot-46
spec: community-chat-bot
status: done
tier: 2
worker: worker-code
tracer: false
wave: 11
blocked_by: []
---

# Метрика отказов гварда считает каждую строку склейки и не висит на best-effort логе

## Goal
Дубликаты одного вопроса перестают выпадать из доли отказов гварда, а сбой необязательной
аудит-записи перестаёт вычитать строку из метрики.

## Requirements
> Тут нужно спланировать, как он будет отвечать: автоматически, в ручном режиме, полуавтоматически

## Files
- app/TaskHandlers/Community/CommunityAutoReplyHandler.php
- app/Controllers/Admin/CommunityController.php
- tests/unit/TaskHandlers/CommunityAutoReplyHandlerTest.php
- tests/unit/Controllers/Admin/CommunityControllerTest.php

## Non-goals
- Не трогать гвард, отправителя, матчер, чистку.
- Не менять остальные метрики экрана.

## В чём дефект
1. `logRoute()` вызывается один раз, на строку-представителя (`CommunityAutoReplyHandler.php:245`),
   а статус `escalated` получает ВСЯ склейка (`markGroup($decision->coveredMessageIds, …)`, `:244`).
   После story 39 различитель — `EXISTS(COMMUNITY_ROUTE_LOGGED)`, поэтому остальные строки склейки
   выпадают и из числителя, и из знаменателя. Это дословно тот дефект, который story 39 закрывала,
   переехавший с «упёрлись в лимит» на «дубликаты одного вопроса». При старом `NOT EXISTS` они
   считались все. Ни один из трёх тестов метрики склейку не строит.
2. `logRoute()` (`:455-482`) глушит `Throwable` на вставке и молча выходит при пустом маршруте.
   После story 39 проглоченная вставка не просто теряет подсказку, а вычитает строку из метрики.

## Contract
- Числитель и знаменатель доли отказов гварда включают КАЖДУЮ строку, которую отказ гварда
  перевёл в `escalated`, включая дубликаты склейки.
- Признак, по которому опознаётся отказ гварда, не исчезает при сбое необязательной аудит-записи.
- Тесты строят склейку из нескольких строк, а не одиночный вопрос.

## Acceptance criteria
- [x] Склейка из N строк, отказанная гвардом, даёт N в числителе и N в знаменателе.
- [x] Сбой аудит-вставки не меняет метрику.
- [x] Терминальный отказ отправителя по-прежнему в числитель не попадает (регрессия story 39/32).

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/TaskHandlers/CommunityAutoReplyHandlerTest.php tests/unit/Controllers/Admin/CommunityControllerTest.php`

## Implementation notes

- Починка на стороне **записи** (`CommunityAutoReplyHandler`), не чтения: `guardDeniedCount()`
  в `CommunityController` не тронут структурно (только докблок) — читающая сторона не может
  восстановить `Decision::coveredMessageIds` постфактум, эта информация нигде не персистится,
  кроме момента самого решения.
- Новый приватный метод `escalateGuardDenial(array $coveredIds, Verdict $verdict)` заменил пару
  `markGroup(..., ['status' => 'escalated']); logRoute($selfId, $verdict);` на строке 244: теперь
  статус склейки и аудит-запись маршрута для **каждой** строки `$coveredIds` (не только
  `$selfId`) коммитятся одной транзакцией (`transBegin/transCommit/transRollback`, паттерн
  `claimGroup()`).
- `logRoute()` больше не глушит `Throwable` сама — вставку теперь оборачивает
  `escalateGuardDenial()`, и сбой (DBDebug=true бросает `DatabaseException`) откатывает и статус:
  строка остаётся `'new'`, получает второй шанс на следующем тике, вместо того чтобы навсегда
  осесть `escalated` без аудит-строки, по которой её опознаёт метрика.
- Реакция 🤔 (`reactOnce`) осталась вне транзакции — она не влияет на метрику и не должна
  блокироваться откатом БД.
- Тесты: `testGuardDenialLogsRouteForEveryDuplicateInGroupNotJustRepresentative` строит склейку
  из 3 строк и проверяет по одной аудит-записи на каждую; `testGuardDenialInsertFailureRollsBackStatusInsteadOfLosingMetricSignal`
  подсовывает `AdminAuditLogModel`-анонимный класс, у которого `insert()` бросает, и проверяет
  откат статуса на `'new'` и ноль аудит-строк.
- Замечено, но не тронуто (вне `## Files` и Non-goals): `tests/unit/Services/CommunityGuardTest.php`
  был уже модифицирован в рабочем дереве до начала этой story (параллельная сессия/воркер) —
  не мой diff, не входит в отчёт.
