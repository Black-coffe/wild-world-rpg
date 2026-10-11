# Нативный /play пишет журнал действий игроков (plan)

**Tier:** 2 · **Spec slug:** `web-native-firehose` · **Brief:** [brief.md](brief.md)
**Governed by:** ADR-148 (firehose `player_action_log`), ADR-168 (origin), ADR-189 (веб-мост), ADR-190 (ядро + два рендерера)
**Depends on:** W2.N1–N7 (нативные экраны `/play`)

## Goal
Веб-игрок, который играет в нативном `/play`, оставляет в `player_action_log` такой же след, как бот-игрок. Каждый
`POST /play/view` даёт ровно одну строку `source='web'` с `character_id`. Открытие экрана пишется как
`action_name=<view>`, действие — как `action_name=<op>`, `raw_input` — `view=…&op=…`, статус — ok, rejected
(отказ ядра или формы) или error (исключение). Нажатие через мост (`op=bridge`) — тоже одна строка:
`action_name` = префикс callback, как у бота, а пайплайн на время моста не пишет свои служебные строки (`:card`,
`:sN`, `/go`). Battle-экраны из `POST /play/act` и первая загрузка `GET /play` пишут строку открытия. Запись идёт
через новый `PlayerActionLogger::recordWebAction()` по образцу `recordTaskCompletion()`. Он уважает killswitch
`logging.player_actions.enabled` и никогда не бросает исключение.

## Assumptions
- **`GET /play` пишет строку `action_name='play'`** при каждой загрузке страницы, включая перезагрузку и PRG после
  формы без JS. Клиент с JS (основной) идёт через `POST /play/view` и PRG не делает. У игрока без JS форма даёт
  2 строки (действие + загрузка). Это принято: такой путь редок, а загрузка страницы — честное «открыл игру».
- **Приглушение моста — флаг на синглтоне логгера** (`suppressPipeline`), который `begin()` уважает. Флаг ставится
  и снимается в `finally` контроллера. `/play/act` (прямой тап по экрану моста) не меняется: там один тап — один
  прогон пайплайна — одна строка, как сейчас.
- **Немой `markRejected()` в `WebNativeScreenService::logRejected` (`:1925`) не трогается:** отказ теперь пишет
  контроллер. Вызов остаётся безвредным no-op, чистка — вне спеки (Law 3).
- **`origin` (ADR-168) у веб-строк — null:** тег экрана-источника — механика бота. Колонка в вебе не заполняется.
- **Объём:** строк станет больше на число веб-тапов (WAU ≈ 25). Ретенция `player_action_log` уже есть
  (`Adr148PlayerActionLogRetentionGameSettings`). Новых таблиц и колонок нет, `WipeManifest` не трогается.
- **Ворота валидации — 🟢:** невидимая аналитика, баланс и игрок не затронуты. ADR не нужен, строка в ADR-148.

## Stories

**Wave 1**
- `web-native-firehose-01` — `recordWebAction` + приглушение пайплайна в логгере; запись в `Play::view/index/act`
  (battle); тест на БД.

## Contracts
- `PlayerActionLogger::recordWebAction(?int $characterId, string $actionName, ?string $rawInput, string $status = 'ok', ?string $errorText = null): void`
- `PlayerActionLogger::suppressPipeline(bool $on): void` — пока true, `begin()` не активирует захват.
**Briefed:** via grill, Andrei, 2026-10-11
**Branch:** vulyk/web-native-firehose
**Council:** RED round 1, 2026-10-11, at db73aef5, pack b10881080dee
**Council:** GREEN round 2, 2026-10-11, at 87bfb812, pack 0e52d5d39668
