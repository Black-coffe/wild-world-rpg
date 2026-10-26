# Smoke preprod: s4-webhook-fail-closed + s4-deploy-secret-gate

**Date:** 2026-10-26 · **Preprod:** run 40391210013 success · parity ✅

| Проверка | Ожидание | Факт |
|---|---|---|
| Деплой: `post-deploy.sh` → `check-webhook-secret.sh` | `ok (production)` до composer | `>>> [post-deploy] webhook secret gate: ok (production)` |
| POST `/telegram/webhook` без заголовка секрета | 403 | 403 |
| POST с чужим `X-Telegram-Bot-API-Secret-Token` | 403 | 403 |
| `testbot-tap.sh character` (с секретом) | 200, firehose `ok` | 200, `956 character ok` |
| Гейт по `~/shared/.env` прода (stdin, чтение) | exit 0 | exit 0 |

Ветка «пустой секрет в production → 403 + CRITICAL» на живом сервере не воспроизводилась (секрет не снимали); она
закрыта тестами `BotControllerWebhookSecretTest` и `CheckWebhookSecretGateTest`.
