# Changelog

Formato: [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/). Datas em ISO 8601.

## [Não lançado]

### Adicionado

- FIN-002: despesas e categorias (API e tela): gastos por data e categoria, categoria com despesas que não se apaga (também no banco), categorias sugeridas, nomes únicos sem acento, listagem por período com total e total por categoria do filtro inteiro, só para dona e gerente, e histórico com antes e depois.
- FIN-001: pagamentos do atendimento (API e tela): sinal, parcelas e valor final com forma e data, total recebido que nunca passa do valor combinado (limite também no banco, mesmo em corrida), estorno em vez de apagar, situação do pagamento no agendamento, comissão do profissional congelada ao agendar, cancelamento e exclusão barrados com dinheiro recebido, idempotência no registro e histórico com antes e depois.
- AGD-003: agenda (API e tela): agendamentos com cliente, profissional e serviço, conflito de horário barrado no banco (inclusive em corrida) com intervalos do serviço, encaixe confirmado fora do expediente e folgas, situações (pendente, agendado, finalizado, cancelado, faltou) com regras de edição, horários livres, avaliação do cliente, papéis (recepção sem valores, profissional só o próprio), exclusão barrada para cliente, serviço e profissional com agendamentos, e contagem e média de avaliações no cliente.
- AGD-002: profissionais (cor, comissão, vínculo com a equipe, ativar e desativar), expediente semanal com várias faixas por dia (sem sobreposição, garantido no banco) e folgas de uma pessoa ou do negócio todo (API e tela).
- AGD-001: catálogo de serviços e categorias (API e tela): duração, preço ou "sob orçamento", intervalos e sinal, arquivar, categoria só apagada sem serviços, nomes únicos sem acento e serviços sugeridos por nicho.
- CLI-001: clientes (API e tela): cadastro com telefone E.164 único por negócio, e-mail e Instagram únicos, busca sem acento, criação rápida que devolve o cliente existente, exclusão (dona e gerente), histórico do cliente e papéis por ação.
- FND-008: auditoria do assinante: registro de criação, edição e exclusão com antes e depois (`Audited`), somente de acréscimo e isolado por negócio, `GET /audit-logs` (dona e gerente) e tela de histórico.
- PLT-013: troca de plano pela dona (API e painel): upgrade vale na hora com cobrança proporcional, downgrade agendado para o fim do período, bloqueio quando o uso passa do limite do plano novo, simulação antes de confirmar e troca agendada que pode ser desfeita.
- PLT-001b: painel web da equipe da plataforma em `/admin` (entrar com 2FA, resumo, assinantes com busca e detalhe, suspender, reativar, estender teste, exceções de funcionalidade e acesso de suporte, planos, cupons e leads), com perfis super admin (escreve) e suporte (consulta).
- PLT-015: painel web do assinante (entrar com 2FA, recuperar e redefinir senha, aceitar convite, início, assinatura com cartão, cancelamento e faturas, equipe e conta) no endereço do negócio, com entrada do acesso de suporte e faixa de suporte; links dos e-mails passam a usar o endereço do painel (`APP_WEB_HOST`).
- PLT-014: site público (landing com os planos da API, contato que vira lead, cadastro em 4 passos com cupom e cartão de teste, confirmação, busca do painel e termos provisórios).
- FND-006: design system (tokens com teste de contraste, componentes acessíveis, layouts do site e dos painéis), ponte do web com a API (cookie HttpOnly, proteção contra CSRF) e roteamento por endereço (site, painel da equipe e painel do assinante). Guia de estilo em `/guia-de-estilo` e sugestão de paleta e logotipo em `docs/16-design-system.md`.
- PLT-011: captura de leads da landing (API) com UTM, consentimento LGPD, anti-robô (reCAPTCHA com verificador de teste local), campo isca, deduplicação em 24 horas, limite por hora, tela de gestão da equipe (lista, status, notas) e retenção de 12 meses.
- PLT-009: gestão de assinantes pela equipe (API): lista e detalhe, exceções de funcionalidade por negócio, suspender e reativar na mão, estender o teste e acesso de suporte (30 minutos, com motivo, auditoria e aviso à dona).
- PLT-010: e-mails transacionais (boas-vindas, fim do teste chegando, pagamento aprovado, suspensão, regularização, cancelamento, troca de senha e 2FA desligado), um por acontecimento, pela fila, com registro de envio sem o endereço.
- PLT-012: cupons criados pelo super admin (percentual, validade, limite de usos), aplicados no cadastro com preço congelado e conta de cortesia (100%) sem cartão; consulta pública do preço com o cupom.
- PLT-008: equipe do negócio (API): convite por e-mail com link de 7 dias, aceite com senha própria, troca de papel, desativação com queda imediata dos acessos, reativação e limite `usuarios.max` do plano.
- PLT-007: gestão da assinatura pela dona (API): trocar o meio de pagamento, regularizar assinatura suspensa com cobrança na hora, pedir e desfazer o cancelamento (vale no fim do teste ou do período pago, sem cobrança) e listar faturas.
- PLT-006: login do assinante (API) com e-mail e senha, token opaco por negócio (RLS), sair e sair de todos, recuperação e troca de senha, 2FA (TOTP) opcional, bloqueio por falhas e papel de banco `app_auth`.
- PLT-004: cobrança do fim do teste e das renovações com o meio de pagamento de teste (`billing:run`, a cada 5 minutos), faturas com RLS, suspensão imediata em caso de falha e simulação de tempo com `--as-of`.
- PLT-005: bloqueio por status da assinatura (somente leitura quando suspensa ou cancelada, código `subscription_suspended`/`subscription_canceled`) e `GET /api/v1/subscription`.
- PLT-003: cadastro do assinante com teste de 7 dias (API): negócio, dona, vínculo com RLS, meio de pagamento de teste, assinatura em teste com preço congelado, idempotência e papel de banco de plataforma.
- PLT-002: catálogo de planos (Essencial, Profissional e Estúdio) e de funcionalidades, regras por plano (liberada, limitada, bloqueada visível ou oculta), preço mensal e anual com desconto, listagem pública de planos ativos e serviço de entitlements.
- PLT-001: login da equipe da plataforma (super admin e suporte) com senha e TOTP obrigatório, tokens Sanctum de desafio e de acesso, bloqueio progressivo, auditoria e comando `platform:create-admin`.
- FND-005: base multi-tenant (resolução por subdomínio, RLS, papel de runtime sem BYPASSRLS, suíte de isolamento).
- FND-003: CI no GitHub Actions (api, web, contract, structure, audit).
- FND-002: esqueleto do monorepo (`apps/api` Laravel, `apps/web` Next.js, `packages/api-client`, `packages/ui-tokens`), ambiente local em Docker Compose, `GET /api/v1/health`, scripts de verificação, agentes e skills em `.claude/`.
- FND-001: ADRs 0001 (stack) e 0004 (hospedagem em Docker) aceitos; 0003 (gateway) proposto.
