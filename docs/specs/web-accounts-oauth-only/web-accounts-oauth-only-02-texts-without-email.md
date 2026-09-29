---
story: web-accounts-oauth-only-02
spec: web-accounts-oauth-only
status: todo
returned:
tier: 2
worker: worker-code
model: sonnet
wave: 2
blocked_by: [web-accounts-oauth-only-01]
---

# Тексты игроку без входа по почте

## Goal
Ни один текст игроку — сайт, кабинет, `/web`, `/guide`, советы Роби — не обещает вход или восстановление по почте с паролем; путь входа описан как Google/Яндекс/Telegram.

## Requirements
> 4. Тексты игроку (сайт, кабинет, `/web`, `/guide`, советы) нигде не обещают вход или восстановление по почте и паролю.

## Files
- app/Controllers/Telegram/Commands/Actions/WebLinkCodeAction.php
- app/Views/site/account_link.php
- app/Views/site/play_stub.php
- app/Services/Onboarding/GuideCatalog.php
- app/Database/Migrations/2026-09-30-100000_FixTipsWithoutEmailLogin.php
- tests/unit/Views/AccountTextsWithoutEmailTest.php

## Non-goals
- Не переписывать тексты, не касающиеся входа.
- Советы правятся идемпотентной миграцией, только если найдены.

## Map slice
memory/map/website.md — аккаунты

## Acceptance criteria
- [ ] Скан-тест: вьюхи аккаунта, `WebLinkCodeAction`, `GuideCatalog` не содержат «почт»/«пароль» в смысле входа (фикстура с исходной и соседней формой).
- [ ] Если в `game_tips` есть совет про вход по почте — миграция правит его по `title_en`; иначе миграции нет и строка из Files удаляется в Implementation notes.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes

## Findings
