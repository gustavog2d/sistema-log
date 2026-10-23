# 16 · Design system: paleta e logotipo sugeridos

O nome do produto, o domínio, as cores e o logotipo ainda não estão definidos; esta é a **sugestão inicial** (Gustavo pediu em 2026-10-22), toda trocável por configuração e pelos tokens.

## Direção visual

O público são profissionais que atendem por horário (tatuagem, manicure, barbearia, estética automotiva): gente ocupada, quase sempre no celular. A interface deve parecer **profissional e calma**, com pouco ruído, leitura fácil e botões grandes. Por isso: fundo claro, uma cor de marca profunda e um acento quente para as ações de destaque.

## Paleta

| Papel | Cor | Uso |
| --- | --- | --- |
| Marca (azul-tinta) | `#3B3FB3` (escala de 50 a 900) | Botão principal, links, menu |
| Destaque (âmbar) | `#F5A524` | Chamadas de destaque (ex.: "Começar agora") |
| Neutros (ardósia) | `#F8FAFC` a `#0F172A` | Fundos, textos, bordas |
| Sucesso, alerta, erro, informação | verde, âmbar escuro, vermelho, azul (fundos claros e textos escuros) | Selos e avisos |

Todos os pares de texto e fundo passam do contraste mínimo de 4,5:1 (WCAG 2.2 AA), conferido por teste. Veja a página `/guia-de-estilo`.

## Logotipo (provisório)

Um quadrado arredondado azul-tinta com um traço diagonal âmbar (lembra uma agulha, um pincel e um ponteiro de agenda ao mesmo tempo) e o monograma do nome em branco, seguido do nome. Serve para qualquer um dos nichos e funciona em tamanho pequeno. O componente `Logo` monta tudo a partir do nome em configuração: quando o nome definitivo existir, é só trocar `NEXT_PUBLIC_APP_NAME` e, se quiserem um desenho próprio, o SVG do componente.

## Tipografia

Fonte do sistema (sem baixar nada, carrega rápido): `system-ui` com reserva. Títulos em negrito, texto de 16 px no mínimo nos campos (evita o zoom automático do iPhone).
