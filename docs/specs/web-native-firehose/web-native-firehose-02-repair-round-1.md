---
story: web-native-firehose-02
status: done
returned: DONE
worker: worker-code
model: opus
wave: 2
blocked_by: []
---

# Repair round 1

## Goal
Make the asks and findings council round 1 left RED pass, and change nothing else.

## Requirements
> 1. Нативный `/play` пишет строку в журнал действий игроков на каждое открытие экрана и каждое действие, с персонажем и статусом (ok/отказ/ошибка), как бот.

## Findings
1. app/Controllers/Play.php:202 [ask 1] a native web action the core refuses on business grounds (craft start, build start, quest start or branch: WebNativeScreenService.php:1128/1135/1145/834/1272/1300 call logRejected, whose markRejected is a no-op, then return an alert) must be written with status `rejected` and the refusal reason, as the bot writes it via BaseAction.php:275; today the controller only flags rejected() (invalid form or stale intent), so these rows land as `ok` - repro: POST `/play/view` `view=craft&op=craft_start&recipe=<hidden or unaffordable>` then `SELECT status FROM player_action_log ORDER BY id DESC LIMIT 1` returns `ok`.
2. app/Services/Logging/PlayerActionLogger.php:101 [ask 1] a bridge tap (`op=bridge`) whose callback the pipeline marks error, rejected or unrouted must reach the single web row with that status; with the pipeline suppressed, markError (UpdatePipeline.php:153/158, where web dispatch exceptions are swallowed), BaseAction markRejected and CallbackqueryCommand markUnrouted all hit an inactive logger, so Play::view records `ok` for every bridge tap that does not raise InvalidArgumentException.

## Files
- app/Services/Logging/PlayerActionLogger.php
- app/Controllers/Play.php
- tests/database/PlayViewControllerTest.php

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes
- Приглушение пайплайна заменено захватом запроса: `PlayerActionLogger::beginWeb()` открывает захват (`active`, `source='web'`, статус `ok`); пока он открыт, `begin()` пайплайна ничего не сбрасывает, а `commit()` ничего не пишет. `markRejected`/`markError`/`markUnrouted` — из ядра (`WebNativeScreenService::logRejected`), `BaseAction`, `CallbackqueryCommand`, `UpdatePipeline` на шагах моста — копят статус и причину в захват. `commitWeb()` пишет одну строку со скопленным статусом, `abortWeb()` закрывает без строки (гейт не пройден).
- Finding 1: деловой отказ ядра → `rejected` + причина (`QUEST_START_…: <code>`), как у бота. Finding 2: сбой/«не понял»/отказ на шаге моста → статус единственной строки.
- `Play::view()` и battle-ветка `act()` — `beginWeb()` → тело → `finally`: `rejected()` → `markRejected`, исключение → `markError` и проброс, `commitWeb()`. Minor «battle-тап, упавший в battleScreen, без строки» закрыт тем же `finally`.
- Тест: `fakeAct('ok'|'error'|'unrouted')` имитирует `UpdatePipeline` (`begin`/`commit` на каждом прогоне, статус на `:cb`); новый `testFirehoseRowCarriesCoreRefusalAndBridgePipelineStatus` — квест 3 (звено цепочки) → `rejected` с причиной; мост `error`/`unrouted` → одна строка с этим статусом. На коде раунда 1 тест красный.
- Не делали (minor, вне asks): без JS battle-тап даёт 2 строки (тап + PRG-загрузка `play`) — как и формы `view`, принято в plan; `LowHealthWarningHandler::PLAYER_INITIATED_SOURCES` без `web` — до спеки, вопрос владельцу.
