#!/usr/bin/env bash

set -euo pipefail

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
repository_root="$(cd -- "${script_dir}/.." && pwd)"
cd -- "${repository_root}"

composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader
test -s public/build/manifest.json

APP_ENV=production \
APP_DEBUG=false \
DB_CONNECTION=sqlite \
DB_DATABASE=database/demo.sqlite \
DB_READ_ONLY=false \
SESSION_DRIVER=array \
CACHE_STORE=array \
QUEUE_CONNECTION=discard \
LOG_CHANNEL=stderr \
php artisan leadops:build-demo-database database/demo.sqlite --no-interaction

rm -f -- database/demo.sqlite-wal database/demo.sqlite-shm database/demo.sqlite-journal
chmod 0444 -- database/demo.sqlite

APP_ENV=production APP_DEBUG=false php artisan route:cache
APP_ENV=production APP_DEBUG=false php artisan view:cache
