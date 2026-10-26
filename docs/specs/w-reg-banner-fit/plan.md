# Авто-раскрытие плашки только если влезает (plan)

**Tier:** 1 · **Spec slug:** `w-reg-banner-fit` · **Brief:** [brief.md](brief.md)
**Governed by:** ADR-062, ADR-189; W-REG.5 (`docs/specs/w-reg-banner/`)
**Depends on:** `v0.51.704`

## Goal
`openNewest` в `wildworld-play.js` раскрывает новейшее сообщение плашки уже в DOM и сразу меряет верх `#play-state`;
если он ниже ¾ `innerHeight`, сворачивает обратно. В `setBanner` раскрытие идёт после `replaceWith`, чтобы замер был
по живой раскладке.

## Assumptions
- Порог ¾ окна — константа JS-вёрстки, не `GameSettings`.
- Замер по документной координате (`top + scrollY`): игрок, прокрутивший страницу вниз, не теряет раскрытие из-за прокрутки.

## Stories

**Wave 1**
- `w-reg-banner-fit-01` — замер после раскрытия в `openNewest`, `?v=6`.

## Contracts
none

## Integration gate
`vendor/bin/phpunit --no-coverage --no-progress`

## Descoped

*(empty)*

## Plan deltas

**Approved:**
**Briefed:** via mini-brief, Andrei, 2026-10-26
**Branch:**
**Checked:**
**Council:**
**Shipped:**
