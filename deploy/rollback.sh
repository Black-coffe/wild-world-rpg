#!/bin/bash
set -euo pipefail

DOMAIN="${1:?usage: rollback.sh <domain>}"

BASE="$HOME"
RELEASES="$BASE/releases"
WEBROOT="$BASE/htdocs/$DOMAIN"

if [[ ! -L "$WEBROOT" ]]; then
    echo "ERROR: $WEBROOT is not a symlink" >&2
    exit 1
fi

CURRENT_PATH="$(readlink "$WEBROOT")"
CURRENT_NAME="$(basename "$CURRENT_PATH")"

cd "$RELEASES"
PREV="$(ls -1dt */ 2>/dev/null | sed 's:/$::' | grep -v "^${CURRENT_NAME}$" | head -1)"

if [[ -z "$PREV" ]]; then
    echo "ERROR: no previous release in $RELEASES (only $CURRENT_NAME present)" >&2
    exit 1
fi

echo ">>> [rollback] $CURRENT_PATH -> $RELEASES/$PREV"
ln -sfn "$RELEASES/$PREV" "$WEBROOT.tmp"
mv -Tf "$WEBROOT.tmp" "$WEBROOT"

echo ">>> [rollback] done. Webroot now points to $RELEASES/$PREV"
echo ">>> WARNING: migrations were NOT reverted. If the failed release added schema,"
echo ">>>          previous code may break. Run 'cd $RELEASES/$PREV && php spark migrate:rollback' if needed."
