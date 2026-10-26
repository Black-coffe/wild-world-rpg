# Плашка «Новое сообщение» свёрнута в строки (plan)

**Tier:** 1 · **Spec slug:** `w-reg-banner` · **Brief:** [brief.md](brief.md)
**Governed by:** ADR-062 (сайт flat), ADR-189 (`/play`), ADR-020 (media-off); `.claude/rules/web-public.md`
**Depends on:** web-onboarding-03 (плашка), W-REG.3 `v0.51.703` (375 px)

## Goal
Плашка новых сообщений над `/play` перестаёт съедать первый экран телефона: каждое из (до трёх) непрочитанных
сообщений показывается одной строкой `<details><summary>` с первой строкой текста, а целиком — по тапу. Отбор
сообщений (`WebInboxService::banner`, `bannerMax`), «ещё N» и «Скрыть» не меняются. JS переносит раскрытое состояние
через замену плашки опросом.

## Assumptions
- Строки свёрнуты на всех ширинах, а не только ≤480 px: одна разметка, и на 1440 три сообщения целиком тоже
  отодвигают экран игры. Отвергнуто: свёрнуто только на телефоне. Это потребовало бы два рендера или CSS-хак поверх `<details>`.
- Заголовок строки — первая непустая строка `text`, иначе `caption`, без разметки, обрезка ~80 символов с «…»;
  ни того ни другого — «Сообщение».
- `bannerMax` (3) не трогаем.

## Stories

**Wave 1**
- `w-reg-banner-01` — `<details>`-строки в `_play/banner.php` + CSS + перенос раскрытого состояния в JS + ui-kit + `?v=`.

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
