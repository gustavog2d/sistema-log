<?php

namespace App\Http\Controllers;

use App\Audit\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Histórico de mudanças do negócio (FND-008): só leitura, dona e gerente. */
class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'entity_type' => ['sometimes', 'string', 'max:40', 'regex:/^[a-z_]+$/'],
            'entity_id' => ['sometimes', 'uuid'],
            'limit' => ['sometimes', 'integer', 'between:1,50'],
            'cursor' => ['sometimes', 'string', 'max:500'],
        ]);

        $page = AuditLog::query()
            ->when($request->filled('entity_type'), fn ($query) => $query->where('entity_type', $request->string('entity_type')->toString()))
            ->when($request->filled('entity_id'), fn ($query) => $query->where('entity_id', $request->string('entity_id')->toString()))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->cursorPaginate($request->integer('limit', 20));

        return response()->json([
            'data' => collect($page->items())->map(fn (AuditLog $log): array => $log->toApi())->values()->all(),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }
}
