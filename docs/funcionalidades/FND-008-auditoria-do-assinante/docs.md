# FND-008 · Auditoria do assinante

## Para quem usa

A dona e a gerente abrem **Histórico** no menu e veem, do mais novo para o mais antigo, quem criou, alterou ou apagou cada registro do negócio, com a mudança campo a campo ("de → para"). Dá para filtrar por tipo de registro e carregar mais. Profissional e recepção não veem esta tela. O histórico não pode ser editado nem apagado pela API; ele é limpo só pela rotina de retenção depois do cancelamento (PLT-016).

## Para quem desenvolve

Para auditar um modelo de negócio:

```php
class Client extends Model implements AuditedEntity
{
    use Audited, BelongsToTenant, HasUuids;

    public function auditedFields(): array
    {
        return ['name', 'phone', 'email'];   // nunca senha, token ou segredo (o Auditor recusa)
    }
}
```

- `save()` e `delete()` do modelo abrem uma transação (ou ponto de salvamento) e `created`, `updated` e `deleted` gravam o registro em `audit_logs` **pela conexão do próprio modelo**: se o registro falha, a mudança some. Editar só campos fora de `auditedFields()` não grava nada.
- Em `created` os valores são relidos do banco (padrões e tipos reais); em `deleted` valem os valores salvos. Campos numéricos e de data auditados devem declarar o cast no modelo.
- Valores normalizados: datas em ISO 8601 UTC, enums pelo valor, dinheiro em centavos.
- A pessoa vem de `request()->attributes['tenant_person']`; em processos internos fica nula. O nome é guardado como cópia.
- `audit_logs` tem RLS; o papel da API só tem SELECT e INSERT; o papel de plataforma pode apagar (retenção).
- `GET /audit-logs?entity_type=&entity_id=&limit=&cursor=` (dona e gerente). O componente `AuditList` (web) mostra uma lista de registros e `AuditView` a tela `/historico`.
- Testes: `vendor/bin/pest tests/Feature/Audit` e `pnpm --filter web exec vitest run src/components/panel/audit-view.test.tsx`.

## Limites conhecidos

- Escritas em massa (`Model::query()->update()`, `insert`, `upsert`) não disparam eventos e não geram histórico: nos modelos auditados use `save`/`delete`. Com `SoftDeletes`, só o `deleted` é registrado (não há `restored`).
- O histórico guarda dados pessoais (nome, telefone, e-mail dos clientes) e é só de acréscimo para a API: o PLT-016 precisa definir o prazo e a anonimização por pedido do titular (o papel de plataforma hoje só lê e apaga).
- A hora na tela usa o fuso de Brasília (o fuso do negócio ainda é fixo).
- Não audita leituras nem permite reverter uma mudança.
- Entidades de plataforma (assinatura, cupom) continuam com o log da plataforma (`platform_audit_logs`).
