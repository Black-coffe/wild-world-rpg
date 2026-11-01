# D-DEAD-RES heal line — числа лечения в итоге крафта (plan)

**Tier:** 1 · **Spec slug:** `d-dead-res-heal-line` · **Brief:** [brief.md](brief.md)
**Governed by:** ADR-020 (media-off), ADR-024 (лечение в GameSettings), ask 6 спеки `d-dead-res`
**Depends on:** `d-dead-res` (merge `14d21c9f`, не запушен) — ключи `medical.<snake>.heal_*`

## Goal
Сообщение «📌 Крафт завершён!» для лекарства, лечение которого задано в GameSettings, показывает числа лечения:
здоровье и выносливость. Так итог крафта читается без фото так же полно, как карточка рецепта. Едет одним тегом
`v0.51.709` вместе с `d-dead-res`.

## Assumptions
- Строку собирает чистая статическая функция с геттером настройки на входе — тестируется без Telegram и БД.
  Порядок ключей, `snake`-правило и «нет `heal_health` → нет строки» — как у `UsePharmacyAction::resolveEffectsFromSettings`.
- Охват — все `drug` с ключами (4 новых, T3-медицина S19, сезонные и еда с `medical.*`). Legacy 8 без ключей — без строки.
- Вторую кнопку («🎒 Инвентарь») не трогаю: не в ask.

## Stories

**Wave 1**
- `d-dead-res-heal-line-01` — строка лечения в `notifyUser` + тест на функцию строки (red).

## Contracts
none

## Integration gate
`vendor/bin/phpunit --no-coverage --no-progress`

## Descoped

*(empty)*

## Plan deltas

**Approved:**
**Briefed:** via mini-brief, Andrei, 2026-11-01
**Branch:** vulyk/d-dead-res-heal-line
**Checked:**
**Council:**
**Council:** GREEN round 1, 2026-11-01, at efdb7648, pack 83618f32a2d7
**Shipped:**
