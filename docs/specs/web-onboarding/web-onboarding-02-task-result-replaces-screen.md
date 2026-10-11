---
story: web-onboarding-02
spec: web-onboarding
status: todo
returned:
tier: 3
worker: worker-code
model: opus
wave: 1
blocked_by: []
---

# Экран законченной задачи сам заменяется её итогом

## Goal
Opus: нужно решить, какой экран «про задачу» и какая фоновая отправка — именно её итог, на стыке `Worker`, `WebDelivery`
и хранилища экрана. Ошибка здесь подменит экран чужим сообщением.

После этой story сохранённый экран `/play`, после действия которого у персонажа появилась новая задача `in_work`,
помнит её id (`task_id` в состоянии `WebScreenStore`). `Worker` на время `handle()` каждой задачи выставляет контекст
«обрабатывается задача X» и снимает его в `finally`. Когда `WebDelivery::route()` видит фоновую отправку
персонажу, у которого сохранённый экран помечен той же задачей X, он заменяет экран этим сообщением. Старый экран
уходит в историю, `task_id` очищается, `rev` растёт. Строка входящих добавляется как сейчас, с тем же `message_id`,
что у сообщения на экране. Состояние получает `rev` и методы `revision()` / `screenMessageIds()` (`plan.md` → `## Contracts`).

## Requirements
> 2. Итог задачи (добыча, крафт) — на экране: экран законченной задачи сам заменяется её итогом, без «❌ Прервать».
> 6. Экран читается в media-off: приветствие, итог задачи и плашка нового сообщения несут весь смысл текстом; в веб-текстах нового первого экрана нет бот-лексики («ниже», «нижнее меню», «команда /…»).

## Files
- app/Controllers/Worker.php
- app/Services/Web/WebDelivery.php
- app/Services/Web/WebScreenStore.php
- app/Services/Web/WebActService.php
- app/Config/WipeManifest.php
- tests/database/WebTaskResultScreenTest.php

## Non-goals
- Не трогать `Play.php`, вьюхи, JS и входящие (`WebInboxService`): показ новой ревизии экрана и плашка — story 03.
- Не трогать 74 task-handler'а: точка одна — `Worker` вокруг `handle()`.
- Не заменять экран сообщением, отправленным вне контекста задачи (шаг цепочки из `QuestObjectiveHandler`, рассылки):
  они идут плашкой.
- Не трогать нативные экраны (`WebNativeScreenService`, `view=craft|base`): они не хранятся (допущение плана).
- Не ставить в `Worker` и в фон `BridgeClient`. Не добавлять `<= 0`-guard: только «`> 0` ИЛИ `VirtualChat::is()`».
- `app/Config/WipeManifest.php` — только если всё же понадобилась новая колонка. Иначе файл не трогается.

## Map slice
`memory/map/tasks-worker.md` → Entry points, Gotchas («Worker ставит completed ДО handle()», виртуальный чат,
синтетические `message_id`); `memory/map/website.md` → «Игра на сайте», Gotchas `/play`;
`docs/specs/web-onboarding/recon.md` → «Веб-доставка».

## Acceptance criteria
- [ ] Действие на `/play` (мост), которое стартует задачу (добыча), сохраняет экран с `task_id` этой задачи. Действие
      без новой задачи пишет `task_id = null`.
- [ ] Фоновая отправка в контексте задачи X персонажу с экраном `task_id = X` заменяет `screen` этим сообщением
      (текст или caption, фото, кнопки — целиком). Прежний экран лежит первым в `history`, лимит истории соблюдён,
      `rev` вырос на 1. На экране больше нет «❌ Прервать».
- [ ] Та же отправка без контекста задачи (или в контексте другой задачи Y) экран не меняет, только входящие.
- [ ] Повторная фоновая отправка в контексте X после замены экран не трогает: `task_id` уже очищен.
- [ ] `message_id` сообщения на экране равен `message_id` строки входящих, добавленной тем же `route()`.
- [ ] Контекст снимается в `finally`: исключение в `handle()` не оставляет его следующей задаче.
- [ ] Работает и для виртуального чата, и для привязанного TG-игрока (у которого есть `web_play_state`). Отправка в
      Telegram привязанному не меняется.
- [ ] `revision()` и `screenMessageIds()` отдают актуальные значения. Старое состояние без `rev`/`task_id` читается как
      `rev = 0`, `task_id = null`.
- [ ] Тест на БД строит схему из миграций (не рукописный DDL) и покрывает всё выше, включая шаг цепочки в ту же минуту.
- Media-off: тест рендера показывает, что итог на экране несёт весь смысл текстом/caption без фото (ask 6).

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes

## Findings
