<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Leads\LeadService;
use App\Platform\Auth\AuthenticatedPlatformUser;
use App\Support\CursorRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LeadController extends Controller
{
    public const STATUSES = ['new', 'contacted', 'converted', 'discarded'];

    public function index(Request $request, LeadService $leads): JsonResponse
    {
        $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', Rule::in(self::STATUSES)],
            'utm_source' => ['sometimes', 'nullable', 'string', 'max:100'],
            'limit' => ['sometimes', 'integer', 'between:1,50'],
            'cursor' => ['sometimes', 'string', 'max:500', CursorRule::for(['id' => 'uuid', 'created_at' => 'date'])],
        ]);

        return response()->json($leads->list(
            $request->filled('q') ? $request->string('q')->toString() : null,
            $request->filled('status') ? $request->string('status')->toString() : null,
            $request->filled('utm_source') ? $request->string('utm_source')->toString() : null,
            $request->integer('limit', 20),
        ));
    }

    public function update(Request $request, string $id, LeadService $leads): JsonResponse
    {
        /** @var array<string, mixed> $changes */
        $changes = $request->validate([
            'status' => ['sometimes', Rule::in(self::STATUSES)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        return response()->json($leads->update(AuthenticatedPlatformUser::from($request), $id, $changes));
    }
}
