---
story: s4-deploy-secret-gate-01
spec: s4-deploy-secret-gate
status: done
returned: DONE
tier: 1
worker: worker-code
wave: 1
blocked_by: []
---

# Деплой не переключает релиз на production без секрета вебхука

## Goal
`deploy/check-webhook-secret.sh <env-file>`: production + пустой/закомментированный/отсутствующий `telegram.WEBHOOK_SECRET`
→ сообщение в stderr и exit 1; иначе exit 0. `post-deploy.sh` вызывает его до `composer install`. Докблок `webhook()` на месте.

## Files
- deploy/check-webhook-secret.sh
- deploy/post-deploy.sh
- tests/unit/CheckWebhookSecretGateTest.php
- app/Controllers/Telegram/BotController.php

## Non-goals
- Не трогать `.github/workflows/deploy.yml`, `rollback.sh`, `.env` серверов.
- Не менять логику `webhookSecretVerdict()`.

## Map slice
—

## Acceptance criteria
- [ ] production + `telegram.WEBHOOK_SECRET = ''` → 1; `# telegram.WEBHOOK_SECRET = 'x'` → 1; строки нет → 1.
- [ ] production + секрет → 0; development + пусто → 0; вывод не содержит значения секрета.
- [ ] `post-deploy.sh` зовёт гейт до `composer install` и до переключения симлинка.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/CheckWebhookSecretGateTest.php`

## Implementation notes
- `deploy/check-webhook-secret.sh`: awk берёт последнее незакомментированное значение ключа (пробелы, кавычки, `` срезаны);
  production + пусто → stderr без значения, exit 1. `post-deploy.sh` зовёт его сразу после проверки `.env` — до composer и
  переключения симлинка; общий для бота и сайта.
- Тест `tests/unit/CheckWebhookSecretGateTest.php` гоняет скрипт настоящим bash на фикстурах (пусто, закомментировано, нет
  строки + CRLF, задан, development) + порядок в `post-deploy.sh`; без вызова в post-deploy краснеет.
- Гейт прогнан по `~/shared/.env` прода и preprod (stdin, только чтение): exit 0 на обоих.
- Докблок `webhook()` вернулся к методу (минор ревью S4).

## Findings
