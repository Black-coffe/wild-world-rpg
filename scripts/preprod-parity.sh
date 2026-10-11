#!/usr/bin/env bash
# Preprod parity gate (docs/defects/preprod-settings-drift.md): before any preprod smoke that will
# green-light a prod tag, preprod-testbot must run the same game_settings as prod.
#
#   Usage: bash scripts/preprod-parity.sh [<out.tsv>]
#
# Reads game_settings from both databases over SSH (read-only on prod), writes the joined snapshot
# `key<TAB>preprod<TAB>prod` and runs scripts/defects-preprod-parity-check.php on it.
# Exit: 0 parity, 1 drift (the DRIFT lines say what to align on preprod), 2 cannot read an environment.
set -u

KEY="${HOME}/.ssh/wildworld_deploy"
OUT="${1:-${TMPDIR:-/tmp}/preprod-parity.tsv}"
Q="SELECT setting_key, COALESCE(value_int, value_float, value_bool, value_string, '') FROM game_settings ORDER BY setting_key"

dump() { # <user@host> <site dir>
  ssh -i "$KEY" -o BatchMode=yes "$1" "cd ~/htdocs/$2 && \
    U=\$(grep -E '^database.default.username' .env | cut -d= -f2- | tr -d ' \"'); \
    P=\$(grep -E '^database.default.password' .env | cut -d= -f2- | tr -d ' \"'); \
    D=\$(grep -E '^database.default.database' .env | cut -d= -f2- | tr -d ' \"'); \
    MYSQL_PWD=\"\$P\" mysql -N -B -u\"\$U\" \"\$D\" -e \"$Q\""
}

PRE=$(mktemp); PROD=$(mktemp)
trap 'rm -f "$PRE" "$PROD"' EXIT
dump wildworld-testbot@testbot.wildworld.fun testbot.wildworld.fun | sort > "$PRE" || { echo "preprod-parity: cannot read preprod" >&2; exit 2; }
dump wildworld-bot@185.161.208.135 bot.wildworld.fun | sort > "$PROD" || { echo "preprod-parity: cannot read prod" >&2; exit 2; }
[ -s "$PRE" ] && [ -s "$PROD" ] || { echo "preprod-parity: empty settings dump" >&2; exit 2; }

join -t $'\t' -a1 -a2 -e MISSING -o 0,1.2,2.2 "$PRE" "$PROD" > "$OUT"
php "$(dirname "$0")/defects-preprod-parity-check.php" "$OUT"
