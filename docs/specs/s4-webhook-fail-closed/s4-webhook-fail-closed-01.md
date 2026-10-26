---
story: s4-webhook-fail-closed-01
spec: s4-webhook-fail-closed
status: todo
returned:
tier: 1
worker: worker-code
wave: 1
blocked_by: []
---

# Вебхук: пустой секрет в production — отказ, а не пропуск

## Goal
`BotController::webhookSecretVerdict(string $expected, string $received, string $environment): bool` — true, если апдейт
пускаем. Пустой `$expected`: true вне `production`, false в `production`. Непустой: `hash_equals`. `webhook()` при false
отвечает 403; при пустом секрете в production пишет CRITICAL (без значения секрета), при несовпадении — прежний warning.

## Files
- app/Controllers/Telegram/BotController.php
- tests/unit/Controllers/Telegram/BotControllerWebhookSecretTest.php
- .env.example

## Non-goals
- Не трогать дедуп ADR-181, community-гейт и остальной `webhook()`.
- Не менять `scripts/smoke/testbot-tap.sh` и `.env` окружений.

## Map slice
`memory/map/` — срез бота (`BotController`), `mmorpg-vault/tech-writing/controllers/BotController.md`.

## Acceptance criteria
- [ ] production + пустой секрет → false; production + секрет + совпадение → true; + несовпадение/пустой заголовок → false.
- [ ] development и testing + пустой секрет → true.
- [ ] `webhook()` использует метод до разбора тела; лог не содержит секрета.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Controllers/Telegram/BotControllerWebhookSecretTest.php`

## Implementation notes

## Findings
