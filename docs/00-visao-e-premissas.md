# 00 · Visão e premissas

O sistema-log é um SaaS de agenda, orçamento e financeiro para profissionais que atendem com hora marcada, e evolui o Tatulog de um produto só para tatuadores para uma plataforma multi-nicho. Ele nasce API-first, multi-tenant e com assinatura recorrente, para que a versão web e os futuros apps iOS e Android usem o mesmo back-end.

## Nome e nomenclaturas são provisórios

"sistema-log" é o nome de trabalho e pode mudar. O mesmo vale para nomes de planos, módulos, perfis e termos de tela. Para que a troca seja barata:

- O nome da marca, domínios, e-mails e cores ficam em configuração, nunca escritos direto no código.
- No código, pacotes, tabelas e classes usam termos de domínio neutros (`tenant`, `appointment`, `quote`), não o nome do produto.
- Os textos de interface ficam em arquivos de tradução (pt-BR primeiro), o que também permite que cada nicho use seu vocabulário ("sessão" para tatuagem, "atendimento" para salão).
- Um glossário em [glossario.md](glossario.md) registra cada termo e onde ele aparece.

## Premissas do projeto

- Nenhuma linha de código antes de a funcionalidade ter especificação, documentação e plano de teste aprovados.
- Todo comportamento entregue tem teste automatizado rodando no CI; o merge é bloqueado se falhar.
- A web é só mais um cliente da API: tudo que o painel web faz, um app mobile poderá fazer pelo mesmo endpoint.
- Escala prevista desde o início para milhares de assinantes, com custo baixo por tenant.

## Escopo pedido para a primeira entrega

- Login de **super admin** para gerenciar todas as assinaturas e usuários.
- **Cadastro e assinatura** funcionando de ponta a ponta, com pagamento recorrente.
- **Funcionalidades por plano** (ex.: plano 1 com o básico; plano 2 com integração ao Google Calendar).
- **Área do assinante** em subdomínio próprio, com todas as funcionalidades do plano e gestão da própria assinatura.
- **Usuários do assinante** sem papel de administrador, que usam as funcionalidades.
