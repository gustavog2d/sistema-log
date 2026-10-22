#!/bin/sh
# Prepara a API no primeiro uso e sobe o servidor de desenvolvimento.
set -e
cd /workspace/apps/api
[ -f .env ] || cp .env.example .env
# O servidor de desenvolvimento do Laravel lê só o .env: chaves novas do .env.example (sem valor definido
# no .env) são acrescentadas, para um .env antigo nunca ficar sem as variáveis novas.
grep -E '^[A-Z0-9_]+=' .env.example | while IFS= read -r line; do
  key=${line%%=*}
  grep -q "^${key}=" .env || echo "$line" >> .env
done
[ -d vendor ] || composer install --no-interaction
grep -q '^APP_KEY=.\+' .env || php artisan key:generate --force
php artisan migrate --database=pgsql_admin --force
php artisan db:seed --class=CatalogSeeder --force
exec php artisan serve --host=0.0.0.0 --port=8000
