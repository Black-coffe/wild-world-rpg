# D1 — ежедневный «Совет дня» перестаёт качать спящих (plan)

**Tier:** 1 · **Spec slug:** `d1-relevel-l1-l2` · **Brief:** [brief.md](brief.md)
**Governed by:** ADR-038 (советы, Фаза C — авто-рассылка), ADR-024 (баланс в GameSettings), ADR-134 (tips), `ROADMAP.md` D1
**Depends on:** v0.51.673

## Goal

Диагноз (разведка на проде, 26.09): рассылка «Совет дня» с 22.05 (`96024234`, v0.51.230) через
`TipService::serveTip()` → `recordView()` начисляет +0.01 опыта / +0.02 ловкости / +0.04 интеллекта
каждому персонажу с `daily_tips_enabled=1` — без проверки активности и до отправки (321 из 378 L2
заблокировали бота). Крон `HealthRegenerationHandler` переводит эти статы в уровень; к 13.09
~350 неиграющих персонажей стали L2, воронка `/admin/funnel` врёт. Решение владельца: **чинить
источник и метрику, без отката данных.**

## Assumptions

- Награда за ручной `/tips` остаётся: это действие игрока (совет #22 её обещает).
- Числа награды — баланс (ADR-024) → переезжают в GameSettings с теми же значениями по умолчанию.
- Откат статов у спящих не делается (решение владельца 26.09).

## Stories

**Wave 1**
- `story-01` — рассылка без награды и без заблокировавших; награда в GameSettings; текст настроек и совет #22 честные; воронка считает прогресс по игре, а не по level.

## Contracts

- `TipService::serveTip(int $characterId, bool $reward = true): ?array` — `/tips` зовёт с `true`, рассылка с `false`.

## Integration gate

`vendor/bin/phpunit --no-coverage --no-progress` · `vendor/bin/phpstan analyse --memory-limit=512M --no-progress` · `php -l` миграций

## Descoped

*(empty)*

## Plan deltas

**Briefed:** via mini-brief, owner (Q «Как чиним D1?» → «Источник + метрика, без отката»), 2026-09-26
**Branch:**
**Checked:**
**Council:**
**Shipped:**
