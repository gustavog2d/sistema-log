# 14 · Decisões em aberto

Estas decisões precisam de resposta na Fase 0; cada uma vira um ADR em [arquitetura/adr/](arquitetura/adr/). A recomendação deste plano vem entre parênteses.

- [x] Stack: Laravel na API + TypeScript nos clientes (decidido em 2026-10-22). → ADR 0001
- [x] Banco compartilhado com RLS em vez de banco por tenant (aceito em 2026-10-22). → ADR 0002
- [ ] Escolher o gateway de pagamento após comparar Pix Automático, cartão recorrente, boleto, taxas, nota fiscal e webhooks. → ADR 0003
- [x] Planos de lançamento: Essencial, Profissional e Estúdio; teste de 7 dias; ciclo mensal ou anual com desconto no anual; cancelamento em até 7 dias sem custo; cupom de cortesia 100% ou proporcional (decidido em 2026-10-22).
- [ ] Ainda em aberto: preços e limites de cada plano, percentual do desconto anual, quais funcionalidades cada plano libera, limita ou oculta, prazo de carência na inadimplência e prazo de retenção após o cancelamento.
- [x] Hospedagem: Docker em servidor simples no começo, migração para AWS + Terraform depois (decidido em 2026-10-22). → ADR 0004. Pendente: escolher o provedor do servidor e o gatilho de migração.
- [ ] Escolher os nichos de lançamento além de tatuagem.
- [ ] Registrar um domínio de trabalho para os subdomínios enquanto o nome definitivo não sai.
- [ ] Definir como os assinantes atuais do Tatulog migram (planos, preço e prazo).
- [ ] Definir a regra de receita do dashboard (prevista, recebida e líquida de comissão), herdada das lacunas do Tatulog.
