<?php

namespace App\Http\Controllers\TenantAuth;

use App\Http\Controllers\Controller;
use App\TenantAuth\AuthenticatedPerson;
use App\TenantAuth\PasswordPolicy;
use App\TenantAuth\PasswordService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PasswordController extends Controller
{
    /** Sempre 202: nunca revela se o e-mail existe nem se a pessoa tem vínculo com o negócio. */
    public function forgot(Request $request, PasswordService $passwords): Response
    {
        $request->validate(['email' => ['required', 'string', 'email', 'max:255']]);

        $passwords->sendResetLink($request->string('email')->toString(), $request->getScheme());

        return response()->noContent(202);
    }

    public function reset(Request $request, PasswordService $passwords): Response
    {
        $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'token' => ['required', 'string', 'max:128'],
            'password' => PasswordPolicy::rules(),
        ]);

        $passwords->reset($request->string('email')->toString(), $request->string('token')->toString(), $request->string('password')->toString());

        return response()->noContent();
    }

    public function change(Request $request, PasswordService $passwords): JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'string', 'max:1024'],
            'password' => PasswordPolicy::rules(),
        ]);

        $person = $request->attributes->get('tenant_person');
        abort_unless($person instanceof AuthenticatedPerson, 401);

        return response()->json($passwords->change($person, $request->string('current_password')->toString(), $request->string('password')->toString()));
    }
}
