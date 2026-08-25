---
story: community-chat-bot-57
spec: community-chat-bot
status: review
tier: 2
worker: worker-code
tracer: false
wave: 15
blocked_by: [community-chat-bot-58]
---

# Тик: прямое обращение доходит до матчера, маршрут отказа не считается ответом

## Goal
Сообщение с `addressed_to_bot=1` попадает в тик независимо от эвристики «похоже на вопрос», а
отправка маршрута отказа перестаёт учитываться как ответ бота.

## Requirements
> чтобы когда игроки что-то спрашивают, уточняют по игре, он отвечал, коммуницировал, комментировал, чтобы была живая группа

> Двухскоростной: мгновенно на знакомое, отложенно на новое

## Files
- app/TaskHandlers/Community/CommunityAutoReplyHandler.php
- tests/unit/TaskHandlers/CommunityAutoReplyHandlerTest.php

## Три дефекта, найденные ревью 2026-08-25

**1. Выборка тика режет раньше матчера.** `CommunityAutoReplyHandler.php:177-182` фильтрует
`where('is_question', 1)`. «Роби, подскажи», «@robibot помоги» — ни `?`, ни вопросительного слова,
значит `is_question=0`, значит строка с `addressed_to_bot=1` в тик не попадает **никогда**: ни
ответа, ни `UNKNOWN`, ни реакции. Докблок `CommunityAnswerMatcher.php:23-24` обещает, что молчание
на прямое обращение структурно невозможно — утверждение верно про класс матчера и неверно про
систему, потому что до матчера дело не доходит. Выборка обязана брать `is_question=1 OR
addressed_to_bot=1`.

**2. Маршрут отказа считается ответом бота.** `sendGuardRouteText()` (`:568-577`) зовёт
`sendAnswer()`, который пишет `COMMUNITY_ANSWER_SENT`. Story 58 заводит `sendGuardRoute()` с
действием `COMMUNITY_ROUTE_SENT` — перейти на него. Имя действия — контракт story 58/59, не
переименовывать.

**3. Эскалация затирает правку владельца.** `escalateGuardDenial()` (`:526-528`) ставит
`status='escalated'` безусловным `whereIn('id', …)->update()`, без `WHERE status=…`, в отличие от
дисциплины `markGroup($onlyIfStatus)` рядом (`:316-335`). Правка владельца между чтением тика и
апдейтом молча теряется. Урок проекта: `feedback_atomic_means_verify_the_write`.

## Non-goals
- Не трогать `CommunityIngestService` (story 56) и `CommunityChatSender` (story 58).
- Не трогать админку и её метрики (story 59).
- Не трогать гвард, его рубежи и пороги.
- Не выводить отказ гварда из-под часового потолка топика — осознанное решение story 58.

## Acceptance criteria
- [ ] Сообщение с `addressed_to_bot=1` и `is_question=0` попадает в выборку тика и получает решение матчера.
- [ ] Подслушанное сообщение с `is_question=0` в выборку по-прежнему не попадает.
- [ ] Маршрут отказа уходит через `sendGuardRoute()` и пишет `COMMUNITY_ROUTE_SENT`.
- [ ] `escalateGuardDenial()` обновляет строку условным апдейтом и не затирает статус, изменённый между чтением и записью; факт неприменения виден в журнале.
- [ ] Каждый пункт подан тестом, краснеющим на возврате прежнего поведения.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/TaskHandlers/CommunityAutoReplyHandlerTest.php`

## Implementation notes

- Дефект 1: `handle()` заменил `where('is_question', 1)` на `groupStart()->where('is_question', 1)->orWhere('addressed_to_bot', 1)->groupEnd()` вместе с `where('status', 'new')` — теперь прямое обращение без `?`/вопросительного слова доходит до матчера. Обновлён докблок над `handle()`.
- Дефект 2: `sendGuardRouteText()` теперь зовёт `CommunityChatSender::sendGuardRoute()` (story 58) вместо `sendAnswer()` — маршрут отказа пишет `COMMUNITY_ROUTE_SENT`/`COMMUNITY_ROUTE_REJECTED` вместо `COMMUNITY_ANSWER_*`. Обновлён существующий тест `testGuardDenyRouteTextRespectsHourlyCapLeavesOnlyReaction` (проверял `COMMUNITY_ANSWER_REJECTED`, стал `COMMUNITY_ROUTE_REJECTED`) и добавлена проверка `COMMUNITY_ROUTE_SENT` в `testGuardDenyEscalatesAndSendsRouteTextToPlayer`.
- Дефект 3: `escalateGuardDenial()` — апдейт статуса стал условным `WHERE status='new'` (как `claimGroup()`/`markGroup($onlyIfStatus)` рядом), сверка `affectedRows() === count($coveredIds)`; при несовпадении — `transRollback()`, `return false`, факт пишется в лог (`log_message('error', …)`, содержит `guard denial escalation skipped`, ожидаемое/фактическое число строк и id).
- Тесты добавлены: `testAddressedWithoutQuestionHeuristicStillReachesMatcher`, `testOverheardWithoutQuestionHeuristicStillSkipsTick` (дефект 1), assert-блок в `testGuardDenyEscalatesAndSendsRouteTextToPlayer` (дефект 2), `testEscalateGuardDenialDoesNotOverwriteStatusChangedBetweenReadAndWrite` (дефект 3, через `ReflectionMethod` на `escalateGuardDenial()`, симулирует зазор между чтением тика и эскалацией).
- `php -l` зелёный на обоих файлах. `vendor/bin/phpunit` НЕ запускал (запрет team-lead — общая БД `wildworld_tests`, параллельные воркеры) — верификацию прогонит team-lead.
- Не трогал `CommunityIngestService`, `CommunityChatSender`, админку/метрики, гвард — как в Non-goals.

## Findings
