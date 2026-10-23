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
 * Grava o histórico de mudanças das entidades auditadas (FND-008). Usa a mesma conexão (e a mesma transação)
 * da mudança: se uma falha, a outra também some.
 */
class Auditor
{
    /** Nomes de campo que nunca entram no histórico. */
    private const SENSITIVE = '/(password|senha|token|secret|segredo|hash|totp|recovery)/i';

    public function __construct(private readonly TenantContext $context) {}

    public function created(Model $model): void
    {
        $after = $this->pick($model, $model->getAttributes());

        $this->write($model, 'created', null, $after);
    }

    public function updated(Model $model): void
    {
        $before = [];
        $after = [];

        foreach ($this->fields($model) as $field) {
            if (! array_key_exists($field, $model->getChanges())) {
                continue;
            }

            $before[$field] = $this->normalize($model->getOriginal($field));
            $after[$field] = $this->normalize($model->getAttribute($field));
        }

        // Editar só campos não auditados não gera registro.
        if ($after === []) {
            return;
        }

        $this->write($model, 'updated', $before, $after);
    }

    public function deleted(Model $model): void
    {
        $before = $this->pick($model, $model->getOriginal());

        $this->write($model, 'deleted', $before, null);
    }

    /** @return list<string> */
    public function fields(Model $model): array
    {
        $fields = $this->entity($model)->auditedFields();

        foreach ($fields as $field) {
            if (preg_match(self::SENSITIVE, $field) === 1) {
                throw new LogicException("O campo {$field} é sensível e não pode ser auditado.");
            }
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function pick(Model $model, array $attributes): array
    {
        $values = [];

        foreach ($this->fields($model) as $field) {
            $values[$field] = $this->normalize($model->hasCast($field) && array_key_exists($field, $attributes) ? $model->getAttribute($field) : ($attributes[$field] ?? null));
        }

        return $values;
    }

    private function entity(Model $model): AuditedEntity
    {
        return $model instanceof AuditedEntity ? $model : throw new LogicException($model::class.' precisa implementar '.AuditedEntity::class.'.');
    }

    private function normalize(mixed $value): mixed
    {
        return match (true) {
            $value instanceof DateTimeInterface => Carbon::instance($value)->utc()->toIso8601String(),
            $value instanceof BackedEnum => $value->value,
            is_array($value) => array_map(fn (mixed $item) => $this->normalize($item), $value),
            is_object($value) => (string) json_encode($value),
            default => $value,
        };
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

        $person = request()->attributes->get('tenant_person');

        DB::table('audit_logs')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'user_id' => $person instanceof AuthenticatedPerson ? $person->id : null,
            'actor_name' => $person instanceof AuthenticatedPerson ? mb_substr($person->name, 0, 100) : null,
            'entity_type' => $this->entity($model)->auditType(),
            'entity_id' => is_scalar($model->getKey()) ? (string) $model->getKey() : '',
            'action' => $action,
            'before' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
            'after' => $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
        ]);
    }
}
