<?php

namespace App\Http\Controllers\TenantAuth;

use App\Billing\SubscriptionAccess;
use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use App\TenantAuth\AccessTokens;
use App\TenantAuth\AuthenticatedPerson;
use App\TenantAuth\Instant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class SessionController extends Controller
{
    public function me(Request $request, TenantContext $context): JsonResponse
    {
        $person = $this->person($request);
        $tenant = $context->current();
        abort_unless($tenant instanceof Tenant, 404);
        $status = Subscription::query()->where('tenant_id', $tenant->id)->value('status');

        return response()->json([
            'user' => $person->toArray(),
            'tenant' => ['id' => $tenant->id, 'name' => $tenant->name, 'slug' => $tenant->slug],
            'can_write' => SubscriptionAccess::canWrite(is_string($status) ? $status : null),
            'two_factor_enabled' => $person->twoFactorEnabled,
        ]);
    }

    public function logout(Request $request, AccessTokens $tokens): Response
    {
        $tokens->revoke($this->person($request)->tokenId);

        return response()->noContent();
    }

    /** Invalida todos os tokens da pessoa em todos os negócios (corte por data em `users.tokens_valid_after`). */
    public function logoutAll(Request $request): Response
    {
        DB::connection('pgsql_auth')->table('users')->where('id', $this->person($request)->id)->update(['tokens_valid_after' => Instant::now()]);

        return response()->noContent();
    }

    private function person(Request $request): AuthenticatedPerson
    {
        $person = $request->attributes->get('tenant_person');
        abort_unless($person instanceof AuthenticatedPerson, 401);

        return $person;
    }
}
