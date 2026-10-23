<?php

namespace App\Http\Controllers\TenantAuth;

use App\Http\Controllers\Controller;
use App\Team\TeamService;
use App\TenantAuth\PasswordPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvitationController extends Controller
{
    public function accept(Request $request, TeamService $team): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'token' => ['required', 'string', 'max:128'],
            'password' => PasswordPolicy::rules(),
            // Quem aceita o convite também aceita os termos de uso (fica registrado na conta nova).
            'accept_terms' => ['required', 'accepted'],
        ]);

        return response()->json($team->accept($request->string('email')->toString(), $request->string('token')->toString(), $request->string('password')->toString()));
    }
}
