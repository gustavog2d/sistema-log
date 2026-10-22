#!/bin/sh
# Prepara a API no primeiro uso e sobe o servidor de desenvolvimento.
set -e
cd /workspace/apps/api
[ -f .env ] || cp .env.example .env
[ -d vendor ] || composer install --no-interaction
grep -q '^APP_KEY=.\+' .env || php artisan key:generate --force
php artisan migrate --database=pgsql_admin --force
exec php artisan serve --host=0.0.0.0 --port=8000
