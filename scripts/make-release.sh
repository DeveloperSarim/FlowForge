#!/usr/bin/env bash
# Builds dist/flowforge-<date>.zip for shared hosting: production vendor/, built assets,
# and no secrets or local state (.env, install flag, logs, sessions, caches, test credentials).
# Upload the zip, extract it (into public_html or a folder above it) and open /install.
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
stage="$(mktemp -d)"
out="$root/dist/flowforge-$(date +%Y%m%d-%H%M).zip"
trap 'rm -rf "$stage"' EXIT

echo "→ Building frontend assets"
npm --prefix "$root" run build --silent

echo "→ Copying source"
rsync -a "$root/" "$stage/" \
    --exclude .git --exclude node_modules --exclude vendor --exclude dist --exclude tests \
    --include .env.example --exclude '.env*' --exclude .phpunit.result.cache --exclude .DS_Store \
    --exclude /public/hot --exclude /public/storage --exclude /storage/.installed

# Keep the storage/ skeleton (.gitignore placeholders) but drop everything this machine wrote.
find "$stage/storage" -type f ! -name .gitignore -delete
find "$stage/bootstrap/cache" -mindepth 1 ! -name .gitignore -exec rm -rf {} +

echo "→ Installing production dependencies"
composer install --working-dir="$stage" --no-dev --optimize-autoloader --no-interaction --quiet
# package:discover booted the app, and first boot writes a .env with a fresh APP_KEY: every install must make its own.
rm -f "$stage/.env"

min_php=$(grep -o 'PHP_VERSION_ID >= [0-9]*' "$stage/vendor/composer/platform_check.php" | head -1 | grep -o '[0-9]*$' || true)
mkdir -p "$root/dist"
(cd "$stage" && zip -qr "$out" . -x '*.DS_Store')

cp "$root/scripts/extract-flowforge.php" "$root/dist/extract-flowforge.php"

echo "✓ $out ($(du -h "$out" | cut -f1)), needs PHP ${min_php:-80200}+"
echo "  + dist/extract-flowforge.php: upload it next to the zip if the File Manager can't extract it"
