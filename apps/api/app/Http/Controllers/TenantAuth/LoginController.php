<?php

namespace App\Http\Controllers\TenantAuth;

use App\Http\Controllers\Controller;
use App\TenantAuth\AccessTokens;
use App\TenantAuth\AccountIsLocked;
use App\TenantAuth\AuthenticatedPerson;
use App\TenantAuth\LoginFailed;
use App\TenantAuth\TenantAuthenticator;
use App\TenantAuth\TenantTwoFactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use stdClass;

class LoginController extends Controller
{
    public function login(Request $request, TenantAuthenticator $authenticator): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
        ]);

        return response()->json($authenticator->login($request->string('email')->toString(), $request->string('password')->toString()));
    }

    /** Segunda etapa: troca o desafio por um token de acesso depois de um código válido. */
    public function verify(Request $request, TenantAuthenticator $authenticator, TenantTwoFactor $twoFactor, AccessTokens $tokens): JsonResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:64']]);
        /** @var AuthenticatedPerson $person */
        $person = $request->attributes->get('tenant_person');
        $db = DB::connection('pgsql_auth');
        $user = $db->table('users')->where('id', $person->id)->first();

        if (! $user instanceof stdClass) {
            throw new LoginFailed;
        }

        if ($authenticator->isLocked($user)) {
            throw new AccountIsLocked;
        }

        if (! $twoFactor->verify($db, $person->id, $request->string('code')->toString())) {
            $authenticator->registerFailure($db, $user);

            throw new LoginFailed;
        }

        $tokens->revoke($person->tokenId);

        return response()->json($authenticator->access($db, $tokens->tenantId(), $user, $person->role));
    }
}
