---
story: web-accounts-oauth-only-01
spec: web-accounts-oauth-only
status: todo
returned:
tier: 2
worker: worker-code
model: sonnet
wave: 1
blocked_by: []
---

# Убрать вход, регистрацию и сброс по почте с паролем

## Goal
Маршруты, контроллеры, вьюхи и сервисы входа по почте удалены вместе с их тестами. Вход — Google, Яндекс, Telegram-виджет и код `/web`.

## Requirements
> 1. На сайте нет входа, регистрации и сброса по почте с паролем: форма почты на `/account/login`, страницы `/account/register` (email-форма), `/account/reset` и `/account/reset/<token>`, форма «почта и пароль» в кабинете убраны; их маршруты больше не принимают запросы (404 или переход на `/account/login`).
> 2. Вход и регистрация на сайте — только через Google и Яндекс (первый вход создаёт аккаунт при включённом `web.open_registration`), плюс Telegram-виджет и код `/web` из бота; кнопки Google/Яндекс без ключей в env видны как недоступные с объяснением.
> 3. Код входа по почте (`AccountAuthService` email-ветки, `PasswordResetService`, `AccountPassword`, провайдер `email`) удалён вместе с тестами на него; никакой другой код на него не ссылается.

## Files
- app/Config/Routes.php
- app/Controllers/AccountAuth.php
- app/Controllers/AccountRegister.php
- app/Controllers/AccountCabinet.php
- app/Controllers/AccountPassword.php
- app/Services/Web/AccountAuthService.php
- app/Services/Web/PasswordResetService.php
- app/Views/site/account_login.php
- app/Views/site/account_register.php
- app/Views/site/account_cabinet.php
- app/Views/site/account_reset.php
- tests/database/AccountAuthTest.php
- tests/database/AccountCabinetTest.php
- tests/database/AccountRegistrationTest.php
- tests/database/AccountPasswordResetPageTest.php
- tests/database/PasswordResetServiceTest.php

## Non-goals
- Не менять схему (`secret_hash`, `account_tokens` остаются).
- Не трогать OAuth-поток и Telegram-вход, кроме удаления ссылок на почту.
- Тексты вне страниц аккаунта — story 02.

## Map slice
memory/map/website.md — аккаунты

## Acceptance criteria
- [ ] GET `/account/login` — нет полей почты и пароля, есть Google/Яндекс/Telegram; POST `/account/login`, `/account/register`, `/account/identity/email`, `/account/reset*` — 404; GET `/account/reset*` — редирект на `/account/login`.
- [ ] `git grep -n "PasswordResetService\|registerWithEmail\|setEmailPassword\|verifyPassword" app tests` пуст.
- [ ] OAuth и Telegram-тесты зелёные; полный набор и phpstan зелёные.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes

## Findings
