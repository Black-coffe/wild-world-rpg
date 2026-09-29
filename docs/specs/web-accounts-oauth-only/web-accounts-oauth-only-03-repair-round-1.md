---
story: web-accounts-oauth-only-03
status: todo
returned:
worker: worker-code
model: opus
wave: 3
blocked_by: []
---

# Repair round 1

## Goal
Make the asks and findings council round 1 left RED pass, and change nothing else.

## Requirements
> 3. Код входа по почте (`AccountAuthService` email-ветки, `PasswordResetService`, `AccountPassword`, провайдер `email`) удалён вместе с тестами на него; никакой другой код на него не ссылается.

## Findings
1. app/Services/Web/AccountService.php:27 [ask 3] the `email` provider must be removed from code: `AccountService::PROVIDERS` still lists `'email'`, so `addIdentity()` still accepts it, and the cabinet still names, labels and counts email identities as a login method (app/Views/site/account_cabinet.php:28-29, :91 `'email' => $mail ?? $subject`, `$canUnlink = count($identities) > 1`), which lets an account with a leftover email row unlink its last working login - repro: `git grep -n "'email'" 7659b0e4 -- app/Services/Web/AccountService.php app/Views/site/account_cabinet.php`

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
- tests/database/AccountLinkPageTest.php
- app/Controllers/Telegram/Commands/Actions/WebLinkCodeAction.php
- app/Views/site/account_link.php
- app/Views/site/play_stub.php
- app/Services/Onboarding/GuideCatalog.php
- app/Database/Migrations/2026-12-15-100000_WebLinkTipWithoutEmail.php
- app/Controllers/AccountOAuth.php
- app/Services/Web/OAuthProviderFactory.php
- tests/unit/Views/AccountTextsWithoutEmailTest.php

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
