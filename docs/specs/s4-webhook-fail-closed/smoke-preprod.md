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

## Прод (v0.51.706, run 40392209303 success, релиз 22:04)

Гейт деплоя `ok (production)` у бота и у сайта. POST без заголовка — 403; сайт 200; ERROR/CRITICAL после деплоя — 0, отказов «не задан»/«mismatch» — 0. `getWebhookInfo` 22:26: pending 0, последняя ошибка доставки — 2026-10-25 (таймаут, до релиза). Действий игроков 22:04–22:25 в `player_action_log` не было (тихий вечер) — первый живой апдейт после релиза не наблюдался.
