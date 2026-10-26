#!/bin/bash
# S4 — гейт деплоя: вебхук закрыт без секрета в production (BotController::webhookSecretVerdict).
# Если в env-файле CI_ENVIRONMENT = production, а telegram.WEBHOOK_SECRET пуст, закомментирован
# или отсутствует — exit 1: релиз не переключается, старый продолжает принимать апдейты.
# Значение секрета не печатается никогда.
set -euo pipefail

ENV_FILE="${1:?usage: check-webhook-secret.sh <env-file>}"

# Последнее незакомментированное значение ключа, без пробелов, кавычек и \r.
value_of() {
    awk -F= -v key="$1" '
        /^[[:space:]]*#/ { next }
        {
            k = $1; gsub(/[[:space:]]/, "", k)
            if (k != key) next
            v = substr($0, index($0, "=") + 1)
            gsub(/[[:space:]"\047\r]/, "", v)
            last = v
        }
        END { print last }
    ' "$ENV_FILE"
}

ENVIRONMENT="$(value_of CI_ENVIRONMENT)"
SECRET="$(value_of telegram.WEBHOOK_SECRET)"

if [[ "$ENVIRONMENT" == "production" && -z "$SECRET" ]]; then
    echo "ERROR: telegram.WEBHOOK_SECRET is empty in $ENV_FILE while CI_ENVIRONMENT = production." >&2
    echo "       After S4 the webhook rejects every update without it — release NOT switched." >&2
    echo "       Restore the secret (the one registered via setWebhook secret_token) and redeploy." >&2
    exit 1
fi

echo ">>> [post-deploy] webhook secret gate: ok (${ENVIRONMENT:-unset})"
