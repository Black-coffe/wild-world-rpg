---
story: s1-atomicity-03
spec: s1-atomicity
status: todo
returned:
tier: 3
worker: worker-code
wave: 2
blocked_by: [s1-atomicity-01, s1-atomicity-02]
red: required
# red_reason:
# red_verified_at:
---

# Крафт и ремонт пишут задачу через `insertExclusiveTaskRow()` и честно отказывают

## Goal
После story ядро крафта, продвижение очереди, семь legacy-стартов `StartCraft*2Action` и ремонт создают строку `character_tasks` только через `ActiveTasksService::insertExclusiveTaskRow()` (C2, при нужде `lastInsertedTaskId()` C3). Переходы 🔒-строк в `in_work` идут через `moveToInWork()` (C4). На `Refused` игрок видит `slotTakenText()` (C5), а ресурсы, золото и предметы не списаны (C6). Не продвинувшийся 🔒-крафт из очереди остаётся `queued` без исключения (assumption A4). Пять существующих тестов этих файлов получают схему C9.

## Requirements
> 3. Все места записи в `character_tasks` (24 вызова в 14 файлах, список в брифе) пишут через `ActiveTasksService::insertExclusiveTaskRow()`; отказ БД из-за занятого 🔒-слота игрок видит тем же текстом отказа ADR-167 («уже занят …»), а не ошибкой, и ресурсы при отказе не списываются.

## Files
- app/Services/Craft/CraftOrderService.php
- app/TaskHandlers/Craft/GenericCraftCompletionHandler.php
- app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchStandard/TeleportBeacon/StartCraftTeleportBeaconBasic2Action.php
- app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchStandard/TeleportBeacon/StartCraftTeleportBackpack2Action.php
- app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchStandard/TeleportBeacon/StartCraftPortableTeleport2Action.php
- app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchStandard/Armor/StartCraftReinforcedLeather2Action.php
- app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchStandard/Armor/StartCraftLeatherJacket2Action.php
- app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchStandard/Armor/StartCraftArmorRaggedShirt2Action.php
- app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchStandard/Armor/StartCraftArmorDrifterClothes2Action.php
- app/Controllers/Telegram/Commands/Actions/Craft/Repair/RepairCraftedItemAction.php
- tests/database/CraftExclusiveSlotRefusalTest.php
- tests/database/CraftOrderServiceTest.php
- tests/database/CraftQueueServiceTest.php
- tests/database/CraftQueueCoreTest.php
- tests/database/CraftFeatureGateTest.php
- tests/unit/Transport/VehicleCraftWiringTest.php

## Non-goals
- Не менять гейт `exclusiveConflict()` в `CraftOrderService::gateError` — он остаётся первым слоем, БД ловит только гонку.
- Не переписывать legacy-actions на ядро `CraftOrderService` и не трогать их тексты, кнопки и расчёты: только точка вставки, ветка `Refused` и порядок списания.
- Не трогать `CraftQueueService` (он только читает, `:76`), `cancel()` и возвраты. UPDATE параллельных (⏳) строк и переходы **из** `in_work` оставить как есть.
- Не править `CharacterResourceModel` и вызовы его методов (story 02). В тестах — только DDL по C9 и новые случаи, старые ассерты не переписывать.

## Map slice
`memory/map/craft.md` → `## Entry points` (`CraftOrderService`, `CraftQueueService`) и `## Gotchas` (ADR-181 отмена/продвижение, `CONFIRM_REQUIRED`, ядро не пишет `action_log`). `memory/map/tasks-worker.md` → `## Gotchas` (ADR-167, Worker ставит `completed` до `handle()`). Контракты C2–C6 в `plan.md`.

## Acceptance criteria
- [ ] **Воркер проверяет сам:** как `StartCraftPortableTeleport2Action` создаёт строку задачи (`insert`, `save` или UPDATE существующей, `:182`) и где именно переход очереди `queued → in_work`: около `GenericCraftCompletionHandler.php:284` и `CraftOrderService.php:308`. Решение записано в Implementation notes.
- [ ] Ни в одном файле story не осталось `insert(` / `save(` строки `character_tasks` мимо `insertExclusiveTaskRow()`. Код, копирующий строку задачи, снимает `id` и `exclusive_slot` (C1).
- [ ] `CraftOrderService::start()`: если между гейтом и вставкой 🔒-слот занимает чужая `in_work` 🔒-строка, `start()` отдаёт отказ с текстом `slotTakenText()`, а рюкзак, склад и золото не изменились. Отказ рендерят бот и веб тем же путём, что прочие отказы ядра.
- [ ] Legacy `StartCraft*2Action` и ремонт на `Refused`: ответ игроку — `slotTakenText()`, списаний нет (вставка до списания или в одной транзакции с откатом).
- [ ] Продвижение очереди: 🔒-крафт, которому не дал слот `moveToInWork()`, остаётся `queued`, handler завершается без исключения.
- [ ] Параллельный (⏳) крафт рядом с `in_work` 🔒-задачей стартует как раньше.
- [ ] Красный тест `CraftExclusiveSlotRefusalTest` проверяет отказ `start()` на гонке и неизменность ресурсов. Схему S1 тест берёт запуском `up()` миграции story 01.
- [ ] Пять существующих тестов из `## Files` получили DDL по C9 (все таблицы S1 в каждом файле) и зелёные. Воркер гоняет их точечно; в `## Verification` их нет, чтобы `stamp-red` не запечатал (plan A8).

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/database/CraftExclusiveSlotRefusalTest.php`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes

## Findings
