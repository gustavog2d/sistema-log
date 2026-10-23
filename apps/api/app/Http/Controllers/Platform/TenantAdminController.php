<?php

namespace App\Http\Controllers\Platform;

use App\Admin\TenantAdminActions;
use App\Admin\TenantDirectory;
use App\Http\Controllers\Controller;
use App\Platform\Auth\AuthenticatedPlatformUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Gestão de assinantes pela equipe da plataforma (PLT-009). Só traduz o pedido; as regras estão em `App\Admin`. */
class TenantAdminController extends Controller
{
    public function __construct(private readonly TenantDirectory $directory, private readonly TenantAdminActions $actions) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', Rule::in(['trialing', 'active', 'suspended', 'canceled'])],
            'limit' => ['sometimes', 'integer', 'between:1,50'],
            'cursor' => ['sometimes', 'string', 'max:500'],
        ]);

        return response()->json($this->directory->list(
            $request->filled('q') ? $request->string('q')->toString() : null,
            $request->filled('status') ? $request->string('status')->toString() : null,
            $request->integer('limit', 20),
        ));
    }

    public function show(string $id): JsonResponse
    {
        return response()->json($this->directory->detail($id) ?? abort(404));
    }

    public function suspend(Request $request, string $id): JsonResponse
    {
        $request->validate(['reason' => ['required', 'string', 'min:5', 'max:200']]);
        $this->actions->suspend(AuthenticatedPlatformUser::from($request), $id, $request->string('reason')->toString());

        return $this->item($id);
    }

    public function unsuspend(Request $request, string $id): JsonResponse
    {
        $this->actions->unsuspend(AuthenticatedPlatformUser::from($request), $id);

        return $this->item($id);
    }

    public function extendTrial(Request $request, string $id): JsonResponse
    {
        $request->validate(['days' => ['required', 'integer', 'between:1,30']]);
        $this->actions->extendTrial(AuthenticatedPlatformUser::from($request), $id, $request->integer('days'));

        return $this->item($id);
    }

    public function setEntitlement(Request $request, string $id, string $feature): JsonResponse
    {
        $request->validate([
            'enabled' => ['required', 'boolean'],
            'limit' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000'],
            'visible' => ['sometimes', 'boolean'],
        ]);
        abort_unless($this->directory->find($id) !== null, 404);

        return response()->json($this->actions->setEntitlement(AuthenticatedPlatformUser::from($request), $id, $feature, [
            'enabled' => $request->boolean('enabled'),
            'limit' => $request->has('limit') && $request->input('limit') !== null ? $request->integer('limit') : null,
            'visible' => $request->boolean('visible', true),
        ]));
    }

    public function resetEntitlement(Request $request, string $id, string $feature): JsonResponse
    {
        abort_unless($this->directory->find($id) !== null, 404);

        return response()->json($this->actions->resetEntitlement(AuthenticatedPlatformUser::from($request), $id, $feature));
    }

    public function impersonate(Request $request, string $id): JsonResponse
    {
        $request->validate(['reason' => ['required', 'string', 'min:10', 'max:200']]);

        return response()->json($this->actions->impersonate(AuthenticatedPlatformUser::from($request), $id, $request->string('reason')->toString()));
    }

    private function item(string $id): JsonResponse
    {
        return response()->json($this->directory->find($id) ?? abort(404));
    }
}
