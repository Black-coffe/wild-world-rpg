#!/usr/bin/env bash
# SQL на preprod-testbot (БД testbotwildworld) — для Tier-3 смоука: стенд, дельты, откат.
#
#   Использование:  bash scripts/smoke/testbot-db.sh <<'SQL'
#                   SELECT level, gold FROM characters WHERE id = 491;
#                   SQL
#
# SQL идёт через stdin, а не аргументом: так кавычки и `$` не проходят через два слоя shell
# (локальный bash → ssh → удалённый bash). Креды читаются на сервере из ~/shared/.env и в
# переписку не попадают.
#
# Хост зашит: только wildworld-testbot@testbot.wildworld.fun. Прод (`wildworld-bot@…`) этим
# скриптом недостижим намеренно — инцидент 2026-06-05 (смоук ADR-099 прошёл по прод-БД),
# memory `reference_testbot_ssh_access`.
set -euo pipefail

HOST="wildworld-testbot@testbot.wildworld.fun"
KEY="${WW_DEPLOY_KEY:-$HOME/.ssh/wildworld_deploy}"

ssh -i "$KEY" -o BatchMode=yes "$HOST" '
  set -e
  [ "$(whoami)" = wildworld-testbot ] || { echo "testbot-db: не testbot ($(whoami)) — стоп" >&2; exit 2; }
  env=~/shared/.env
  val() { awk -F= -v k="$1" "\$1 ~ \"^\" k { gsub(/[ \"\047\r]/, \"\", \$2); print \$2; exit }" "$env"; }
  mysql -u"$(val database.default.username)" -p"$(val database.default.password)" "$(val database.default.database)" 2>&1 | grep -v "Using a password"
'
