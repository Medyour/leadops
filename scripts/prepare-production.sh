#!/usr/bin/env bash

set -euo pipefail

composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader
npm ci
npm run build
npm prune --omit=dev
rm -rf node_modules

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

chmod 0444 database/demo.sqlite

APP_ENV=production APP_DEBUG=false php artisan route:cache
APP_ENV=production APP_DEBUG=false php artisan view:cache
