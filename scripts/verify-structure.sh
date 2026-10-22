#!/usr/bin/env bash
# FND-002 · T-01, T-09, T-10, T-12: estrutura do monorepo, nomes provisórios, agentes e segredos.
set -u
cd "$(dirname "$0")/.."
fail=0
check() { if ! eval "$2"; then echo "FALHA [$1] $3"; fail=1; else echo "ok    [$1] $3"; fi; }

# CA-01
for d in apps/api apps/web packages/api-client packages/ui-tokens docs .claude; do
  check CA-01 "[ -d $d ]" "existe $d"
done
check CA-01 "[ ! -e apps/mobile ]" "apps/mobile não existe"

# CA-08: o nome provisório não aparece em código-fonte
check CA-08 "! grep -rIl -i -E 'sistema[-_ ]?log' apps packages scripts compose.yaml docker \
  --exclude-dir=node_modules --exclude-dir=vendor --exclude-dir=.next --exclude-dir=storage \
  --exclude-dir=bootstrap --exclude=verify-structure.sh --exclude=pnpm-lock.yaml --exclude=composer.lock 2>/dev/null | grep -q ." \
  "nome provisório fora de código"

# CA-09
check CA-09 "[ \$(ls .claude/agents | wc -l) -eq 10 ]" "10 agentes em .claude/agents"
check CA-09 "[ \$(ls .claude/skills | wc -l) -eq 11 ]" "11 skills em .claude/skills"
check CA-09 "[ -f .vscode/extensions.json ] && [ ! -e setup ]" ".vscode existe e setup/ não"

# CA-11
check CA-11 "[ -f .env.example ]" ".env.example versionado"
check CA-11 "[ -z \"\$(git ls-files '*.env' '.env' 'apps/*/.env' 2>/dev/null)\" ]" "nenhum .env rastreado"
check CA-11 "grep -q '^\.env$' .gitignore" ".env no .gitignore"

exit $fail
