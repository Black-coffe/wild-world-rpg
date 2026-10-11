---
story: web-native-firehose-01
spec: web-native-firehose
status: done
returned: DONE
tier: 2
worker: queen
model: opus
wave: 1
blocked_by: []
---

# Нативный /play пишет одну строку firehose на экран и действие

## Goal
`PlayerActionLogger` получает автономную запись `recordWebAction()` (`source='web'`, `character_id`, без
Telegram-полей) и флаг `suppressPipeline()`. `Play::view()` пишет ровно одну строку на запрос: ok, rejected
или error. На время моста пайплайн приглушён, и мост даёт одну строку. `GET /play` и battle-экраны из `act()`
пишут строку открытия.

## Requirements
> Хвост: нативные `/play/view` не пишут firehose → web-only игроки вне WAU.
> 1. Нативный `/play` пишет строку в журнал действий игроков на каждое открытие экрана и каждое действие, с персонажем и статусом (ok/отказ/ошибка), как бот.
> 2. Одно нажатие «мостом» — одна строка в журнале, служебные шаги моста не пишутся.
> 3. Веб-игроки без Telegram попадают в метрики активности (строка несёт `character_id`).
> 4. Выключатель журнала из админки (`logging.player_actions.enabled`) действует и на веб; сбой записи никогда не ломает игру.

## Files
- app/Services/Logging/PlayerActionLogger.php
- app/Controllers/Play.php
- tests/database/PlayViewControllerTest.php

## Non-goals
- Не трогать `UpdatePipeline`, `WebActService`, `WebNativeScreenService` (немой `markRejected` — вне спеки).
- Не заполнять `origin` у веб-строк.
- Не менять схему `player_action_log`.

## Map slice
`memory/map/website.md` (`/play`), `memory/map/telegram.md` (UpdatePipeline, firehose).

## Acceptance criteria
- [ ] `POST /play/view` открытие экрана → 1 строка `source=web`, `action_name=<view>`, `character_id`, `status=ok`.
- [ ] Нативное действие → 1 строка `action_name=<op>`; отказ → `status=rejected`.
- [ ] `op=bridge` → ровно 1 строка (`action_name` = префикс callback), служебных строк пайплайна нет.
- [ ] Killswitch выключен → 0 строк; ошибка вставки не роняет ответ.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes
- `PlayerActionLogger::recordWebAction()` — по образцу `recordTaskCompletion()`: `source='web'`, Telegram-поля null, `raw_input` ≤ 255 (ширина колонки), killswitch тот же, не бросает. `suppressPipeline(bool)` — флаг синглтона; `begin()` при нём оставляет `active=false`, `commit()` — no-op.
- `Play::view()` — обёртка над прежним телом (`viewScreen()`): действие = `op`, иначе `view`; `raw_input` = `view=…&op=…&id=…`; мост — префикс callback и сам callback, как у бота. Статус: `rejected()` ставит флаг, исключение — `error` и проброс. Пайплайн приглушается только на мосту и снимается в `finally`. Строка пишется, только если гейт пройден (персонаж известен).
- `GET /play` пишет `play` (`raw_input` `view=…`, если открыт нативный экран). Battle-кнопки в `act()` мимо пайплайна пишут строку с префиксом callback.
- Флаги читаются через геттеры `firehoseCharacter()`/`firehoseStatus()`: phpstan иначе сужает их до значений, присвоенных перед вызовом.
- Тесты: в `PlayViewControllerTest` (там уже есть `player_action_log` и хелперы сессии), а не в новом файле — `## Files` поправлен. Три теста: 4 запроса → 4 строки с персонажем сессии (экран, шаг, отказ, мост с двумя прогонами `act`); killswitch → 0 строк; приглушённый `begin/commit` → 0, после снятия → 1.
