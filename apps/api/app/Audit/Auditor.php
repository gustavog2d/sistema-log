<?php

namespace App\Audit;

use App\Tenancy\Exceptions\TenantContextMissing;
use App\Tenancy\TenantContext;
use App\TenantAuth\AuthenticatedPerson;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Grava o histórico de mudanças das entidades auditadas (FND-008), na mesma conexão (e transação) da mudança:
 * o trait {@see Audited} garante a transação, então se o registro falha a mudança também some.
 */
class Auditor
{
    /** Nomes de campo (ou chaves dentro de um campo) que nunca entram no histórico: segredos, credenciais, cartão e documentos. */
    private const SENSITIVE = '/(password|passwd|senha|token|secret|segredo|hash|totp|recovery|api_?key|private_?key|credential|authorization|bearer|cvv|cvc|card|cartao|iban|pix_?key|chave_?pix|cpf|cnpj|\brg\b|passport|passaporte)/i';

    /** Marca no lugar do conteúdo de um campo oculto. */
    public const MASK = '[oculto]';

    public function __construct(private readonly TenantContext $context) {}

    public function created(Model $model): void
    {
        // Relê a linha: os valores que o banco preencheu (padrões) e os tipos reais entram no histórico.
        $fresh = $model->newQueryWithoutScopes()->whereKey($model->getKey())->first() ?? $model;

        $this->write($model, 'created', null, $this->values($model, fn (string $field) => $fresh->getAttribute($field)));
    }

    public function updated(Model $model): void
    {
        $before = [];
        $after = [];
        $changes = $model->getChanges();

        foreach ($this->fields($model) as $field) {
            if (! array_key_exists($field, $changes)) {
                continue;
            }

            $before[$field] = $this->shown($model, $field, $model->getOriginal($field));
            $after[$field] = $this->shown($model, $field, $model->getAttribute($field));
        }

        // Editar só campos não auditados não gera registro.
        if ($after === []) {
            return;
        }

        $this->write($model, 'updated', $before, $after);
    }

    public function deleted(Model $model): void
    {
        $this->write($model, 'deleted', $this->values($model, fn (string $field) => $model->getOriginal($field)), null);
    }

    /**
     * Registro manual para mudanças que não são um modelo só (ex.: o expediente inteiro de um profissional, trocado de uma vez).
     * Mesma conexão e transação do chamador; quem chama garante que os valores não têm dado sensível.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(string $entityType, string $entityId, string $action, ?array $before, ?array $after): void
    {
        $tenantId = $this->context->id();

        if ($tenantId === null) {
            throw TenantContextMissing::forModel('registro manual do histórico');
        }

        $person = request()->attributes->get('tenant_person');

        DB::table('audit_logs')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'user_id' => $person instanceof AuthenticatedPerson ? $person->id : null,
            'actor_name' => $person instanceof AuthenticatedPerson ? mb_substr($person->name, 0, 100) : null,
            'impersonated_by' => $person instanceof AuthenticatedPerson ? $person->impersonatedBy : null,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'before' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
            'after' => $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
        ]);
    }

    /** @return list<string> */
    public function fields(Model $model): array
    {
        $fields = $this->entity($model)->auditedFields();

        if ($fields === []) {
            throw new LogicException($model::class.' não lista nenhum campo auditado.');
        }

        foreach ($fields as $field) {
            if (preg_match(self::SENSITIVE, $field) === 1) {
                throw new LogicException("O campo {$field} é sensível e não pode ser auditado.");
            }
        }

        return $fields;
    }

    /**
     * @param  callable(string): mixed  $read
     * @return array<string, mixed>
     */
    private function values(Model $model, callable $read): array
    {
        $values = [];

        foreach ($this->fields($model) as $field) {
            $values[$field] = $this->shown($model, $field, $read($field));
        }

        return $values;
    }

    /** Valor para o histórico: campos "ocultos" (texto livre que pode ter dado sensível) só registram que existem ou mudaram. */
    private function shown(Model $model, string $field, mixed $value): mixed
    {
        $masked = method_exists($model, 'maskedAuditFields') ? $model->maskedAuditFields() : [];

        if (is_array($masked) && in_array($field, $masked, true)) {
            return $value === null || $value === '' ? null : self::MASK;
        }

        return $this->normalize($field, $value);
    }

    private function entity(Model $model): AuditedEntity
    {
        return $model instanceof AuditedEntity ? $model : throw new LogicException($model::class.' precisa implementar '.AuditedEntity::class.'.');
    }

    private function normalize(string $field, mixed $value): mixed
    {
        return match (true) {
            $value instanceof DateTimeInterface => Carbon::instance($value)->utc()->toIso8601String(),
            $value instanceof BackedEnum => $value->value,
            is_array($value) => $this->nested($field, $value),
            is_object($value) => (string) json_encode($value),
            default => $value,
        };
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private function nested(string $field, array $value): array
    {
        $clean = [];

        foreach ($value as $key => $item) {
            if (is_string($key) && preg_match(self::SENSITIVE, $key) === 1) {
                throw new LogicException("A chave {$key} do campo {$field} é sensível e não pode ser auditada.");
            }

            $clean[$key] = $this->normalize($field, $item);
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function write(Model $model, string $action, ?array $before, ?array $after): void
    {
        $tenantId = $this->context->id();

        if ($tenantId === null) {
            throw TenantContextMissing::forModel($model::class);
        }

        $key = $model->getKey();

        if (! is_string($key) || ! Str::isUuid($key)) {
            throw new LogicException($model::class.' precisa de chave uuid para ter histórico.');
        }

        $person = request()->attributes->get('tenant_person');

        // A conexão do próprio modelo: a mudança e o registro dividem a transação e o contexto do negócio.
        $model->getConnection()->table('audit_logs')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'user_id' => $person instanceof AuthenticatedPerson ? $person->id : null,
            'actor_name' => $person instanceof AuthenticatedPerson ? mb_substr($person->name, 0, 100) : null,
            'impersonated_by' => $person instanceof AuthenticatedPerson ? $person->impersonatedBy : null,
            'entity_type' => $this->entity($model)->auditType(),
            'entity_id' => $key,
            'action' => $action,
            'before' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
            'after' => $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
        ]);
    }
}
