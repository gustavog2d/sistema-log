<?php

namespace App\Http\Controllers;

use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

class CurrentTenantController extends Controller
{
    public function __invoke(TenantContext $context): JsonResponse
    {
        $tenant = $context->current();
        abort_if($tenant === null, 404);

        return response()->json([
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'timezone' => $tenant->timezone,
            'currency' => $tenant->currency,
        ]);
    }
}
