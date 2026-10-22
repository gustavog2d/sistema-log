<?php

namespace App\Tenancy\Concerns;

use App\Models\Tenant;
use App\Tenancy\Exceptions\TenantContextMissing;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modelos de tabelas de negócio: filtro automático por tenant, preenchimento do tenant_id
 * ao criar e erro explícito se não houver tenant em contexto (falha fechada).
 *
 * @mixin Model
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            if ($model->getAttribute('tenant_id') !== null) {
                return;
            }

            $tenantId = app(TenantContext::class)->id();

            if ($tenantId === null) {
                throw TenantContextMissing::forModel($model::class);
            }

            $model->setAttribute('tenant_id', $tenantId);
        });
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
