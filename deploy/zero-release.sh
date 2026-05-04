#!/bin/bash
# One-time setup on a fresh CloudPanel site.
# Run as the site user (wildworld-bot or wildworld-bot-test) over SSH.
# Converts htdocs/<domain> from a regular dir into the symlink-based release scheme.
#
# Usage on the server:
#   bash zero-release.sh testbot.wildworld.fun
#   bash zero-release.sh bot.wildworld.fun

set -euo pipefail

DOMAIN="${1:?usage: zero-release.sh <domain>}"

BASE="$HOME"
RELEASES="$BASE/releases"
SHARED="$BASE/shared"
WEBROOT="$BASE/htdocs/$DOMAIN"
INITIAL="$RELEASES/0000-initial"

if [[ -L "$WEBROOT" ]]; then
    echo "ABORT: $WEBROOT is already a symlink — zero-release already done" >&2
    exit 1
fi

if [[ ! -d "$WEBROOT" ]]; then
    echo "ABORT: $WEBROOT does not exist" >&2
    exit 1
fi

echo ">>> [zero-release] preparing $DOMAIN"

mkdir -p "$RELEASES" "$SHARED/writable"

echo ">>> [zero-release] create writable subdirs (CI4 layout)"
mkdir -p "$SHARED/writable/cache" \
         "$SHARED/writable/logs" \
         "$SHARED/writable/session" \
         "$SHARED/writable/uploads" \
         "$SHARED/writable/debugbar"

echo ">>> [zero-release] move $WEBROOT -> $INITIAL"
mv "$WEBROOT" "$INITIAL"

if [[ -f "$INITIAL/.env" ]]; then
    echo ">>> [zero-release] migrate .env to shared/"
    cp "$INITIAL/.env" "$SHARED/.env"
    chmod 600 "$SHARED/.env"
    rm "$INITIAL/.env"
else
    echo "WARNING: no .env found in $INITIAL — creating empty $SHARED/.env"
    touch "$SHARED/.env"
    chmod 600 "$SHARED/.env"
fi
ln -sfn "$SHARED/.env" "$INITIAL/.env"

if [[ -d "$INITIAL/writable" ]]; then
    echo ">>> [zero-release] merge writable/ into shared/"
    cp -a "$INITIAL/writable/." "$SHARED/writable/" 2>/dev/null || true
    rm -rf "$INITIAL/writable"
fi
ln -sfn "$SHARED/writable" "$INITIAL/writable"

echo ">>> [zero-release] atomic switch"
ln -sfn "$INITIAL" "$WEBROOT.tmp"
mv -Tf "$WEBROOT.tmp" "$WEBROOT"

echo ">>> [zero-release] done"
echo ">>> $WEBROOT -> $INITIAL"
echo ">>> shared layout:"
ls -la "$SHARED/"
echo ">>>"
echo ">>> next steps:"
echo ">>>   1. Verify the site still loads in browser"
echo ">>>   2. cd $INITIAL && php spark migrate:status   (sanity check)"
echo ">>>   3. Trigger a deploy from GitHub Actions to create a fresh release alongside this one"
