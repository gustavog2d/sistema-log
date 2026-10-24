# AGD-001 · Serviços e categorias

## Para quem usa

**Serviços** no menu (todos os papéis consultam). Dona e gerente cadastram o que o negócio oferece: nome, categoria, duração (de 5 em 5 minutos, até 12 horas), preço em reais ou **sob orçamento**, intervalos antes e depois e se o serviço exige sinal. É possível **arquivar** um serviço (some das novas agendas, mas guarda o histórico) e reativar. **Categorias** organizam o catálogo; uma categoria só pode ser apagada quando está vazia. Quando o catálogo está vazio, **Usar serviços sugeridos** cria os serviços e categorias típicos do tipo de negócio (tatuagem, manicure, barbearia, estética automotiva), sem preço, para você ajustar.

## Para quem desenvolve

| Método | Rota | Quem |
| --- | --- | --- |
| GET | `/services` (`q`, `status` = `active`/`archived`/`all`, `category_id`, `limit`, `cursor`) | todos |
| GET | `/services/{id}` | todos |
| POST, PATCH, DELETE | `/services`, `/services/{id}` | dona e gerente |
| POST | `/services/presets` | dona e gerente |
| GET | `/service-categories` (com `services_count`) | todos |
| POST, PATCH, DELETE | `/service-categories`, `/service-categories/{id}` | dona e gerente |

- **Nome único:** colunas geradas `name_key = lower(f_unaccent(name))` com índice único por negócio; a validação dá a mensagem e o banco decide em corridas. Espaços repetidos são colapsados antes de validar.
- **Categoria:** chave estrangeira composta `(tenant_id, category_id)` garante que o serviço só aponta para categoria do mesmo negócio; `ON DELETE RESTRICT` e a regra de negócio (`CategoryInUse`, 409 com `count`) impedem apagar categoria com serviços.
- **Presets:** `config/niche_presets.php` (categoria => serviços => minutos). Pula o que já existe pelo nome; não mexe em preços já definidos.
- **Histórico:** `Service` e `ServiceCategory` usam `Audited`.
- **Testes:** `vendor/bin/pest tests/Feature/Services` e `pnpm --filter web exec vitest run src/components/panel/services-view.test.tsx`.

## Limites conhecidos

- A exclusão do serviço é barrada quando há agendamentos (409 `service_in_use`; arquive em vez de excluir). A trava por orçamentos chega com a fase de orçamento.
- Sem preço por profissional, pacotes de sessões, variações (tamanho, comprimento), foto nem vocabulário por nicho nos textos.
