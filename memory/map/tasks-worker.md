<!-- Срез-указатель, а не копия территории. Подробность — в mmorpg-vault; здесь только то,
     что нужно, чтобы понять, куда идти, и не вляпаться. Посеян обследованием дерева репозитория
     и конституцией проекта 2026-08-19; углубляется /vulyk-map <path> через drone-scout. -->
last-verified: 2026-09-25

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
- **(2026-09-15) `BaseTaskHandler::telegram()`/`BaseObjectHandler::telegram()` возвращают `?Telegram`**
  через `App\Services\Telegram\TelegramBridge::ensure()` — раньше на неудаче здесь стоял `catch`,
  создающий `new Telegram('invalid','invalid')`, которая сама бросала то же исключение, что ловилась
  (класс-баг, закрыт `cron-delivery-integrity-01`; подробности — `memory/map/telegram.md`, нота
  `mmorpg-vault/tech-writing/tasks/BaseTaskHandler.md`).
- Эксклюзивные (🔒) задачи не стартуют поверх других эксклюзивных — симметрия ADR-167.
- Ловушка (exploit-audit, `docs/specs/exploit-audit/REPORT.md` #9/#27, `EA-tasks-01`/`EA-tasks-02`):
  `character_tasks` несёт только `PRIMARY(id)`, никакого `UNIQUE` на эксклюзивном слоте — гейт
  ADR-167 корректен как чтение, но ничего не резервирует; единственное доказанное нарушение на
  проде — 3 перехлёста Похода у одного персонажа 27.08 (зазоры 9/17/46 с). **Индекс на
  `character_tasks`/`quest_steps` НЕ заведён (2026-09, exploit-fix)** — владелец сократил спеку до
  одного `resources_bank`: ~20+ пишущих в `character_tasks` (`GatherAction`, `MarchAction`,
  все `StartCraft*`, стройка, роботы, теплица) и 4 в `quest_steps` пишут `Model::insert()` напрямую
  без `try/catch` — индекс без их заводки через `insertUnique()` заменял бы «лишнюю строку» на
  необработанный 500. Отдельная спека заведёт все точки и только потом повесит индекс.
- **(2026-09, ADR-181) Старт эксклюзивной 🔒-задачи идёт через `insertUnique()`, а не
  `Model::insert()`.** `ActiveTasksService::insertExclusiveTaskRow()` — единая точка вставки
  `character_tasks` для переезда (`RelocationTaskCreator`); до `UNIQUE`-индекса (которого ещё нет,
  см. выше) просто вставляет и отдаёт `Applied`. Подставляет `created_at`/`updated_at` сама — на
  проде обе `DATETIME NOT NULL` без DEFAULT.
- **`character_tasks.task_settings` не существует ни в одной миграции репозитория** (найдено при
  ремонте тестовых фикстур exploit-fix-11/14) — колонка реально на проде (`text NULL`) и её пишет/
  читает продовый код (`ActiveTasksService`, `RelocationTaskCreator`), но история миграций
  разошлась: физически либо файл миграции отсутствует, либо колонку добавили в обход
  `php spark migrate:create`. Рукописный DDL тестовой схемы для этой таблицы — не недосмотр, а
  вынужденное решение, пока миграция не найдена/не восстановлена.
- **(2026-09-25, ADR-189 — заменяет запись ADR-188 от 24.09) Web-only персонаж с аккаунтом имеет
  виртуальную строку `telegram_users`** (`BackfillVirtualTelegramUsers` + `CharacterProvisioningService`);
  NULL `telegram_user_id` остаётся лишь у персонажа без аккаунта. Чат — через
  `TelegramChatResolver::chatIdForCharacter()` (теперь отдаёт и отрицательный виртуальный id; null →
  пропуск), не `TelegramUserModel::find($id)`: `find(null)` отдаёт ВСЕ строки. Награда — ДО поиска чата.
- Фоновая `send*` в виртуальный чат не уходит в Telegram: `WebDelivery::route()` кладёт её в
  `web_inbox` (флаг `web.play_enabled` on) или отбрасывает (off); `edit*`/`delete*` в виртуальный
  чат отбрасываются. Не надо ставить `<= 0`-guard перед отправкой — только «`> 0` ИЛИ `VirtualChat::is()`».
- Фоновая `edit*`/`delete*` с `message_id >= Config\WebPlay::firstMessageId` (синтетический, сообщение
  есть только на сайте) в Telegram не уходит и привязанному: правка патчит копию на экране `/play` и
  (флаг on) обновляет одну строку входящих. Пример — Поход, начатый на `/play`.
- Worker/cron/spark не ставят `BridgeClient`; всё в них — «фон» для моста.

## Vault
`mmorpg-vault/apps/tasks/index.md` · `mmorpg-vault/tech-writing/tasks/` ·
`tech-writing/services/TelegramChatResolver.md` · `tech-writing/services/WebDelivery.md` (ADR-189)
