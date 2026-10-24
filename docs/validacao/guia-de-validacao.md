# Guia de validação

Documento para validar o produto de ponta a ponta no computador local: o que existe, como entrar, o que testar em cada área e como registrar as observações. Nomes, domínios, e-mails e cores são **provisórios**.

## 1. Onde estamos

| Fase | Situação |
| --- | --- |
| 0 · Fundação (monorepo, CI, multi-tenant, design system) | Pronta |
| 1 · Plataforma de assinatura (planos, cadastro, cobrança de teste, painéis, e-mails, cupons, leads) | Pronta, com **meio de pagamento de teste** |
| 2 · Núcleo (clientes, serviços, profissionais, agenda, pagamentos, despesas, financeiro, auditoria) | Pronta. Falta a retenção de dados após o cancelamento (PLT-016) |
| Pagamento real (Asaas), domínio e homologação | **Por último**, dependem de você (conta Asaas sandbox; servidor e domínio) |
| Orçamentos, agenda pública, lembretes, integrações, app mobile | Fases seguintes |

Tudo está liberado em todos os planos por enquanto (limites por plano ficam para depois dos testes).

## 2. Endereços (ambiente local)

| O que | Endereço |
| --- | --- |
| Site público (landing, planos, contato, cadastro) | http://localhost:3000 |
| Painel da equipe da plataforma (super admin e suporte) | http://localhost:3000/admin |
| Painel do assinante | `http://{slug}.localhost:3000` (o `{slug}` é o endereço escolhido no cadastro, ex.: `http://tintaviva.localhost:3000`) |
| E-mails enviados pelo sistema (caixa de teste, Mailpit) | http://localhost:8025 |
| API | http://localhost:8000/api/v1 (ex.: `/api/v1/health`) |
| Guia de estilo (componentes) | http://localhost:3000/guia-de-estilo |

Navegadores modernos resolvem `*.localhost` sozinhos; não precisa mexer em arquivos do sistema. Todos os e-mails (boas-vindas, convites, recuperação de senha, aviso de teste acabando, cobrança) chegam no **Mailpit**, não em caixas reais.

## 3. Preparar o ambiente

```bash
cp .env.example .env                  # primeira vez
docker compose up -d --build --wait   # sobe API, site, worker, Postgres, Redis e Mailpit
docker compose down -v                # zera tudo (apaga o banco) e recomeça limpo
```

- Se o site mostrar erro 500 depois de trocar de versão do código: `docker compose restart web`.
- O banco de desenvolvimento começa **vazio**. Os planos (Essencial, Profissional, Estúdio) são criados sozinhos na subida.
- O que o ambiente local simula: pagamento (cartões de teste), anti-robô (modo de teste) e e-mail (Mailpit).

## 4. Acessos

**Não há contas prontas nem senhas neste documento.** Você cria as suas e escolhe as senhas.

### 4.1 Super admin da plataforma

```bash
docker compose exec api php artisan platform:create-admin seu@email.com "Seu Nome"
```

O comando pede a senha. Depois entre em http://localhost:3000/admin/entrar. O **2FA é obrigatório**: no primeiro acesso a tela mostra um QR code para ler em um aplicativo autenticador (Google Authenticator, Authy, 1Password etc.) e pede o código de 6 dígitos. Há também a função "suporte" (somente consulta e acesso de suporte).

### 4.2 Assinante (dono do negócio)

1. Abra http://localhost:3000/cadastro e percorra os 4 passos (negócio e endereço, nicho, plano e ciclo, dados da dona e pagamento).
2. No pagamento use um dos **cartões de teste** da tela: *aprovado* (cobra e segue), *recusa na cobrança* (aceita agora e falha na primeira cobrança) ou *recusado agora* (recusa no cadastro).
3. O anti-robô está em modo de teste. Um e-mail de boas-vindas chega no Mailpit.
4. Entre em `http://{slug}.localhost:3000/entrar` com o e-mail e a senha que você definiu. A dona pode ligar o **2FA** em *Minha conta*.

O teste grátis dura 7 dias e já exige o cartão cadastrado; cancelar durante o teste nunca cobra.

### 4.3 Equipe do assinante

Na tela **Equipe** (dona e gerente), convide pessoas com um papel (gerente, profissional, recepção). O link de aceite chega no Mailpit (vale 7 dias). Para validar os papéis, crie ao menos uma pessoa de cada.

| Papel | Vê no menu |
| --- | --- |
| Dona | Início, Assinatura, Agenda, Clientes, Serviços, Profissionais, Financeiro, Despesas, Equipe, Histórico, Minha conta |
| Gerente | Tudo, menos Assinatura |
| Profissional | Início, Agenda (só a própria), Clientes, Serviços, Profissionais, Minha conta |
| Recepção | Início, Agenda (de todos, sem valores), Clientes, Serviços, Profissionais, Minha conta |

Para o profissional enxergar a própria agenda, cadastre-o em **Profissionais** e ligue ao acesso dele no campo "Acesso".

### 4.4 Acesso de suporte (impersonação)

No `/admin`, abra o assinante e use **Entrar como o assinante** (pede o motivo). Vale 30 minutos, é somente leitura, fica registrado e avisa a dona por e-mail.

## 5. Roteiro de validação

Marque cada item e anote o que estranhar (seção 8).

### 5.1 Site público
- [ ] A landing mostra os 3 planos com preço mensal e anual (10% de desconto no anual).
- [ ] O formulário de contato cria um lead (veja em `/admin` → Leads).
- [ ] "Encontrar meu estúdio" leva ao login do negócio.
- [ ] Termos e privacidade (textos provisórios) abrem.

### 5.2 Cadastro e acesso do assinante
- [ ] Cadastro completo com cartão aprovado chega ao painel do negócio.
- [ ] Endereço (slug) repetido ou reservado é recusado; cupom inválido mostra sempre a mesma mensagem.
- [ ] Login, sair, sair de todos os aparelhos, recuperar e trocar senha, 2FA (ligar, entrar com código, desligar).
- [ ] Muitas senhas erradas bloqueiam por um tempo.

### 5.3 Assinatura (dona)
- [ ] *Assinatura* mostra plano, situação, próxima cobrança e faturas.
- [ ] Trocar o cartão; cartão que recusa suspende.
- [ ] Assinatura **suspensa** ou **cancelada**: o painel vira somente leitura, com faixa de aviso e caminho para regularizar.
- [ ] Cancelar (vale no fim do teste ou do período pago) e desfazer o cancelamento.
- [ ] Trocar de plano: aumento vale na hora com cobrança proporcional; redução vale na próxima renovação; mostra a simulação antes de confirmar.

### 5.4 Equipe
- [ ] Convidar, aceitar o convite, trocar o papel, desativar (a pessoa perde o acesso na hora) e reativar.
- [ ] Cada papel só vê o que a tabela da seção 4.3 diz.

### 5.5 Clientes
- [ ] Criar com telefone (DDI, ex.: +55), e-mail e Instagram; telefone repetido no mesmo negócio é recusado.
- [ ] Busca por nome, telefone, e-mail e Instagram, sem diferenciar acento.
- [ ] Criação rápida (nome e telefone) devolve o cliente que já existe.
- [ ] O cliente mostra quantos agendamentos tem e a média das avaliações; com agendamentos não pode ser excluído.
- [ ] Notas do cliente não aparecem no histórico (só "mudou").

### 5.6 Serviços
- [ ] Criar serviço com duração (múltiplo de 5 min), preço ou "sob orçamento", intervalos antes e depois e "exige sinal".
- [ ] Categorias: criar, renomear; categoria com serviços não é excluída.
- [ ] *Serviços sugeridos* pelo nicho do negócio (não duplica o que já existe).
- [ ] Serviço usado em agendamento só pode ser **arquivado**, não excluído; arquivado não aparece para novos agendamentos.

### 5.7 Profissionais, expediente e folgas
- [ ] Cadastrar com cor e comissão (0 a 100%); ligar a uma pessoa da equipe.
- [ ] Expediente por dia da semana, com mais de uma faixa por dia; "copiar para os dias úteis".
- [ ] Folgas de uma pessoa ou do negócio todo.
- [ ] Desativar tira das novas agendas e mantém o histórico; com agendamentos não pode ser excluído.

### 5.8 Agenda
- [ ] Novo agendamento: busca de cliente, profissional, serviço, data e hora; o fim vem da duração do serviço.
- [ ] **Ver horários livres** mostra os horários possíveis do dia (de 15 em 15 min) e preenche o início.
- [ ] **Conflito:** dois agendamentos do mesmo profissional nunca se sobrepõem (os intervalos antes e depois contam); encostar sem sobrepor vale; cancelado e falta liberam o horário.
- [ ] Fora do expediente ou em folga: o sistema pede confirmação de **encaixe**; conflito nunca é liberado.
- [ ] Situações: Pendente → Agendado → Finalizado; Cancelado e Faltou; reativar confere o horário de novo.
- [ ] Finalizado só permite mudar notas, valor e avaliação (1 a 5).
- [ ] Recepção não vê valores; profissional só vê e agenda no próprio perfil.
- [ ] Navegar pelas semanas e filtrar por profissional.

### 5.9 Pagamentos do atendimento (dona e gerente)
- [ ] Botão **Pagamentos** no agendamento: resumo (combinado, recebido, falta, comissão) e lista.
- [ ] Registrar sinal, parcela e final, com forma (PIX, dinheiro, cartões, outro); o total recebido **nunca passa** do valor combinado.
- [ ] Estornar (com confirmação); estornado não conta. Para corrigir um valor, estorne e registre de novo.
- [ ] Não dá para cancelar ou marcar falta com pagamento recebido (estorne antes); agendamento com pagamento não é excluído.
- [ ] A situação do pagamento aparece no agendamento: Sem valor, Em aberto, Parcial, Pago.
- [ ] **Decisão sua:** falta com sinal retido hoje exige estornar o sinal. Confirme se é isso mesmo.

### 5.10 Despesas (dona e gerente)
- [ ] Categorias: criar, renomear, "criar sugeridas"; categoria com despesas não é excluída.
- [ ] Registrar, editar e excluir despesas; navegar pelos meses; total do mês e por categoria.

### 5.11 Financeiro (dona e gerente)
- [ ] Para um mês com agendamentos, pagamentos e despesas, confira as contas:
  - **Recebida:** pagamentos recebidos no mês (por data do pagamento).
  - **Prevista:** valor dos atendimentos do mês (sem cancelados nem faltas).
  - **A receber:** prevista menos o que já foi recebido desses atendimentos (mesmo de outro mês).
  - **Líquida:** recebida menos a comissão. **Resultado:** líquida menos despesas.
- [ ] Definir, alterar e remover a **meta do mês**; o progresso pode passar de 100%.
- [ ] Recebido por forma de pagamento, balanço dos 12 meses e calendário do mês.

### 5.12 Histórico (dona e gerente)
- [ ] Toda criação, edição e exclusão aparece com quem fez, quando e antes → depois (inclusive pagamentos, despesas e meta). O histórico não pode ser editado.

### 5.13 Painel da plataforma (`/admin`)
- [ ] **Assinantes:** lista com busca e filtro, detalhe; suspender e reativar na mão; estender o teste; **exceções de funcionalidade** por negócio; acesso de suporte (somente super admin edita).
- [ ] **Planos:** catálogo de planos e funcionalidades (preço, regras por plano).
- [ ] **Cupons:** criar (percentual, validade, limite de usos); usar no cadastro; cupom de 100% assina sem cartão e gera faturas de valor zero.
- [ ] **Leads:** lista, mudar situação, notas.

### 5.14 Cobrança e e-mails (simulação de tempo)
O sistema cobra pelo comando agendado `billing:run`. Para simular o tempo:

```bash
docker compose exec api php artisan billing:run --as-of=2026-12-01      # cobra fim de teste e renovações até a data
docker compose exec api php artisan notifications:run --as-of=2026-12-01 # avisa o fim do teste chegando
```
- [ ] Rodar duas vezes **não cobra duas vezes**.
- [ ] Cartão que recusa na cobrança suspende na hora; trocar o cartão regulariza e libera na hora.
- [ ] Cada acontecimento manda um e-mail (uma vez só) no Mailpit.

## 6. Regras de negócio que valem conferir

- Conflito de horário é barrado também no banco, mesmo com dois cliques ao mesmo tempo.
- Datas ficam em UTC no banco e aparecem no fuso do negócio (hoje fixo em Brasília).
- Dinheiro em centavos; telefone em formato internacional.
- Cada negócio só enxerga os próprios dados (isolamento no banco).
- Dono e gerente fazem tudo do negócio; recepção não vê valores; profissional vê só o que é dele.
- Dados após o cancelamento (proposta, ainda não implementada): 30 dias somente leitura com exportação, 90 dias guardados, depois apagados ou anonimizados; faturas por 5 anos; pedido de exclusão (LGPD) a qualquer momento.

## 7. O que ainda não existe

- Pagamento real (Asaas), nota fiscal, domínio definitivo, ambiente de homologação e backups ensaiados.
- Retenção e exportação de dados (PLT-016).
- Orçamentos, agenda pública, lembretes por WhatsApp e e-mail ao cliente, Google Calendar, app mobile.
- Limites por plano (hoje tudo liberado).

## 8. Como registrar as observações

Use uma linha por observação:

| Área | Passo | O que aconteceu | O que esperava | Tipo (erro / ajuste / dúvida / ideia) |
| --- | --- | --- | --- | --- |
| Agenda | Reativar cancelado | … | … | … |

Se puder, anexe um print. Para cada ajuste de **regra de negócio** eu atualizo a spec (`spec.md` da funcionalidade) antes de mexer no código.

## 9. Documentação técnica (se quiser aprofundar)

- Visão geral e mapa: `README.md`; instruções para o Claude Code: `CLAUDE.md`.
- Planos e assinatura: `docs/05-planos-e-assinaturas.md`; perfis e permissões: `docs/06-perfis-e-permissoes.md`; modelo de domínio: `docs/07-modelo-de-dominio.md`; backlog: `docs/13-backlog.md`; decisões em aberto: `docs/14-decisoes-em-aberto.md`.
- Cada funcionalidade tem uma pasta em `docs/funcionalidades/<ID>/` com `spec.md` (regras e critérios), `docs.md` (como usar e como funciona), `test-plan.md` e `openapi.yaml` (contrato da API).
- Changelog: `CHANGELOG.md`. Padrão de qualidade: `docs/09-padrao-de-qualidade.md`.

## 10. Comandos úteis

```bash
docker compose exec api vendor/bin/pest                         # testes da API
docker compose exec web sh -c 'cd /workspace && pnpm test'      # testes do site e painéis
bash scripts/verify-quality.sh                                  # verificação completa
docker compose logs -f api web worker                           # acompanhar o que acontece
```
