#!/usr/bin/env bash
# Нажать inline-кнопку бота на preprod-testbot: синтетический callback_query через настоящий
# роутер вебхука (Tier-3 без браузера, memory `reference_autonomous_webhook_tier3_smoke`).
#
#   Использование:  bash scripts/smoke/testbot-tap.sh <callback_data> [telegram_id]
#                   bash scripts/smoke/testbot-tap.sh confirm_upgrade_building_2_l6_b168
#
# telegram_id по умолчанию — тест-чар 491 (tg25, 6995661239). Печатает http-код и строку
# firehose `player_action_log` этого апдейта: `ok` = роут прошёл; исход проверяй дельтой БД
# (scripts/smoke/testbot-db.sh) — `ok` доказывает маршрут, не результат.
#
# Грабли, которые скрипт снимает сам:
#  - секрет вебхука читается на сервере тем же awk, что в памяти (tr по `\x27` резал цифры → 403);
#  - payload пишется файлом и уходит --data-binary (JSON в shell-переменной ломался → «Invalid input JSON»);
#  - message_id — число-заглушка, не characters.last_message_id (бывает NULL → невалидный JSON);
#  - update_id уникален (время в мс), иначе дедуп update_id глотает повтор.
set -euo pipefail

DATA="${1:?usage: testbot-tap.sh <callback_data> [telegram_id]}"
TG="${2:-6995661239}"
case "$DATA" in *[\"\\]*) echo "testbot-tap: кавычки и \\ в callback_data не поддерживаются" >&2; exit 2 ;; esac
case "$TG" in *[!0-9]*) echo "testbot-tap: telegram_id — только цифры" >&2; exit 2 ;; esac

HOST="wildworld-testbot@testbot.wildworld.fun"
KEY="${WW_DEPLOY_KEY:-$HOME/.ssh/wildworld_deploy}"

ssh -i "$KEY" -o BatchMode=yes "$HOST" "DATA='$DATA' TG='$TG' bash -s" <<'REMOTE'
set -e
[ "$(whoami)" = wildworld-testbot ] || { echo "testbot-tap: не testbot ($(whoami)) — стоп" >&2; exit 2; }
env=~/shared/.env
SECRET=$(awk -F= '/telegram\.WEBHOOK_SECRET/{gsub(/[ "\047\r]/,"",$2); print $2; exit}' "$env")
N=$(date +%s%3N)
P=$(mktemp)
cat > "$P" <<JSON
{"update_id":$N,"callback_query":{"id":"$N","from":{"id":$TG,"is_bot":false,"first_name":"smoke"},"message":{"message_id":999999,"date":1700000000,"chat":{"id":$TG,"type":"private"},"from":{"id":$TG,"is_bot":false,"first_name":"smoke"},"text":"x"},"chat_instance":"1","data":"$DATA"}}
JSON
curl -s -o /dev/null -w 'http=%{http_code}\n' -X POST "https://testbot.wildworld.fun/telegram/webhook" \
  -H "Content-Type: application/json" -H "X-Telegram-Bot-API-Secret-Token: $SECRET" --data-binary @"$P"
rm -f "$P"
sleep 1
val() { awk -F= -v k="$1" '$1 ~ "^" k { gsub(/[ "\047\r]/, "", $2); print $2; exit }' "$env"; }
mysql -u"$(val database.default.username)" -p"$(val database.default.password)" "$(val database.default.database)" -N \
  -e "SELECT id, action_name, status, IFNULL(error_text,'') FROM player_action_log WHERE telegram_user_id = $TG ORDER BY id DESC LIMIT 1" 2>&1 | grep -v "Using a password"
REMOTE
