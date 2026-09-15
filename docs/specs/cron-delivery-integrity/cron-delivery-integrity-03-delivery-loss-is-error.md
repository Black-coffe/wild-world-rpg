---
story: cron-delivery-integrity-03
spec: cron-delivery-integrity
status: done
returned: DONE
tier: 3
worker: worker-code
tracer: false
wave: 2
blocked_by: [cron-delivery-integrity-01]
model: sonnet
---

# Потеря доставки видна на проде

## Goal
Провал отправки игроцкого сообщения пишется уровнем `error`: `PvEService.php:188`
(`PvE notify failed`) и `BaseTaskHandler` — `safeSendMessage` not-ok (`:120`), `safeSendPhoto`
not-ok (`:175`), `degradeToText` not-ok (`:258`). Порог логгера не трогаем. В Implementation notes
воркер записывает решение с причиной: нужен ли маркер в `action_log` (на проде мониторинг идёт по
нему) — и, если да, делает его в этих же файлах.

## Requirements
> Провал отправки игроцкого сообщения в PvE-пути и в `BaseTaskHandler::safeSend*` пишется уровнем `error` — строка видна при прод-пороге логгера 4.

## Files
- app/Services/Player/PvEService.php
- app/TaskHandlers/BaseTaskHandler.php
- tests/unit/Services/Player/PvEServiceNotifyFailureTest.php

## Non-goals
- Не менять `app/Config/Logger.php` — подъём порога потянет поток `[DEPRECATED]`-шума.
- Не трогать `PveNotificationSender.php` — это файл `-02` в той же волне.
- Не менять `warning` в ветке фото «файла нет → деградация в текст» (`:160`) — сообщение доходит текстом, это не потеря.
- Не трогать `TelegramBridge` и подъём моста в `telegram()` — сделано в `-01`.
- Не расширять на прочие сервисы «сквозняком» — только названные точки.

## Map slice
`memory/map/pve-pvp.md`, `memory/map/tasks-worker.md`.

## Acceptance criteria
- [ ] Исключение из `notificationSender->send()` в `PvEService::attack()` логируется `error`; бой и награды по-прежнему засчитаны (исключение не всплывает).
- [ ] Три not-ok ветки `BaseTaskHandler` пишут `error`.
- [ ] Решение по `action_log`-маркеру записано с причиной (и `ActionLogModel` enum не нарушен, если маркер есть — `feedback_action_log_enum_strict_values`).

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`

## Implementation notes

- `app/Services/Player/PvEService.php:188` — `PvE notify failed` поднят с `warning` до `error`.
- `app/TaskHandlers/BaseTaskHandler.php` — три not-ok ветки подняты до `error`: `safeSendMessage`
  (sendMessage not-ok), `safeSendPhoto` (sendPhoto not-ok → деградация в текст),
  `degradeToText` not-ok. Ветка «файла точно нет → деградация» (`is_file`-предпроверка) осталась
  `warning` — это не потеря доставки (Non-goals).
- **Решение по `action_log`-маркеру: НЕ добавлен.** Причина: и в PvE-пути, и в трёх
  `BaseTaskHandler`-ветках провал теперь пишется уровнем `error`, который прод-логгер (порог 4)
  реально записывает в файл — то есть событие уже видно на проде без обхода через БД. Маркеры
  `action_log`/`OnbNudge_*` существуют именно как замена для `info`/`warning`, которые на проде
  немые (`reference_prod_info_not_logged_monitor_via_markers`); здесь этот обходной путь не нужен,
  т.к. сам лог теперь смотрибелен. Добавление маркера дублировало бы уже читаемый `error`-лог без
  дополнительной ценности — оставлено вне скоупа (Law 2). Прецедент — `PvEService::logBossKill`
  (уже использует `ActionLogModel` с `action_status='Completed'`) показывает, что при необходимости
  расширения enum ломать не пришлось бы: он использовался бы без миграции. Если позже понадобится
  агрегируемая по времени статистика провалов доставки (не просто «видно в логе») — заводить
  отдельной story с миграцией enum, а не тут.
- Тест `tests/unit/Services/Player/PvEServiceNotifyFailureTest.php` — `PveNotificationSender` и
  `PveCombatValidator` — `final class`, подклассить нельзя. Обошёл: для notify — реальный
  `PveNotificationSender` с подменённой (не-final) `TelegramUserModel::find()`, которая бросает;
  для combat-валидации — реальный `PveCombatValidator`, но с подменёнными `NpcSpawnModel`/
  `NpcModel` (оба не final), т.к. таблицы `npcs`/`npc_spawns`/`battle_logs` — легаси, ни одна
  миграция их не создаёт (только `ALTER`/seed), поэтому в изолированной «только-миграционной» БД
  их нет. Для `battle_logs` (пишет `PveBattleLogWriter`, тоже `final`, не обойти подменой)
  первая версия теста полагалась на ручной raw SQL в БД до прогона — не самодостаточно, падало
  на честной пустой БД (close-story поймал). Фикс: тест сам создаёт `battle_logs` в `setUp()`
  и дропает в `tearDown()` (только эту таблицу, `CREATE TABLE IF NOT EXISTS` / `DROP TABLE IF
  EXISTS` через `Config\Database::connect('tests')`) — проверено на честной свежей
  `wildworld_ci_cdi03` (DROP+CREATE DATABASE перед прогоном), зелёно, таблица не остаётся после.

## Findings
