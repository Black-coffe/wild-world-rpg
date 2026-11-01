---
id: once-guard-in-cache
title: Защита «раз в сутки» у массовой рассылки держится на кэше
status: text
check:
fixtures: []
keys: [пришло дважды, дубль рассылки, совет дня два раза]
paths: ["app/TaskHandlers/**", "app/Services/Notifications/**"]
---

# Защита «раз в сутки» у массовой рассылки держится на кэше

«Совет дня» охранялся cache-ключом; деплой или `cache:clear` в час рассылки стирал маркер, и всем игрокам
приходил дубль.

## Нельзя
- Держать once-per-period guard крон-рассылки в кэше.

## Как надо
- Атомарный условный UPDATE строки-маркера в БД (`SET value=:today WHERE key=:k AND value != :today`, `affectedRows() === 1`), claim до цикла рассылки; маркер сидится миграцией со значением `''`, не NULL.
- Тест: отправить → очистить кэш → отправить ещё раз → 0 отправок. Эталон — `DailyTipBroadcastHandler::claimToday()`.

## Source
- 2026-06-01 · auto memory feedback_once_per_day_guard_db_not_cache · retold, not a quote
