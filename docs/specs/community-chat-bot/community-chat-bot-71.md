---
story: community-chat-bot-71
spec: community-chat-bot
status: review
tier: 1
worker: worker-test
tracer: false
wave: 19
blocked_by: []
---

# Тесты тика опираются на живое вето, а не на снятое

## Goal
Семь тестов тика перестают падать: они проверяют отказ гварда, вызванный правилом, у которого
вето есть, а не провенансом, у которого его больше нет.

## Requirements
> чтобы люди не использовали его для того, чтобы узнать какие-то фишки, лайфхаки и читинг в игре, и потом это не применяли

## Files
- tests/unit/TaskHandlers/CommunityAutoReplyHandlerTest.php

## В чём дело
Story 63 по ADR-178 сняла с провенанса право вето. Семь тестов тика строили сценарий «гвард
отказал» на тексте без опоры в корпусе («Теплица строится на базе.») — раньше это давало
`deny('no_provenance')`, теперь даёт `allow` с пометкой. Тесты падают в полном наборе, хотя
по отдельности файл был зелёным до правки гварда.

Падают: `testGuardDenyEscalatesAndSendsRouteTextToPlayer`,
`testGuardDenyRouteTextRespectsHourlyCapLeavesOnlyReaction`,
`testGuardDenyRouteTextRespectsAuthorCooldownLeavesOnlyReaction`,
`testDenyVerdictRouteIsObservableInAuditLog`,
`testGuardDenialLogsRouteForEveryDuplicateInGroupNotJustRepresentative`,
`testGuardDenialInsertFailureRollsBackStatusInsteadOfLosingMetricSignal`,
`testRouteLogTimestampUsesDatabaseClockNotPhpDate`.

## Non-goals
- 🔴 Не менять `CommunityGuard` и не возвращать провенансу вето: снятие вето — принятое решение ADR-178, а не поломка.
- Не ослаблять утверждения тестов: цель каждого («маршрут доезжает», «потолок глушит», «часы БД», «откат вставки») сохраняется целиком, меняется только способ вызвать отказ.
- Не трогать `CommunityAutoReplyHandler` — дефекта в нём нет.

## Acceptance criteria
- [ ] Отказ в каждом из семи сценариев вызывается правилом, у которого вето есть: сравнительная форма (R1–R4), стоп-лист, стоп-тема или килсвитч.
- [ ] Цель каждого теста сохранена дословно — проверь по его имени и докблоку.
- [ ] Ни один тест не начинает зависеть от `provenance_mode=deny`: аварийный режим не должен быть предусловием обычного теста.
- [ ] Полный набор зелёный.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/TaskHandlers/CommunityAutoReplyHandlerTest.php`

## Implementation notes

- Отказ во всех семи тестах теперь вызывается рубежом 3 гварда (лексический стоп-лист, вето сохранено ADR-178): `answer_text` банк-ответа получил слово `быстрее` (уже в `CommunityGuard::LEXICAL_STOPLIST`) — деним ДО провенанса, независимо от корпуса/режима `provenance_mode`.
- Гвард в этих тестах сменён с `denyingGuard()` (пустой корпус — раньше гарантировал `deny('no_provenance')`, теперь при default `advisory` даёт `allow()` с пометками) на `permissiveGuard()`: корпус реалистичный, отказ идёт от содержимого ответа, а не от искусственно пустого корпуса.
- `denyingGuard()` оставлен только для `testEscalateGuardDenialDoesNotOverwriteStatusChangedBetweenReadAndWrite` (story 57) — там нужен произвольный `Verdict` для прямого вызова `escalateGuardDenial()` через `ReflectionMethod`, allow/deny не имеет значения; докблок обновлён, чтобы не вводить в заблуждение про гарантию deny.
- Ни один тест не завязан на `provenance_mode=deny` — настройка нигде не устанавливается.
- `CommunityGuard` и `CommunityAutoReplyHandler` не тронуты — только тестовый файл.
- Верификация: `vendor/bin/phpunit --no-coverage --no-progress tests/unit/TaskHandlers/CommunityAutoReplyHandlerTest.php` → `Tests: 33, Assertions: 100` — все зелёные (одна PHPUnit-deprecation, не относится к правке, не failure).

## Findings
