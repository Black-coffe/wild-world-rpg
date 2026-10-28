---
story: s2-conditional-writes-01
spec: s2-conditional-writes
status: todo
returned:
tier: 3
worker: worker-code
model: haiku
wave: 1
blocked_by: []
red: required
red_reason: "stale seed snapshot: quantity went below zero or plant task row created"
---

# F4: семя списывается условием «≥ 1» до создания задачи посадки

## Goal

`PlantCropActionStart.php:97-118` пишет `quantity - 1` без условия `>= 1` и без проверки `affectedRows`; гейт —
чтение `:77-81`. После story списание семени — `decrementIfAtLeast` (или инлайн условный `UPDATE` с `affectedRows`,
если строка семени не адресуется одним `id`), и строка задачи посадки создаётся только на `Applied`; `Refused`/
`Missing` откатывают транзакцию и показывают существующий текст `:80`. `model: haiku` — повтор уже принятого в
проекте шаблона (`decrementIfAtLeast` на списании перед выдачей), один файл, один тест.

## Requirements

> 1. F4 — посадка (`PlantCropActionStart`) списывает семя условием «количество ≥ 1» с проверкой затронутых строк; при отказе
> F4 семя без условия «≥ 1» (`PlantCropActionStart.php:101-103`)

## Files

- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/Greenhouse/PlantCropActionStart.php
- tests/database/PlantCropSeedConditionalWriteTest.php

## Non-goals

- Не трогать `tests/exploit-poc/GapAuditTest.php` (`testSeedDeductionCannotDriveQuantityNegative`): если он
  позеленел — записать в отчёт, не править.
- Не менять ни катало́г культур, ни длительность роста, ни `GameSettings` теплицы.
- Не переписывать существующий `transStart`/`transComplete` на другую форму — только добавить ветку отказа с откатом.
- Не вводить новый текст: отказ — существующая строка `:80`.

## Map slice

`memory/map/bases.md` → Entry points (стройка/теплица как рендерер), Gotchas («Старт стройки (ADR-181)»: списание
условное под транзакцией — образец формы); `memory/map/tasks-worker.md` → Gotchas (вставка `character_tasks`,
`created_at`/`updated_at` NOT NULL без DEFAULT). Контракт примитива и форма теста — `plan.md` → `## Contracts`.

## Acceptance criteria

- [ ] Списание семени — одна условная запись с проверкой затронутых строк; прочитанное в гейте количество в
      `UPDATE` не участвует.
- [ ] Задача посадки вставляется только после `Applied`; на `Refused`/`Missing` транзакция откатывается, строки
      задачи нет, игрок видит существующий текст «У тебя нет семян…»; `Missing` дополнительно пишет `error` в лог.
- [ ] Транзакция завершается ровно один раз на каждом пути выхода; исход — по возврату, не по `transStatus()`.
- [ ] Тест `PlantCropSeedConditionalWriteTest`: персонаж с одним семенем проходит гейт; тест обнуляет/удаляет
      строку семени в БД; вызов продового списания → отказ, количество не ниже 0, в `character_tasks` нет новой
      строки. Сообщение ассерта при провале: `stale seed snapshot: quantity went below zero or plant task row created`.
- [ ] Фикстура строит таблицы из миграций по факту отсутствия (приём `exploit-fix-13`), не дропает чужие.

## Verification

`vendor/bin/phpunit --no-coverage --no-progress tests/database/PlantCropSeedConditionalWriteTest.php && vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes

## Findings
