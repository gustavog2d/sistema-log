# 06 · Perfis de acesso e permissões

Há dois mundos de usuários que nunca se misturam: a equipe da plataforma (no painel do super admin) e os usuários de cada assinante (no subdomínio dele). Dentro do assinante, o acesso é por papel com permissões finas, para que novos papéis surjam sem mudar código.

| Papel | Onde | Pode |
| --- | --- | --- |
| Super admin | Plataforma | Tudo: assinantes, planos, funcionalidades, exceções, cobranças, leads, métricas da plataforma, logs |
| Suporte | Plataforma | Consultar assinantes e entrar como assinante (impersonação) com motivo registrado e aviso ao dono; sem mexer em cobrança |
| Dono | Assinante | Tudo do negócio + assinatura (trocar plano, forma de pagamento, faturas, cancelar) + usuários e papéis |
| Gerente | Assinante | Tudo do negócio, sem assinatura e sem excluir o dono |
| Profissional | Assinante | Própria agenda, próprios atendimentos e orçamentos, clientes; vê só os próprios valores e comissão |
| Recepção | Assinante | Agenda de todos, clientes e orçamentos; sem financeiro |

- Convite de usuário por e-mail, com link de uso único e expiração; o convidado define a própria senha.
- O número de usuários ativos respeita o limite `usuarios.max` do plano.
- Um mesmo e-mail pode pertencer a mais de um assinante (um tatuador que atende em dois estúdios); no login, escolhe o estúdio.
- Toda autorização é checada na API por *policies*; a interface só reflete o resultado.
