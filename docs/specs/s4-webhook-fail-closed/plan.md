# Вебхук закрыт без секрета в production (plan)

**Tier:** 1 · **Spec slug:** `s4-webhook-fail-closed` · **Brief:** [brief.md](brief.md)
**Governed by:** F0.9 `mmorpg-vault/lore/refactor/Security-telegram.md`; ADR-181 (дедуп после секрета)
**Depends on:** —

## Goal
Решение «пустить апдейт или 403» выносится в чистый статический метод `BotController::webhookSecretVerdict(expected,
received, environment)`; при пустом секрете в `production` — отказ с CRITICAL-логом, в остальных средах — пропуск, как
сейчас. `webhook()` зовёт его первым делом. Комментарии в контроллере и `.env.example` говорят новое правило.

## Assumptions
- Отказ при пустом секрете — 403 (как при несовпадении); Telegram будет ретраить, пока секрет не вернут, — это и есть fail-closed.
- `development`/`testing` пропускают пустой секрет: иначе ломается локальная разработка без Telegram.

## Stories

**Wave 1**
- `s4-webhook-fail-closed-01` — `webhookSecretVerdict` + вызов в `webhook()` + тест + `.env.example`.

## Contracts
none

## Integration gate
`vendor/bin/phpunit --no-coverage --no-progress`

## Descoped

*(empty)*

## Plan deltas

**Approved:**
**Briefed:** via mini-brief, Andrei, 2026-10-26
**Branch:** vulyk/s4-webhook-fail-closed
**Checked:**
**Council:**
**Council:** GREEN round 1, 2026-10-26, at f8decb01, pack 7f5b1da2b237
**Shipped:**
**Shipped:** v0.51.706, 2026-10-26, at e7897146 - merged to develop, publish pending (вместе с s4-deploy-secret-gate)
