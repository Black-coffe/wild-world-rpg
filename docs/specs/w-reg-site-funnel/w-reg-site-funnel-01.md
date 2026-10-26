---
story: w-reg-site-funnel-01
spec: w-reg-site-funnel
status: todo
tier: 1
worker: worker-code
wave: 1
blocked_by: []
---

# Две двери на сайте при открытой регистрации

## Goal
При `web.open_registration` = true главная и `/about` показывают «▶ Играть в браузере» рядом с Telegram и
блок «Как начать», а вход говорит новичку, что Google/Яндекс создаст аккаунт; при false — прежний рендер.

## Requirements
> /vulyk-plan W-REG.1.
> (ROADMAP.md, дословно:) **W-REG.1** · Tier 1 · Воронка сайта под регистрацию: на главной «Играть в браузере» рядом с Telegram, без «Никаких регистраций… Только Telegram» (`app/Views/site/home.php:34,171,173`); вход говорит «Google/Яндекс создаст аккаунт» (`account_login.php`); страница «Об игре». Ask: тон ADR-062 — холодный постапок, без маркетингового шума; компоненты через `public/ui-kit.html`. Поглотил W3.P5 и «конверсионный лендинг» SEO-плана. Источник — `w-reg-cold-pass` F4.

## Files
- app/Controllers/Front.php
- app/Controllers/AccountAuth.php
- app/Views/site/home.php
- app/Views/site/page.php
- app/Views/site/account_login.php
- app/Views/site/_start_doors.php
- tests/database/SiteFunnelTest.php

## Non-goals
- Не трогать текст `site_pages` в БД, шапку, футер, `wildworld-ui.css` и `public/ui-kit.html`.
- Не менять поведение флага в `AccountRegister`/`AccountOAuth`.

## Map slice
memory/map/website.md — «Аккаунты», «Игра на сайте», Gotchas.

## Acceptance criteria
- [ ] Флаг off: главная содержит «Никаких регистраций» и не содержит «Играть в браузере»; вход не содержит «аккаунт создастся»; `/about` без блока «Как начать».
- [ ] Флаг on: главная — «▶ Играть в браузере» (href `/play`) в hero и CTA, нет «Никаких регистраций» и «Только Telegram»; бейдж/«Платформа» — Telegram и браузер.
- [ ] Флаг on: вход первым блоком — Google/Яндекс создаст аккаунт, затем имя персонажа; код из бота — «уже играешь в Telegram».
- [ ] Флаг on: главная и `/about` рендерят один partial `site/_start_doors`; только существующие классы, без JS; тон ADR-062.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/database/SiteFunnelTest.php`

## Implementation notes

## Findings
