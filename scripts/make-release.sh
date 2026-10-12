#!/usr/bin/env bash
# Builds the two release zips for shared hosting:
#   dist/flowforge-install-<version>.zip  fresh install: extract on the server, open /install
#   dist/flowforge-update-<version>.zip   existing install: Settings → Updates → upload
# plus dist/extract-flowforge.php for hosts whose File Manager can't extract the install zip.
# Neither zip contains secrets or local state (.env, install flag, logs, sessions, caches, test credentials).
#
# Usage: bash scripts/make-release.sh [version]   (e.g. 1.2.0; writes VERSION. Omit to reuse VERSION.)
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
if [ -n "${1:-}" ]; then
    [[ "$1" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo "Version must look like 1.2.0" >&2; exit 1; }
    echo "$1" > "$root/VERSION"
fi
version="$(tr -d '[:space:]' < "$root/VERSION")"
stage="$(mktemp -d)"
trap 'rm -rf "$stage"' EXIT
mkdir -p "$root/dist"
install_zip="$root/dist/flowforge-install-$version.zip"
update_zip="$root/dist/flowforge-update-$version.zip"
rm -f "$install_zip" "$update_zip"

echo "→ Building frontend assets"
npm --prefix "$root" run build --silent

echo "→ Copying source"
rsync -a "$root/" "$stage/" \
    --exclude .git --exclude node_modules --exclude vendor --exclude dist --exclude tests \
    --exclude /docs --exclude /marketing --exclude /.github \
    --include .env.example --exclude '.env*' --exclude .phpunit.result.cache --exclude .DS_Store \
    --exclude /public/hot --exclude /public/storage --exclude /storage/.installed

# Keep the storage/ skeleton (.gitignore placeholders) but drop everything this machine wrote.
find "$stage/storage" -type f ! -name .gitignore -delete
find "$stage/bootstrap/cache" -mindepth 1 ! -name .gitignore -exec rm -rf {} +

echo "→ Installing production dependencies"
composer install --working-dir="$stage" --no-dev --optimize-autoloader --no-interaction --quiet
# package:discover booted the app, and first boot writes a .env with a fresh APP_KEY: every install must make its own.
rm -f "$stage/.env"
find "$stage/storage" -type f ! -name .gitignore -delete

php_min="8.2.0"
echo "→ Zipping"
(cd "$stage" && zip -qr "$install_zip" . -x '*.DS_Store')

# The update package: code only. storage/ and bootstrap/cache belong to the running install.
printf '{"product":"flowforge","version":"%s","php":"%s","built_at":"%s"}\n' "$version" "$php_min" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" > "$stage/flowforge-update.json"
(cd "$stage" && zip -qr "$update_zip" . -x 'storage/*' 'bootstrap/cache/*' '.env*' '*.DS_Store')

cp "$root/scripts/extract-flowforge.php" "$root/dist/extract-flowforge.php"

echo "✓ v$version (PHP $php_min+)"
echo "  fresh install: $install_zip ($(du -h "$install_zip" | cut -f1))"
echo "  update:        $update_zip ($(du -h "$update_zip" | cut -f1))"
echo "  + dist/extract-flowforge.php: upload it next to the install zip if the File Manager can't extract it"
