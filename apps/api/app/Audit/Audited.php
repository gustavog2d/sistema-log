<?php

namespace App\Audit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Modelos de negócio auditados (FND-008): cada criação, edição e exclusão grava um registro com antes e depois
 * dos campos listados em `auditedFields()`. O modelo implementa {@see AuditedEntity} e usa também `BelongsToTenant`.
 *
 * @mixin Model
 */
trait Audited
{
    public static function bootAudited(): void
    {
        static::created(fn ($model) => app(Auditor::class)->created($model));
        static::updated(fn ($model) => app(Auditor::class)->updated($model));
        static::deleted(fn ($model) => app(Auditor::class)->deleted($model));
    }

    /** Tipo da entidade no histórico (`client`, `appointment`…). */
    public function auditType(): string
    {
        return Str::snake(class_basename($this));
    }
}
