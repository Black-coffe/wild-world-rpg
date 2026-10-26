# W-REG.1: воронка сайта под регистрацию (plan)

**Tier:** 1 · **Spec slug:** `w-reg-site-funnel` · **Brief:** [brief.md](brief.md)
**Governed by:** ADR-062 (сайт flat, тон), ADR-188 (аккаунты, `web.open_registration`), ADR-189 (`/play`)
**Depends on:** `w-reg-cold-pass` (F4), `web-onboarding` (v0.51.698)

## Goal
С включённым `web.open_registration` сайт ведёт новичка в обе двери: главная и `/about` предлагают
браузер рядом с Telegram, вход говорит, что Google/Яндекс создаст аккаунт. С выключенным флагом — как сейчас.

## Assumptions
- Флаг читается в контроллерах (`Front::home`, `Front::renderPage`, `AccountAuth::render`) через `GameSettingsService` и подаётся во вьюхи переменной; вьюхи GameSettings не зовут.
- Блок «Как начать» на `/about` выводится в `site/page.php` только для slug `about`; текст страницы из БД не меняется (решение владельца 2026-10-26).
- «Играть в браузере» ведёт на `/play`: гостя он отправит на вход с возвратом в `/play` (`AccountSession::RETURN_PLAY`).

## Stories

**Wave 1**
- `w-reg-site-funnel-01` — флаг в контроллерах, две двери на главной и `/about`, «аккаунт создастся» на входе, тест on/off

## Contracts
- none

## Integration gate
`vendor/bin/phpunit --no-coverage --no-progress`

## Descoped

- Правка текста `/about` в БД («прямо в Telegram») — в W-REG.4 вместе с флагом.
- Совет «играть можно в браузере» — в W-REG.4.

## Plan deltas

**Approved:**
**Briefed:** via mini-brief, Andrei, 2026-10-26
**Branch:** vulyk/w-reg-site-funnel
**Checked:**
**Council:**
**Council:** GREEN round 1, 2026-10-26, at 26c92fb7, pack 4c5f300d92b5
**Shipped:**
