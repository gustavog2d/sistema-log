<?php

namespace App\Tenancy;

use App\Models\Tenant;
use Illuminate\Http\Request;

/** Descobre o tenant de uma requisição. O mobile terá um resolvedor por token (PLT-006). */
interface TenantResolver
{
    public function resolve(Request $request): ?Tenant;
}
