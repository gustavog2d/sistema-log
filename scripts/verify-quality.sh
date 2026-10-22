#!/usr/bin/env bash
# FND-002 · T-06, T-07, T-10: lint, análise estática e testes passam, sem avisos. Exige o ambiente no ar.
set -u
cd "$(dirname "$0")/.."
fail=0
run() { # id descrição serviço comando
  if out=$(docker compose exec -T "$3" sh -c "$4" 2>&1); then echo "ok    [$1] $2"; else echo "FALHA [$1] $2"; echo "$out" | tail -15; fail=1; fi
}
run CA-05 "Pint (estilo da API)"        api "vendor/bin/pint --test"
run CA-05 "Larastan nível máximo"       api "vendor/bin/phpstan analyse --memory-limit=1G --no-progress"
run CA-06 "Pest com PostgreSQL real"   api "vendor/bin/pest && php -r 'exit(getenv(\"DB_CONNECTION\") === \"sqlite\" ? 1 : 0);'"
run CA-05 "ESLint sem avisos"           web "cd /workspace && pnpm --filter web exec eslint . --max-warnings 0"
run CA-05 "TypeScript strict"           web "cd /workspace && pnpm typecheck"
run CA-05 "Prettier"                    web "cd /workspace && pnpm exec prettier --check ."
run CA-06 "Vitest"                      web "cd /workspace && pnpm test"
run CA-07 "Teste único (Pest)"          api "vendor/bin/pest --filter='reports api, database and redis as healthy'"
run CA-07 "Teste único (Vitest)"        web "cd /workspace && pnpm --filter web exec vitest run src/components/home.test.tsx -t 'renders the title'"
run RN-04 "Cliente gerado sem diferença" web "cd /workspace && f=packages/api-client/src/generated/api.ts && before=\$(sha256sum \$f) && pnpm api-client:generate >/dev/null && pnpm exec prettier --write \$f >/dev/null && [ \"\$before\" = \"\$(sha256sum \$f)\" ]"
exit $fail
