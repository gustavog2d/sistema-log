#!/usr/bin/env bash
# FND-002 · T-02, T-03: o ambiente sobe saudável e o health responde.
set -u
cd "$(dirname "$0")/.."
fail=0
health_file=$(mktemp)
ok()   { echo "ok    [$1] $2"; }
bad()  { echo "FALHA [$1] $2"; fail=1; }

[ -f compose.yaml ] || { bad CA-02 "compose.yaml ausente"; exit 1; }
docker compose up -d --build --wait --wait-timeout 240 || { bad CA-02 "serviços não ficaram saudáveis"; exit 1; }

for s in api worker web postgres redis mailpit; do
  if docker compose ps --status running --services | grep -qx "$s"; then ok CA-02 "$s rodando"; else bad CA-02 "$s não está rodando"; fi
done

api_port=$(docker compose port api 8000 | sed 's/.*://')
web_port=$(docker compose port web 3000 | sed 's/.*://')
code=$(curl -s -o "$health_file" -w '%{http_code}' "http://localhost:${api_port}/api/v1/health")
if [ "$code" = 200 ] && grep -q '"database"' "$health_file" && grep -q '"redis"' "$health_file"; then ok CA-03 "GET /api/v1/health 200"; else bad CA-03 "health devolveu $code"; fi
if curl -s "http://localhost:${web_port}/" | grep -q 'data-testid="home-title"'; then ok CA-04 "web renderiza página inicial"; else bad CA-04 "página inicial não renderizou"; fi

exit $fail
