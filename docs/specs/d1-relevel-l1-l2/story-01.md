---
story: d1-relevel-l1-l2-01
spec: d1-relevel-l1-l2
status: todo
returned:
tier: 1
worker: worker-code
tracer: false
wave: 1
blocked_by: []
---

# «Совет дня» не качает спящих; воронка считает прогресс по игре

## Goal

Ежедневная рассылка советов (`DailyTipBroadcastHandler`) перестаёт начислять статы и перестаёт слать
тем, кто заблокировал бота; награда остаётся только за ручной `/tips` и берётся из GameSettings.
Текст экрана настроек и совет #22 больше не обещают прокачку от ежедневной рассылки.
`/admin/funnel` перестаёт считать неиграющих персонажей продвинувшимися.

Контекст (прод, 26.09): награда 0.07 суммы/сутки дошла до L2 у 378 неиграющих; 321 из них
заблокировали бота. Формула уровня (`HealthRegenerationHandler::calculateLevel`) не трогается.

## Files
- app/Services/Player/TipService.php
- app/TaskHandlers/Tips/DailyTipBroadcastHandler.php
- app/Controllers/Telegram/Commands/Actions/SettingsAction.php
- app/Services/Admin/FunnelAnalyticsService.php
- app/Views/admin/funnel.php
- app/Database/Migrations/2026-12-12-100000_D1TipRewardGameSettingsAndTip22.php
- tests/database/TipServiceTest.php
- tests/database/DailyTipBroadcastHandlerTest.php
- tests/**/FunnelAnalyticsServiceTest.php

## Non-goals
- Не откатывать статы и уровни существующих персонажей, не писать backfill.
- Не менять формулу уровня и `HealthRegenerationHandler`, `LevelProgressService`.
- Не менять размер награды за `/tips` (значения по умолчанию = нынешние 0.01 / 0.02 / 0.04).
- Не трогать другие рассылки и их фильтры по `blocked_at`.
- Не переписывать остальные советы и дизайн админ-страницы воронки сверх подписи метрики.

## Map slice
`memory/map/player.md`, `memory/map/admin.md`, `memory/map/tasks-worker.md`, `memory/map/data-layer.md`

## Acceptance criteria
- [ ] `TipService::serveTip(int $characterId, bool $reward = true)`: при `false` показ записывается (дедуп 15 дней общий), статы не меняются. `TipsCommand` поведение не меняет.
- [ ] `DailyTipBroadcastHandler` вызывает `serveTip(..., false)` и не выбирает персонажей, чей `telegram_users.blocked_at IS NOT NULL`.
- [ ] Награда читается из GameSettings: `tips.reward.experience` / `tips.reward.agility` / `tips.reward.intellect` (float, по умолчанию 0.01 / 0.02 / 0.04), каждая с `rationale`, above/below-эффектами, soft/hard-границами и reset-to-default по образцу `TipsCSeedGameSettings`; миграция идемпотентна и откатываема; константы `REWARD_*` удалены или помечены как fallback по умолчанию.
- [ ] Та же миграция переписывает контент совета #22 (`game_tips.id=22`) без чисел баланса: награда — за открытый вручную `/tips`, ежедневный совет от Роби — просто чтение; тон Роби, markdown-safe; `down()` возвращает прежний текст.
- [ ] `SettingsAction`: подпись «Совет дня» (строка ~387) больше не обещает «микро-прокачку» от рассылки; упоминает, что награду даёт `/tips`. Текст самодостаточен (media-off).
- [ ] `FunnelAnalyticsService`: корзины уровней и `stuck_l1` не засчитывают продвижение неиграющим — персонаж без записей в `explored_cells` идёт в отдельную корзину «не начали игру» (или эквивалент) и считается «застрявшим» независимо от `level`; `l2plus` в когортах считает только персонажей с движением. `app/Views/admin/funnel.php` подписывает новую корзину/определение.
- [ ] Тесты: рассылка не меняет статы и пропускает заблокировавших; `/tips` по-прежнему награждает значениями из GameSettings; воронка кладёт персонажа L2 без движения в «не начали игру».
- [ ] Вердикты: guide — нет (бэкенд + правка текста настроек, новой механики нет); tips — да, правка существующего совета #22 (без новых советов). WipeManifest — новых таблиц/колонок нет.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/database/TipServiceTest.php tests/database/DailyTipBroadcastHandlerTest.php` + тест воронки + `vendor/bin/phpstan analyse --memory-limit=512M --no-progress` + `php -l app/Database/Migrations/2026-12-12-100000_D1TipRewardGameSettingsAndTip22.php`

## Implementation notes

## Findings
