# 02 · Herança do Tatulog

O sistema-log reaproveita as regras de negócio do Tatulog (descritas em [referencias/tatulog-especificacao-funcional.md](referencias/tatulog-especificacao-funcional.md)) e corrige as lacunas listadas lá. A stack e o modelo de banco por tenant não são reaproveitados.

## O que levar

- Clientes com dedup por DDI + telefone, criação rápida dentro do agendamento e média de avaliação.
- Atendimentos com status, tipo (atendimento/retoque), valor total, sinal, comissão, status e forma de pagamento, avaliação 1–5.
- Despesas e categorias, abas de período (semana, mês, próximo mês, ano), filtros e agrupamentos.
- Dashboard: receita, despesas, saldo, meta do mês, balanço de 12 meses e calendário.
- Log de auditoria com valores antes e depois em todas as entidades.
- Landing com captura de leads (UTM + reCAPTCHA) e painel do super admin.

## O que muda

| Ponto | Tatulog hoje | sistema-log |
| --- | --- | --- |
| Isolamento de tenants | Um banco MariaDB por tenant | Banco compartilhado PostgreSQL com `tenant_id` + Row Level Security |
| Planos | Só na landing, sem efeito | Planos com funcionalidades e limites aplicados pela API, cobrança recorrente |
| Onboarding | Tenant criado à mão pelo admin | Cadastro self-service com teste grátis e subdomínio automático |
| Papéis | Todo usuário faz tudo | Dono, gerente e profissional, com permissões |
| Duração | Texto em horas inteiras | Minutos (inteiro), com fim calculado e checagem de conflito |
| Serviços | Categoria em texto livre | Catálogo de serviços com duração e preço padrão |
| Orçamento | Não existe | Orçamento que vira agendamento ao ser aprovado |
| Receita | Soma `value_total`, inclusive cancelados no gráfico | Receita prevista, recebida e líquida de comissão, sem cancelados |
| Meta do mês | Fixa em R$ 10.000 | Configurável por tenant |
| Telefone | Único só por número, máscara BR | E.164, único por tenant, máscara por país |
| Exclusão de categoria | Apaga as despesas | Bloqueada se houver despesas |
| Imagens | Campos comentados | Upload de referência, arte aprovada e resultado |
| Testes | Nenhum real | Cobertura obrigatória por funcionalidade |

Os dados atuais (banco central + bancos `dashboard_<uuid>`) serão migrados por script na Fase 3.
