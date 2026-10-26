# Гейт секрета вебхука в деплое (plan)

**Tier:** 1 · **Spec slug:** `s4-deploy-secret-gate` · **Brief:** [brief.md](brief.md)
**Governed by:** S4 `docs/specs/s4-webhook-fail-closed/`; ADR-052 (две площадки)
**Depends on:** `s4-webhook-fail-closed` (merge e7897146)

## Goal
`deploy/check-webhook-secret.sh <env>` читает `CI_ENVIRONMENT` и `telegram.WEBHOOK_SECRET` (кавычки, пробелы, `#`-комментарии)
и при production без секрета выходит 1. `post-deploy.sh` зовёт его сразу после проверки наличия `.env`. Докблок `webhook()`
возвращается к методу.

## Assumptions
- Гейт в общем `post-deploy.sh` касается и сайта: у него тот же `shared/.env`, секрет там задан (сверено).

## Stories

**Wave 1**
- `s4-deploy-secret-gate-01` — скрипт-гейт + вызов в `post-deploy.sh` + тест + докблок.

## Contracts
none

## Integration gate
`vendor/bin/phpunit --no-coverage --no-progress`

## Descoped

*(empty)*

## Plan deltas

**Approved:**
**Briefed:** via mini-brief, Andrei, 2026-10-26
**Branch:** vulyk/s4-deploy-secret-gate
**Checked:**
**Council:**
**Council:** GREEN round 1, 2026-10-26, at c26abcb6, pack d1d30c571ba1
**Shipped:**
**Shipped:** v0.51.706, 2026-10-26, at 544c4ec5 - merged to develop, publish pending
