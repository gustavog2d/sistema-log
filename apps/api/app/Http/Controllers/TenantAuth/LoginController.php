<?php

namespace App\Http\Controllers\TenantAuth;

use App\Http\Controllers\Controller;
use App\TenantAuth\AuthenticatedPerson;
use App\TenantAuth\TenantAuthenticator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
    public function verify(Request $request, TenantAuthenticator $authenticator): JsonResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:64']]);
        /** @var AuthenticatedPerson $person */
        $person = $request->attributes->get('tenant_person');

        return response()->json($authenticator->completeChallenge($person, $request->string('code')->toString()));
    }
}
