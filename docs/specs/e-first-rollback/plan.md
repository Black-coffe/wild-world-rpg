# E-FIRST rollback-safe — нижняя кнопка первого шага работает всегда (plan)

**Tier:** 1 · **Spec slug:** `e-first-rollback` · **Brief:** [brief.md](brief.md)
**Governed by:** ADR-103 (навигация не теряется), ADR-168 (метка `origin`)
**Depends on:** `e-first` (merge 4ee35897, v0.51.708)

## Goal
Откат флага `onboarding.cold_open_v2.first_step_keyboard` не должен оставлять новичка с мёртвой кнопкой. Текст «🧭 Сделать
первый шаг» приходит только с нижней клавиатуры E-FIRST, поэтому его роут и разовое полное меню после него работают при любом
значении флага. Флаг управляет только тем, что получает новый игрок на `/start`, и меню после inline-шага Роби.

## Assumptions
- Набрать «🧭 Сделать первый шаг» руками (со значком) может и не-новичок: он получит компас и, один раз, полное меню — безвредно.
- Меню после inline `move~cold` остаётся под флагом: при OFF поток байт-в-байт как до E-FIRST.

## Stories

**Wave 1**
- `e-first-rollback-01` — роут кнопки и меню после неё без гейта флага; меню после inline-шага под флагом.

## Contracts
none

## Integration gate
`vendor/bin/phpunit --no-coverage --no-progress`

## Descoped

*(empty)*

## Plan deltas

**Approved:**
**Briefed:** via mini-brief, Andrei, 2026-11-01
**Branch:** vulyk/e-first-rollback
**Checked:**
**Council:**
**Council:** GREEN round 1, 2026-11-01, at b26d9962, pack 5adb80c20183
**Shipped:**
**Shipped:** v0.51.708, 2026-11-01, at e3b4c58c - merged to develop, publish pending (вместе с e-first)
