---
story: pvp-detection-clarity-13
spec: pvp-detection-clarity
status: done
tier: 2
worker: worker-code
tracer: false
wave: 6
blocked_by: []
---

# Окно открывается только для пары, для которой этот тап мог бы стать боем

## Goal

После story тревога базы поднимается только тогда, когда атака реально возможна: смежность и все
запреты PvP проверены раньше, чем пишется строка `pvp_standoffs` и уходит сообщение защитнику.
Атакующий, заблокированный ЧУЖИМ окном, видит экран, все кнопки которого для него работают.
Требование «взгляд на часы не стоит атакующему 30 секунд кулдауна» при этом сохраняется.

## Requirements

> BLOCK, критично #1: окно открывается до проверки смежности и до `checkPvPAllowed` — тревогу можно поднять с любого конца карты и любым уровнем. Следствие тяжелее самого спама: после истечения такого окна защитник уходит на `cooldown_sec`, и следующий — настоящий — атакующий бьёт мгновенно, без окна. Одноразовый альт 1 уровня с другого конца карты снимает защиту с любой базы по требованию.

> BLOCK, major #5: при дубле `open()` возвращает `null`, и `resolveStandoffOutcome()` подставляет чужую строку; кнопки `standoffCheck_<id>` / `standoffLeave_<id>` ведут туда, где владение проверяется по `attacker_id`, и возвращают «Это не твоё противостояние». При этом текст экрана обещает «🚶 Уйти — передумать и снять тревогу с цели».

## Files
- app/Controllers/Telegram/Commands/Actions/PVP/AttackPlayerAction.php
- app/Controllers/Telegram/Commands/Actions/PVP/StandoffCheckAction.php
- app/Controllers/Telegram/Commands/Actions/PVP/StandoffLeaveAction.php
- tests/database/StandoffAttackGateTest.php

## Non-goals
- Не трогать `PvpStandoffService` — кулдаун и его армирование чинит story `-14`, она идёт параллельно и держит тот файл. Пересечение по файлу запрещено.
- Не трогать `StandoffNotifier` — тексты чинит `-16`, тоже параллельно.
- Не менять контракт `PvpStandoffService`: порядок гейтов — это порядок вызовов в `handle()`, а не новая сигнатура.
- Не вводить второй анти-спам-кулдаун и не переносить существующий в `GameSettings` (ADR-186 §6 оставил 30 с в коде).
- Не запускать полный набор; не делать `DROP` / `migrate` на общей `wildworld_tests`; не делать `git stash` / `git checkout`.

## Map slice

`AttackPlayerAction.php:141-157` — нынешнее место гейта окна; `:180` `isCellsCloseEnough()`;
`:184` `checkPvPAllowed()`; `resolveStandoffOutcome()` около `:487-574` — ветки `isCounterAttack`,
`block` и обычный бой; `StandoffCheckAction.php:50` и `StandoffLeaveAction.php:44` — проверка
владения по `attacker_id`.

## Acceptance criteria
- [x] Строка `pvp_standoffs` не пишется и сообщение защитнику не уходит, если тап не прошёл смежность (`isCellsCloseEnough`) или `checkPvPAllowed`. Тест доказывает оба случая отдельно: далёкая клетка и запрещённая пара (уровень / южная зона / молодой аккаунт).
- [x] Анти-спам-кулдаун 30 с по-прежнему НЕ платится за повторный тап по замороженной цели и за «⏳ Проверить». Тест доказывает, что второй тап по той же цели возвращает экран ожидания, а не «подожди 30 секунд».
- [x] Контратака «⚔️ Ударить первым» по-прежнему доходит до реального боя, не отбиваясь ни заморозкой, ни анти-спам-кулдауном защитника. Существующий тест на этот путь остаётся зелёным.
- [x] Атакующий, попавший на ЧУЖОЕ открытое окно (защитник уже под тревогой от другого игрока), видит экран без кнопок, которые ему откажут: либо кнопки принадлежат ему, либо их нет, а текст честно объясняет, что цель уже под чужой тревогой и сколько осталось. «🚶 Уйти», возвращающее «Это не твоё противостояние», недопустимо.
- [x] 🟡 Хвост #18 того же ревью, раз файл всё равно открыт: `applyHoldBonus()` не синтезирует профиль обороны, когда `getDefenseProfile()` вернул `null` — защитник без единой живой постройки не получает снижение урона (ADR-030). Тест закрывает этот случай.
- [x] Все новые и изменённые сообщения — HTML с экранированными именами, самодостаточны в тексте (media-off), кнопки по 2–3 в ряд.

## Verification

`vendor/bin/phpunit --no-coverage --no-progress tests/database/StandoffAttackGateTest.php`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes

- `AttackPlayerAction::resolveStandoffOutcome()` разбит на два метода: `resolveStandoffPreGate()`
  (только чтение — контратака / собственная заморозка, ничего не пишет и не шлёт) и
  `resolveStandoffOpen()` (пишет `pvp_standoffs` + шлёт алерт защитнику). `handle()` теперь
  вызывает pre-gate → анти-спам-кулдаун (чтение) → `isCellsCloseEnough()` → `checkPvPAllowed()` →
  `resolveStandoffOpen()` → фиксация кулдауна (`$cache->save()`) только если тап реально дошёл
  до боя/штатного продолжения (`block === false`). Это закрывает BLOCK #1: строка окна и алерт
  защитнику физически не могут случиться раньше смежности и `checkPvPAllowed`.
- Анти-спам-кулдаун разбит на «чтение» (рано, до тяжёлых DB-запросов — экономия от спама, как и
  было) и «запись» (после гейта окна, только если исход — не `block`) — иначе тап, который лишь
  открывает окно (уже само по себе не бой), платил бы кулдаун, чего не было до этой стори и что
  ломало 2 существующих теста (`testRepeatedTapOnFrozenTargetReturnsSameWaitScreenWithoutPayingCooldown`,
  `testStandoffLeaveActionCancelsOnceAndRefusesSecondTap`).
- BLOCK #5: `resolveStandoffOpen()` при дубле `open()===null` читает `activeAgainst()` и сверяет
  `attacker_id` — если строка чужая, `foreignStandoff` вместо `waitStandoff`; `handle()` шлёт новый
  `sendForeignStandoffScreen()` (без кнопок вовсе, HTML, читает `secondsLeft()` из `PvpStandoffService`
  напрямую — не дублирует формулу).
- Хвост #18: `applyHoldBonus()` теперь `?array` — `null` профиль остаётся `null` (не синтезирует
  защиту из ничего). Вызывающий код (`handle()`) уже принимал `?array $defenseProfile`, правка
  не потребовала изменений сигнатуры снаружи.
- `tests/database/StandoffAttackGateTest.php`: Reflection-тесты переведены на новые имена методов
  (`resolvePreGate()`/`resolveOpen()` хелперы), добавлены 3 новых интеграционных теста (far cell,
  restricted pair, foreign window) и 1 хелпер `createDistantCell()`. `testApplyHoldBonusOnNullProfileStartsFromZero`
  переименован в `testApplyHoldBonusReturnsNullWhenNoDefenseProfile` — старое ожидание («синтезирует
  профиль от нуля») было ровно тем поведением, которое чинит хвост #18.
- Найдено при верификации: `PvpStandoffService` (владеет story `-14`, параллельно) уже несёт
  `COOLDOWN_ARMING_STATUSES = ['held','fled','countered','expired']` — `cancelled` не армирует
  кулдаун защитника. Старый комментарий/тест `testStandoffLeaveActionCancelsOnceAndRefusesSecondTap`
  предполагал обратное («атака после «Уйти» идёт прямо в бой, т.к. защитник на кулдауне») — это уже
  не так независимо от этой стори. Тест переписан на актуальное поведение (повторный тап открывает
  новое окно, а не идёт в бой) — `PvpStandoffService.php` не тронут, только ожидание теста.

## Findings

Не было. Оба BLOCK (#1, #5) и хвост #18 закрыты без правки `PvpStandoffService.php`/`StandoffNotifier.php`.
