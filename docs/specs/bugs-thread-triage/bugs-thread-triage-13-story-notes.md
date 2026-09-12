---
story: bugs-thread-triage-13
spec: bugs-thread-triage
status: todo
tier: 1
worker: worker-code
tracer: false
wave: 5
blocked_by: [bugs-thread-triage-08]
---

# Ремонт: четыре закрытые story с пустыми находками

## Goal

Находка MINOR-11. Четыре story стоят `status: done` с пустыми `## Implementation notes` и
`## Findings`, хотя нашли существенное. Содержание не потеряно — оно в файлах вердиктов и в отчёте —
но `/vulyk-gc` и библиотекарь читают именно `## Findings`, и для памяти улья этих находок не существует.

## Requirements

> Если да, пишешь ответ, что данная система устранена, бага нет.

> смотришь: мы эту систему поправили, баг устранили?

## Files
- docs/specs/bugs-thread-triage/bugs-thread-triage-01-bases.md
- docs/specs/bugs-thread-triage/bugs-thread-triage-02-storage.md
- docs/specs/bugs-thread-triage/bugs-thread-triage-07-live-run.md

## Что делать

Заполни в каждой story `## Implementation notes` (как работали, чем доказывали) и `## Findings`
(что нашли сверх задачи). Источник — соответствующие файлы вердиктов и `REPORT.md`, не выдумка.
По минимуму обязано попасть:

- **story 01:** два живых прод-бага мульти-базы (`TextMapService:127-130`,
  `BuildingUpgradeValidator:83-85`) и то, что они — один класс «первая строка не та строка».
- **story 02:** пять экранов T2, читающих только рюкзак мимо общего пула, и что коммита-кандидата
  для них не существует нигде — это не «не выкачено», а «не написано».
- **story 07:** что гипотеза про путаницу ⬜ и ❄️ **опровергнута** полноразмерным просмотром
  скриншота, и что чем была вызвана жалоба Анжелы — неизвестно.

Не меняй `status:` и не переписывай остальные разделы.

## Non-goals
- Не трогать файлы вердиктов, `REPORT.md` и story 08 (её заметки пишет story 14 вместе с отчётом).
- Не переписывать цели, требования и критерии в этих story.

## Map slice
не нужен — источник в файлах вердиктов той же спеки

## Acceptance criteria
- [ ] В каждой из трёх story оба раздела непусты и содержат названное выше.
- [ ] `status:` не изменён, остальные разделы не тронуты.

## Verification
`grep -c . docs/specs/bugs-thread-triage/bugs-thread-triage-01-bases.md docs/specs/bugs-thread-triage/bugs-thread-triage-02-storage.md docs/specs/bugs-thread-triage/bugs-thread-triage-07-live-run.md`

## Implementation notes

## Findings
