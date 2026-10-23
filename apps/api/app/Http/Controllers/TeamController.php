<?php

namespace App\Http\Controllers;

use App\Team\TeamService;
use App\TenantAuth\AuthenticatedPerson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** Equipe do negócio (PLT-008). Só traduz o pedido; as regras estão em `TeamService`. */
class TeamController extends Controller
{
    public function __construct(private readonly TeamService $team) {}

    public function index(): JsonResponse
    {
        return response()->json($this->team->team());
    }

    public function invite(Request $request): JsonResponse
    {
        $request->validate([
            // Só letras, números e pontuação simples: o nome vai no texto do e-mail, sem quebra de linha nem endereço.
            'name' => ['required', 'string', 'min:2', 'max:100', "regex:/^[\\p{L}\\p{N} .'\\-]+$/u"],
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', 'string', Rule::in(TeamService::ASSIGNABLE_ROLES)],
        ]);

        return response()->json($this->team->invite(
            $this->actor($request), $request->string('name')->toString(), $request->string('email')->toString(), $request->string('role')->toString(),
        ), 201);
    }

    public function cancelInvitation(string $id): Response
    {
        $this->team->cancelInvitation($id);

        return response()->noContent();
    }

    public function changeRole(Request $request, string $id): JsonResponse
    {
        $request->validate(['role' => ['required', 'string', Rule::in(TeamService::ASSIGNABLE_ROLES)]]);

        return response()->json($this->team->changeRole($this->actor($request), $id, $request->string('role')->toString()));
    }

    public function deactivate(Request $request, string $id): JsonResponse
    {
        return response()->json($this->team->setActive($this->actor($request), $id, false));
    }

    public function reactivate(Request $request, string $id): JsonResponse
    {
        return response()->json($this->team->setActive($this->actor($request), $id, true));
    }

    private function actor(Request $request): AuthenticatedPerson
    {
        $person = $request->attributes->get('tenant_person');
        abort_unless($person instanceof AuthenticatedPerson, 401);

        return $person;
    }
}
