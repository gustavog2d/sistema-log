# 14 · Decisões em aberto

Estas decisões precisam de resposta na Fase 0; cada uma vira um ADR em [arquitetura/adr/](arquitetura/adr/). A recomendação deste plano vem entre parênteses.

- [x] Stack: Laravel na API + TypeScript nos clientes (decidido em 2026-10-22). → ADR 0001
- [x] Banco compartilhado com RLS em vez de banco por tenant (aceito em 2026-10-22). → ADR 0002
- [ ] Escolher o gateway de pagamento após comparar Pix Automático, cartão recorrente, boleto, taxas, nota fiscal e webhooks. → ADR 0003
- [x] Planos de lançamento: Essencial, Profissional e Estúdio; teste de 7 dias; ciclo mensal ou anual com desconto no anual; cancelamento em até 7 dias sem custo, contados da solicitação da assinatura (= período de teste); cupom de cortesia 100% ou proporcional (decidido em 2026-10-22).
- [x] Preços iniciais R$ 15, R$ 50 e R$ 250 (mensal), 10% de desconto no anual; teste de 7 dias com pagamento já cadastrado e cobrança no 7º dia; falha no pagamento suspende na hora (sem carência); cupom criado pelo super admin com validade e limite de uso (decidido em 2026-10-22).
- [ ] Ainda em aberto: quais funcionalidades cada plano libera, limita ou oculta, e o prazo de retenção de dados após o cancelamento.
- [x] Hospedagem: Docker em servidor simples no começo, migração para AWS + Terraform depois (decidido em 2026-10-22). → ADR 0004. Pendente: escolher o provedor do servidor e o gatilho de migração.
- [x] Nichos do primeiro teste: tatuagem, manicure, barbeiro e estética automotiva (2026-10-22).
- [ ] Registrar um domínio de trabalho para os subdomínios enquanto o nome definitivo não sai.
- [x] Migração do Tatulog: fora do escopo por ora (apenas 1 assinatura; decidido em 2026-10-22).
- [ ] Definir a regra de receita do dashboard (prevista, recebida e líquida de comissão), herdada das lacunas do Tatulog.
