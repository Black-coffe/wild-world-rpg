---
story: web-accounts-hardening-01
spec: web-accounts-hardening
status: done
returned: DONE
tier: 1
worker: worker-code
model: sonnet
wave: 1
blocked_by: []
---

# Текущий пароль при смене; страницы сброса без Statable и с Referrer-Policy

## Goal
Кабинет меняет пароль своей почты только по верному текущему паролю. Страницы сброса пароля не
подключают Statable и отдают `Referrer-Policy: no-referrer`.

## Requirements
> Всё, давай по рекомендациям дальше двигайся и закрывай все недочёты, всё, что по убыванию, по рекомендации вперёд.

## Files
- app/Services/Web/AccountAuthService.php
- app/Controllers/AccountCabinet.php
- app/Views/site/account_cabinet.php
- app/Controllers/AccountPassword.php
- app/Views/site/_layout/statable.php
- tests/database/AccountCabinetTest.php
- tests/database/AccountPasswordResetPageTest.php
- tests/database/AccountAuthTest.php

## Non-goals
- Не делать подтверждение email (#7) — отдельная спека.
- Не убирать Statable/Google Fonts с остальных страниц сайта.

## Map slice
memory/map/website.md — аккаунты

## Acceptance criteria
- [ ] POST смены пароля своей почты без `current_password` или с неверным — 422, «Неверный текущий пароль», хэш прежний; с верным — хэш новый.
- [ ] Первое добавление почты работает без текущего пароля, как раньше.
- [ ] GET `/account/reset` и `/account/reset/<token>` — заголовок `Referrer-Policy: no-referrer`, в HTML нет `statable.com` при заданном `STATABLE_SITE_HASH`; на обычной странице сайта Statable остаётся.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`

## Implementation notes
- `setEmailPassword(..., ?string $currentPassword)`: при своей почте с хэшем — `password_verify` текущего, иначе `ERR_CURRENT_PASSWORD` («Неверный текущий пароль. Забыл его — сбрось пароль по почте.»). Поле «Текущий пароль» в форме кабинета — только когда почта уже есть.
- `AccountPassword::render()` ставит `Referrer-Policy: no-referrer` и `noThirdPartyAnalytics`; `statable.php` при флаге ничего не рендерит.
- `AccountAuthTest` менял пароль своей почты без текущего — теперь это отказ; тест проверяет оба случая (файл добавлен в `## Files` при сборке).
- Тест Statable сбрасывает общий рендерер: сохранённые данные вьюх иначе переживают рендер в том же процессе. Оба новых теста падают на коде до правки.

## Findings
