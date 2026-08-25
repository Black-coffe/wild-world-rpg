---
story: community-chat-bot-32
spec: community-chat-bot
status: done
tier: 3
worker: worker-code
tracer: false
wave: 9
blocked_by: []
---

# Админка: один порог просрочки, честная доля отказов, маршрут виден в очереди

## Goal
Будильник «вопросы висят без ответа» перестаёт обнуляться собственной суточной чисткой, доля
отказов гварда перестаёт считать отказы отправителя, а маршрут отказа появляется там, где
владелец разбирает очередь.

## Requirements
> чтобы была живая группа, потому что я не всегда успеваю, не всегда могу

> Тут нужно спланировать, как он будет отвечать: автоматически, в ручном режиме, полуавтоматически

## Files
- app/Controllers/Admin/CommunityController.php
- app/Views/admin/community_index.php
- app/Commands/CommunityCleanup.php
- tests/unit/Controllers/Admin/CommunityControllerTest.php
- tests/unit/Commands/CommunityCleanupTest.php

## Non-goals
- Не трогать гвард, тик, отправителя, матчер.
- Не менять дизайн-систему админки: только существующие компоненты `admin-ui.css`.
- Не заводить новых таблиц и колонок.

## В чём дефект
1. `CommunityController::STALE_QUESTION_HOURS = 72` против порога зависших в `CommunityCleanup`
   (`community.question.max_age_hours`, дефолт 48). Чистка в 03:45 переводит все `new` старше 48 ч
   в `ignored`, поэтому строка почти никогда не доживает до 72 ч и главный будильник владельца
   деградирует до одних `escalated`. Две story волны 8 не видели друг друга.
2. `guardDeniedCount()` считает `escalated` без `COMMUNITY_ANSWER_SENT`. Story 23 начала помечать
   `escalated` терминальные отказы ОТПРАВИТЕЛЯ (длина, непарный markdown, неканоничное имя) — у них
   тоже нет `SENT`, и они попадают в числитель «отказов гварда». Story 26 чинила ровно эту болезнь
   с другой стороны.
3. `COMMUNITY_ROUTE_LOGGED` пишется в журнал, но очередь `/admin/community` его не читает: обещание
   «владелец увидит маршрут рядом со строкой очереди» репозиторию не соответствует. Маршрут виден
   только на общем `/admin/audit-log` при ручном фильтре.
4. Тест `testRevokeTargetsEarliestMessageAmongDuplicates` вставляет строки в том же порядке, что и
   `sent_at`, поэтому `first()` вернул бы ту же строку и без `orderBy` — тест не краснеет на дефекте.
5. Story 22 заявила проверенными два пункта, которых нет в тестах: `community_answers` не трогается
   ни при каких условиях, и чистка работает при выключенном `community.enabled`.

## Contract
- Порог «вопрос просрочен» и порог «вопрос закрывается автоматически» происходят из ОДНОГО
  источника; отношение между ними задано явно, а не совпадением двух констант.
- Доля отказов гварда отличает отказ гварда от отказа гейта отправителя по признаку, который
  различает их НА ЗАПИСИ, а не по совпадению статуса.
- Маршрут отказа виден там, где владелец разбирает очередь.
- Тест детерминизма ставит порядок вставки ПРОТИВ порядка сортировки.
- Два незакрытых пункта приёмки story 22 закрываются тестами.
- Правка вьюхи — только токены `admin-ui.css`, без градиентов и глубоких теней.

## Acceptance criteria
- [ ] Вопрос, закрытый автоматической чисткой, не исчезает из поля зрения владельца молча.
- [ ] Терминальный отказ отправителя не увеличивает долю отказов гварда.
- [ ] Маршрут отказа читается в очереди `/admin/community`.
- [ ] Тест отзыва краснеет, если убрать сортировку.
- [ ] Чистка не трогает `community_answers` и работает при выключенном килсвитче — обе проверки в тестах.
- [ ] Tier-2 visual smoke очереди: 1440 / 768 / 375, console clean.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Controllers/Admin/CommunityControllerTest.php tests/unit/Commands/CommunityCleanupTest.php`

## Implementation notes

- Дефект 1: `STALE_QUESTION_HOURS=72` заменён на `staleQuestionHours()` — читает
  `community.question.max_age_hours` (тот же ключ, что `CommunityCleanup`) через
  `GameSettingsReaderTrait`, порог = явная доля `STALE_FRACTION=0.5` от него,
  строго меньше порога авто-закрытия. `index()`/`computeMetrics()` переведены на
  этот метод.
- Acceptance «не исчезает молча»: `CommunityCleanup::auditAutoClosed()` пишет
  `COMMUNITY_QUESTION_AUTO_CLOSED` в `admin_audit_log` на каждую закрытую строку
  (id снимаются `staleQuestionIds()` до `UPDATE`); контроллер добавил
  `autoClosedCount()` → метрика `auto_closed_unanswered`, новая KPI-плитка «Закрыто
  чисткой без ответа» во вьюхе (существующие `.aui-kpi`-компоненты, новых токенов не
  вводилось).
- Дефект 2: `guardDeniedCount()` добавил `NOT EXISTS (... COMMUNITY_ANSWER_REJECTED ...)`
  — различитель на записи между отказом гварда (не пишет `_REJECTED` вовсе) и
  терминальным отказом гейта отправителя (story 23, всегда пишет `_REJECTED`).
- Дефект 3: `openQuestionsFlat()` собирает id `escalated`-строк и подмешивает
  `route` через новый `routesByMessageId()` (последняя `COMMUNITY_ROUTE_LOGGED` на
  строку); вьюха показывает маршрут под статус-бейджем (`.aui-muted.aui-small`,
  существующие классы).
- Дефект 4: тест отзыва переставлен — `$later` вставляется ДО `$earlier` (более
  ранний по `sent_at`, но со старшим `id`), так что `first()` без `orderBy`
  вернул бы неверную цель.
- Story 22 gaps: добавлены `testCleanupNeverTouchesCommunityAnswers` (создаёт
  `community_answers` через Forge-миграцию как временную таблицу теста) и
  `testRunIgnoresCommunityEnabledKillswitch` (двойник `GameSettingsService` с
  `community.enabled=false`, вызов через `run()` — единственный путь, где
  `$settings` реально консультируется).
- `CommunityCleanupTest` — своя изолированная `admin_audit_log` (реальная схема
  `tests` отстаёт на непрогнанные миграции, паттерн `CommunityChatSenderTest`).
- Файлы вне `## Files` не трогались; конкурентная правка `CommunityGuard.php`
  другим воркером в общей рабочей копии задела `git stash` — восстановлена через
  `git checkout stash@{0} -- <мои файлы>`, чужой файл не тронут.
- Tier-2 visual smoke (MCP Chrome, 1440/768/375) не выполнен — MCP Chrome
  недоступен в этой сессии; owner-воркер сообщил, что организует его отдельно.
