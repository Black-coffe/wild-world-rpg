---
story: s2-conditional-writes-05
spec: s2-conditional-writes
status: todo
returned:
tier: 3
worker: worker-code
wave: 2
blocked_by: []
red: required
red_reason: "pharmacy effects applied although the DB held zero doses"
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

## Findings
