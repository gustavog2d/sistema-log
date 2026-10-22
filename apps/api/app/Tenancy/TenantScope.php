<?php

namespace App\Tenancy;

use App\Tenancy\Exceptions\TenantContextMissing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/** @implements Scope<Model> */
class TenantScope implements Scope
{
    /** @param  Builder<covariant Model>  $builder */
    public function apply(Builder $builder, Model $model): void
    {
        $tenantId = app(TenantContext::class)->id();

        if ($tenantId === null) {
            throw TenantContextMissing::forModel($model::class);
        }

        $builder->where($model->qualifyColumn('tenant_id'), $tenantId);
    }
}
