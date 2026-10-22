<?php

namespace App\Http\Controllers\TenantAuth;

use App\Http\Controllers\Controller;
use App\TenantAuth\AuthenticatedPerson;
use App\TenantAuth\TenantAuthenticator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TwoFactorController extends Controller
{
    public function setup(Request $request, TenantAuthenticator $authenticator): JsonResponse
    {
        $person = $this->person($request);
        abort_if($person->twoFactorEnabled, 403, 'O 2FA já está ativo.');

        return response()->json($authenticator->startTwoFactor($person));
    }

    public function confirm(Request $request, TenantAuthenticator $authenticator): JsonResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:64']]);
        $person = $this->person($request);
        abort_if($person->twoFactorEnabled, 403, 'O 2FA já está ativo.');

        return response()->json(['recovery_codes' => $authenticator->confirmTwoFactor($person, $request->string('code')->toString())]);
    }

    public function disable(Request $request, TenantAuthenticator $authenticator): Response
    {
        $request->validate(['current_password' => ['required', 'string', 'max:1024']]);
        $authenticator->disableTwoFactor($this->person($request), $request->string('current_password')->toString());

        return response()->noContent();
    }

    private function person(Request $request): AuthenticatedPerson
    {
        $person = $request->attributes->get('tenant_person');
        abort_unless($person instanceof AuthenticatedPerson, 401);

        return $person;
    }
}
