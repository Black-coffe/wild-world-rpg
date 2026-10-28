---
story: s2-conditional-writes-05
spec: s2-conditional-writes
status: done
returned: DONE
tier: 3
worker: worker-code
wave: 2
blocked_by: []
red: required
red_reason: pharmacy effects applied although the DB held zero doses
red_verified_at: 2026-10-28T21:11:05Z ed6a18f96ac0e1356d4a53b6fcac1833ff51031efef99bf370dd6268a5f1b946
---

# F14: доза препарата списывается условно до лечения, снятия дебаффов и «сытости»

## Goal

`UsePharmacyAction.php`: лечение `:249`, `cureByItem` `:247`, `maybeApplyWellFed` `:90` идут до списания
`decrementItemUsage` `:259-315` (двухуровневый счётчик `durability_count → quantity → delete`), транзакции нет. После
story порядок перевёрнут: сначала одна условная запись дозы (`durability_count = durability_count - 1 WHERE id = ?
AND durability_count >= 1`, инлайн с `affectedRows` — у примитива нет формы под двухуровневый счётчик; перекат на
следующую единицу/удаление строки — следующими записями в той же транзакции), эффекты — только на подтверждённое
списание; отказ → существующий текст «препарат закончился» (`:73`/`:260`), здоровье и дебаффы нетронуты.

## Requirements

> 5. F14 — препарат (`UsePharmacyAction`) списывает дозу условной записью ДО лечения, снятия дебаффов и «сытости»; при нуле
> F14 доза препарата до лечения

## Files

- app/Controllers/Telegram/Commands/Actions/UsePharmacyAction.php
- tests/database/PharmacyDoseConditionalWriteTest.php

## Non-goals

- Не менять силу лечения, список дебаффов, длительность «сытости» и правило зажима зарядов при слиянии стака.
- Не выносить списание в новый сервис и не трогать `CraftedItemsLogModel`: правка в самом handler'е; raw `update()`
  модели крафт-предметов молча не срабатывает (`feedback_crafted_items_log_raw_set_update_noop`) — писать `$db->query()`.
- Не трогать `tests/exploit-poc/DuplicationTest.php::testOneHealPerMedicineCharge`.
- Не править `phpstan-baseline.neon`: устаревшие записи — списком под `## Findings`, `WALL`.

## Map slice

`memory/map/player.md` → Gotchas («Списание крафт-предметов — только `deductCraftedItem`; raw-set `update()` молча
не срабатывает», «Заряды при слиянии стаков зажимаются»); `memory/map/telegram.md` → Entry points (action-handler'ы).
Образец инлайн-CAS по строке крафт-предмета — `TeleportItemConsumer` (`player.md`, запись exploit-fix-08). Контракт и
форма теста — `plan.md` → `## Contracts`.

## Acceptance criteria

- [ ] Списание дозы — условная запись с проверкой затронутых строк, выполняется до любого эффекта; прочитанные в
      гейте `durability_count`/`quantity` в `WHERE` участвуют только как предусловие «≥ 1», не как новое значение.
- [ ] Лечение, `cureByItem`, `maybeApplyWellFed` идут только после подтверждённого списания, в одной транзакции с ним;
      упавший эффект откатывает списание. Транзакция завершается ровно один раз на каждом пути.
- [ ] Доза в БД 0 / строки нет → «препарат закончился», никаких эффектов; `Missing` пишет `error`.
- [ ] Перекат `durability_count → quantity → delete` сохраняет сегодняшний видимый результат для честного игрока
      (одна доза = одно использование; пустой предмет исчезает).
- [ ] Тест `PharmacyDoseConditionalWriteTest`: персонаж с препаратом в одну дозу и сниженным здоровьем + дебафф
      проходит гейт; тест ставит `durability_count = 0` (и/или `quantity = 0`) в БД; вызов → отказ, здоровье и дебаффы
      равны исходным, строка не ушла в минус. Сообщение ассерта при провале:
      `pharmacy effects applied although the DB held zero doses`. Handler тестируется на уровне метода записи,
      без живого Telegram.

## Verification

`vendor/bin/phpunit --no-coverage --no-progress tests/database/PharmacyDoseConditionalWriteTest.php && vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes

- tests/database/PharmacyDoseConditionalWriteTest.php: вызов настоящего `handle()`, обнуление `quantity`/`durability_count` через `DBQuery` после гейт-SELECT; добавлен `ensureColumns()` (только `ALTER ... ADD COLUMN` недостающих колонок у чужих урезанных `characters`/`crafted_items`/`crafted_items_log`, без DROP) — так стенд с осиротевшей таблицей соседа больше не блокирует тест (тот же приём, что `IntentApplierRelativeDeltasTest`).
- red_reason: снял кавычки — `fm_raw` берёт значение буквально, и `stamp-red` не находил строку в выводе.
- app/Controllers/Telegram/Commands/Actions/UsePharmacyAction.php: `decrementItemUsage` заменён на `consumeDose()` — одна условная запись `WHERE id = ? AND character_id = ? AND crafted_item_id = ? AND quantity >= 1` + `affectedRows()`, затем `DELETE ... WHERE id = ? AND quantity <= 0`; перекат заряд → единица → удаление посчитан в SQL (eff = min(max(stored ?? base, 1), base)), `quantity` в SET первой.
- Решение: предусловие — `quantity >= 1`, не буквальное `durability_count >= 1` из Goal: блюда хранят `durability_count = 0` при шаблоне 0, `effectiveCharges()` считает это одной дозой; буквальное условие отказало бы честному игроку в еде.
- `applyMedicineEffect`: транзакция `transBegin` → списание → «Сытость» → `cureByItem` → `adjust` → `transCommit`; отказ списания / `adjust() === null` / исключение — `transRollback` ровно один раз; отказ → «У тебя нет нужного препарата, или он закончился.», `Missing` пишет `log_message('error')`.
- `maybeApplyWellFed` перенесён из `handle()` внутрь транзакции после списания; побочный эффект: предмет, не попавший ни в settings-heal, ни в switch («не найден в списке»), больше не даёт «Сытость» без списания.

- Королева 28.10: baseline снят коммитом `aebbd7a3` (`chore(baseline)`), код story не менялся; `returned: DONE` выставлен по слову воркера «правок кода больше не требуется», close-story перезапущен.

## Findings

- Прошлый стенд-блокер снят тестом (`ensureColumns`, без DROP); вопрос про `quantity >= 1` решён сам (Implementation notes).
- NEEDS_CONTEXT: `close-story` exit 4 только на phpstan — 9 устаревших baseline-записей по `app/Services/Events/IntentApplier.php` (`ignore.unmatched`/`ignore.count`). Это незакоммиченная правка соседа по волне s2-conditional-writes-06 (его Findings — тот же список); `UsePharmacyAction.php` в phpstan чист, `PharmacyDoseConditionalWriteTest` зелёный (8 assertions), red receipt есть. Нужно: Королева закрывает 06 / правит `phpstan-baseline.neon` (вне `## Files` обоих), затем перезапуск `close-story` этой story — правок кода больше не требуется.

## Red receipt
- command: vendor/bin/phpunit --no-coverage --no-progress tests/database/PharmacyDoseConditionalWriteTest.php && vendor/bin/phpstan analyse --memory-limit=512M --no-progress
- exit: 1
- tests: ed6a18f96ac0e1356d4a53b6fcac1833ff51031efef99bf370dd6268a5f1b946 (tests/database/PharmacyDoseConditionalWriteTest.php)
- impl: 8e701818f13be20065d520377e4f0da32157806e38c93d6160b2cd135808ccc5
- stamped: 2026-10-28T21:11:05Z

```
C:\laragon\www\mmorpg\vendor\phpunit\phpunit\src\Framework\TestCase.php:1667
C:\laragon\www\mmorpg\vendor\phpunit\phpunit\src\Framework\TestCase.php:519
C:\laragon\www\mmorpg\vendor\phpunit\phpunit\src\Framework\TestRunner\TestRunner.php:87
C:\laragon\www\mmorpg\vendor\phpunit\phpunit\src\Framework\TestCase.php:365
C:\laragon\www\mmorpg\vendor\phpunit\phpunit\src\Framework\TestSuite.php:369
C:\laragon\www\mmorpg\vendor\phpunit\phpunit\src\TextUI\TestRunner.php:64
C:\laragon\www\mmorpg\vendor\phpunit\phpunit\src\TextUI\Application.php:211

[37;41mFAILURES![0m
[37;41mTests: 1[0m[37;41m, Assertions: 2[0m[37;41m, Failures: 1[0m[37;41m, PHPUnit Deprecations: 1[0m[37;41m.[0m
```
