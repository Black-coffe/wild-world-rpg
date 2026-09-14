# ADR harvest — docs/specs/angela-second-base-bugs

Source: `## Plan deltas` and `## Assumptions` in `docs/specs/angela-second-base-bugs/plan.md`.
Existing ADR numbers checked in `C:/Projects/mmorpg-vault/decisions/` — highest is ADR-186
(`ADR-186-Base-standoff-window-before-field-pvp.md`). Next free: **ADR-187**.

---

## Proposed: ADR-187

```markdown
---
type: adr
id: ADR-187
title: Удалённое управление постройками через Вышку связи разрешает неоднозначность первой активной базой
status: proposed
date: 2026-09-14
supersedes: []
relates: [ADR-102, ADR-031]
tags: [bases, buildings, multibase, communication-tower, resolver]
---

# ADR-187 — Удалённое управление постройками через Вышку связи разрешает неоднозначность первой активной базой

## Context

Из `## Plan deltas` (plan.md, 2026-09-13):

> **2026-09-13 · Удалённое управление чинится общим резолвером, а не хинтом в
> `callback_data`.** Триггер: после волны 1 у мульти-базового игрока под сигналом Вышки связи
> клик по кнопке постройки перестал открывать карточку — `resolveTargetBaseCell` отдаёт `null`,
> дверь отвечает «встань на базу», хотя экран рядом обещает «Можно управлять сооружениями
> удалённо!». Решение: `BaseScopeResolver` (см. `## Contracts`) — при покрытии вышкой берётся
> первая активная база по `id`, та же, что показывает `DetailedBaseInfoAction` после фикса его
> собственной выборки (`status='active'` + `orderBy`). Отклонено: (а) хинт базы в `callback_data`
> — ломает кнопки в уже отправленных сообщениях и требует правки роутера; (б) выбор базы кнопками
> в духе `TeleportUseValidator` (ADR-102) — спрашивает игрока о том, что экран уже знает, и
> меняет общий текст отказа, закреплённый тестом истории 05. Цена принятого решения названа
> честно: при ≥2 базах удалённо управляется ПЕРВАЯ активная, а не выбранная игроком; сознательный
> выбор базы для удалённого управления — тема отдельной спеки, если владелец её захочет.

`ClaimedCellModel::resolveTargetBaseCell` (ADR-102) уже решает «где стоит игрок ⇒ какая база
активна» для случая, когда игрок физически на клетке. Этот дельта добавляет отдельное правило
для случая, когда игрок НЕ на базе, но накрыт сигналом Вышки связи (ADR-031) — ранее необработанный
путь, из-за которого волна 1 сломала уже существующую фичу удалённого управления.

## Options

1. **Хинт базы в `callback_data`** — резолвит неоднозначность на уровне кнопки, но ломает кнопки
   в уже отправленных сообщениях (старый `callback_data` без хинта) и требует правки роутера.
   Отклонено.
2. **Выбор базы кнопками (по образцу `TeleportUseValidator`, ADR-102)** — корректно с точки зрения
   игрока, но спрашивает о том, что экран уже знает, и меняет общий текст отказа, закреплённый
   тестом истории 05. Отклонено.
3. **`BaseScopeResolver`: под покрытием Вышки — первая активная база по `id`** (та же, что
   показывает `DetailedBaseInfoAction`). Принято.

## Decision

Когда игрок не стоит ни на одной из своих баз, но покрыт сигналом Вышки связи, `BaseScopeResolver`
берёт **первую активную базу по `id`** как цель удалённого управления — то же правило, которым
`DetailedBaseInfoAction` уже выбирает базу для показа. Выбор базы игроком для этого случая не
реализуется.

## Consequences

- Удалённое управление постройками через Вышку связи снова работает после волны 1 (регресс
  закрыт), не требуя правки роутера кнопок.
- Игрок с ≥2 активными базами при удалённом управлении всегда попадает на ПЕРВУЮ активную базу
  по `id`, а не на выбранную им — цена явно принята и записана дельтой, а не открытие.
- Текст отказа для по-настоящему неоднозначных случаев (нет покрытия вышкой, игрок не на базе)
  остаётся прежним и закреплён тестом истории 05 — резолвер для Вышки не переиспользует эту ветку.
- Будущий код, работающий с постройками под покрытием Вышки (роботы, генераторы, любой удалённый
  экран), обязан идти через тот же `BaseScopeResolver`, а не изобретать собственное правило выбора
  базы — иначе экран и `DetailedBaseInfoAction` снова разойдутся в том, о какой базе идёт речь.

## Revisit when

Владелец захочет сознательный выбор базы для удалённого управления (делта называет это темой
отдельной спеки) — тогда придётся вводить выбор базы кнопками (отклонённый вариант 2) или другой
механизм, и переопределять эту границу.
```

---

## Judged, did not earn an ADR

- **`## Verification` cell must be the full-suite command, not a per-file placeholder**
  (2026-09-13 delta) — bookkeeping about how `close-story`/`wave-check` read the `## Commands`
  table. No future story re-decides game architecture from this; it constrains VULYK tooling
  usage, not code shape.
- **`phpstan-baseline.neon` touched by story 01 outside its `## Files`** (2026-09-13 delta) —
  a one-time housekeeping exception for a pre-existing static-analysis debt, not a decision a
  future story needs to re-derive.
- **`scripts/cycle.sh` patched to read both constitutions** (2026-09-13 delta) — a framework
  patch to VULYK itself, already the kind of thing tracked in `docs/vulyk/ADAPTATION.md` per
  `CLAUDE.vulyk.md` convention; a second record in `decisions/` would duplicate that ledger.
- **Workflow driver failed to launch, cycle ran on the fallback driver** (2026-09-13 delta) —
  session-local operational hiccup, no bearing on code that will exist.
- **Wave 2–3 stories cite `## Asks` lines instead of bug reports as quote source** (2026-09-13
  delta) — a `trace-check.sh` sourcing convention for this spec's paperwork, not a constraint
  on future code.
- **`## Assumptions` — "current base" resolved via existing `resolveTargetBaseCell` contract** —
  restates ADR-102, does not extend it. Covered by ADR-102.
- **`## Assumptions` — three surfaces (map/cards/upgrade) in scope, others (`Robots/*`,
  `TeleportBeacon*`, `BaseService.php:78`, `BaseCampDecorService`) explicitly out of scope** —
  a scope boundary for this spec's stories, not an architectural rule for code that does not
  exist yet; the open tails are already logged in `## Открытые хвосты`, not ADR material.
- **`## Assumptions` — media-off is carried by each story's acceptance criteria, not a
  separate gate command** — restates the existing MEDIA-OFF constitutional rule (`CLAUDE.md`),
  not a new decision.
- **`## Assumptions` — tech-writing notes updated by `drone-docs` after merge, deploy sequence
  owned by Queen** — process/caste-assignment bookkeeping per `CLAUDE.vulyk.md`, not a game
  architecture decision.

## Gaps — reason missing, no ADR possible

None found. Every decision-shaped item in `## Plan deltas` and `## Assumptions` either carried
its own stated reason (and was judged above) or was a restatement of an already-accepted ADR.
