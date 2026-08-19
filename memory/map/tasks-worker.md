<!-- Срез-указатель, а не копия территории. Подробность — в mmorpg-vault; здесь только то,
     что нужно, чтобы понять, куда идти, и не вляпаться. Посеян обследованием дерева репозитория
     и конституцией проекта 2026-08-19; углубляется /vulyk-map <path> через drone-scout. -->
last-verified: 2026-08-19

# Scout report: Фоновая обработка (cron → Worker → TaskHandlers)

## Purpose
Всё, что происходит «со временем»: добыча, разведка, стройка, крафт, регенерация, события,
налоги, ежедневные задания. Задача ставится в `character_tasks`, минутный крон её завершает.

## Entry points
- `app/Controllers/Worker.php::processTasks()` — dispatcher завершения `character_tasks`.
- `app/Config/Tasks.php` — расписание (`codeigniter4/tasks`); **recurring**-обработчики
  (HealthRegen, EventActivation, FoodAndWater, Greenhouse) живут здесь, не в `Worker`.
- `app/Services/Handlers/HandlerRegistry.php` — сопоставление типа задачи и handler-класса.
- `app/TaskHandlers/` — 74 файла; группы: `Built`, `Craft`, `Drone`, `Endgame`, `Events`, `Farm`,
  `NPC`, `Objects`, `Onboarding`, `Oracle`, `PVP`, `Quests`, `Referral`, `Streak`, `Tips`, `Titles`.
- `app/TaskHandlers/Contracts/TaskHandlerInterface.php`, `BaseTaskHandler.php`.

## Key types / contracts
Handler реализует `TaskHandlerInterface` и получает уже заклеймленную задачу.

## Dependencies
inbound: cron (scheduler + legacy curl-cron на `GET /cbgsvd-d-dw/worker`, strangler-режим F4.1).
outbound: почти все доменные сервисы + `Services/Notifications` для сообщений игроку.

## Gotchas
- Гонки закрыты тремя слоями: `flock()` на `worker.lock`, атомарный claim
  `UPDATE ... WHERE status='in_work'` (affected_rows=0 → задача уже чужая), `singleInstance(2*MINUTE)`.
- **Worker ставит статус `completed` ДО вызова `handle()`** — completion-handler не должен
  полагаться на «задача ещё in_work».
- В тестах не поднимать Telegram эйджерно: `telegram()` в конструкторе handler'а валит DB-тесты.
- Эксклюзивные (🔒) задачи не стартуют поверх других эксклюзивных — симметрия ADR-167.

## Vault
`mmorpg-vault/apps/tasks/index.md` · `mmorpg-vault/tech-writing/tasks/`
