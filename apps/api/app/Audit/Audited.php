<?php

namespace App\Audit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Modelos de negócio auditados (FND-008): cada criação, edição e exclusão grava um registro com antes e depois
 * dos campos listados em `auditedFields()`. O modelo implementa {@see AuditedEntity} e usa também `BelongsToTenant`.
 *
 * A mudança e o registro acontecem na mesma transação (o `save` e o `delete` abrem uma, ou um ponto de salvamento
 * dentro de outra): se o registro falha, a mudança some junto. Escritas em massa (`Model::query()->update()`,
 * `insert`, `upsert`) não disparam eventos e, portanto, não geram histórico: nos modelos auditados use `save`/`delete`.
 * Campos numéricos ou de data auditados devem declarar o cast, para que antes e depois tenham o mesmo tipo.
 *
 * @mixin Model
 */
trait Audited
{
    public static function bootAudited(): void
    {
        static::created(fn (Model $model) => app(Auditor::class)->created($model));
        static::updated(fn (Model $model) => app(Auditor::class)->updated($model));
        static::deleted(fn (Model $model) => app(Auditor::class)->deleted($model));
    }

    /** @param  array{touch?: bool|null}  $options */
    public function save(array $options = []): bool
    {
        return (bool) $this->getConnection()->transaction(fn (): bool => parent::save($options));
    }

    public function delete(): ?bool
    {
        return $this->getConnection()->transaction(fn (): ?bool => parent::delete());
    }

    /** Tipo da entidade no histórico (`client`, `appointment`…). */
    public function auditType(): string
    {
        return Str::snake(class_basename($this));
    }
}
