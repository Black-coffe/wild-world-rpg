#!/bin/bash
set -euo pipefail

DOMAIN="${1:?usage: post-deploy.sh <domain> <ts>}"
TS="${2:?usage: post-deploy.sh <domain> <ts>}"

BASE="$HOME"
RELEASES="$BASE/releases"
SHARED="$BASE/shared"
WEBROOT="$BASE/htdocs/$DOMAIN"
NEW_RELEASE="$RELEASES/$TS"

if [[ ! -d "$NEW_RELEASE" ]]; then
    echo "ERROR: release dir $NEW_RELEASE missing — rsync did not run?" >&2
    exit 1
fi

if [[ ! -L "$WEBROOT" ]]; then
    echo "ERROR: $WEBROOT is not a symlink — run zero-release.sh first" >&2
    exit 1
fi

if [[ ! -f "$SHARED/.env" ]]; then
    echo "ERROR: $SHARED/.env missing — create it manually before first deploy" >&2
    exit 1
fi

cd "$NEW_RELEASE"

echo ">>> [post-deploy] composer install"
composer install --no-dev --optimize-autoloader --no-progress --no-interaction

echo ">>> [post-deploy] symlink .env and writable/"
ln -sfn "$SHARED/.env" .env
rm -rf writable
ln -sfn "$SHARED/writable" writable

# ADR-052 — featured-картинки сайта (uploads/site) держим в shared, чтобы они
# переживали новые релизы (контент в БД shared, картинки gitignored → не в rsync).
echo ">>> [post-deploy] symlink public/uploads/site -> shared"
mkdir -p "$SHARED/uploads/site"
mkdir -p public/uploads
rm -rf public/uploads/site
ln -sfn "$SHARED/uploads/site" public/uploads/site

echo ">>> [post-deploy] migrate"
php spark migrate --all -n

# ADR-052 — контент публичного сайта: импорт из WP при первом деплое (если site_posts
# пуст) + ревизия под канон (идемпотентно). Non-fatal: сбой контента не валит деплой.
echo ">>> [post-deploy] website content (import-if-empty + canon)"
php spark site:import-wp --only-if-empty || echo "WARN: site:import-wp failed, continuing"
php spark site:apply-canon || echo "WARN: site:apply-canon failed, continuing"

echo ">>> [post-deploy] atomic switch"
ln -sfn "$NEW_RELEASE" "$WEBROOT.tmp"
mv -Tf "$WEBROOT.tmp" "$WEBROOT"

echo ">>> [post-deploy] cleanup old releases (keep 5 newest)"
cd "$RELEASES"
ls -1dt */ 2>/dev/null | sed 's:/$::' | tail -n +6 | xargs -r rm -rf

echo ">>> [post-deploy] done: $WEBROOT -> $NEW_RELEASE"
