<?php

namespace App\Http\Controllers;

use App\Services\CatalogInput;
use App\Services\Service;
use App\Services\ServiceCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Serviços do negócio (AGD-001). Só traduz o pedido; as regras estão em `ServiceCatalog`. */
class ServiceController extends Controller
{
    public function __construct(private readonly ServiceCatalog $catalog) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'min:2', 'max:100'],
            'status' => ['sometimes', 'in:active,archived,all'],
            'category_id' => ['sometimes', 'uuid'],
            'limit' => ['sometimes', 'integer', 'between:1,50'],
            'cursor' => ['sometimes', 'string', 'max:500'],
        ]);

        $page = $this->catalog->list(
            $request->filled('q') ? trim($request->string('q')->toString()) : null,
            $request->string('status', 'active')->toString(),
            $request->filled('category_id') ? $request->string('category_id')->toString() : null,
            $request->integer('limit', 20),
        );

        return response()->json([
            'data' => collect($page->items())->map(fn (Service $service): array => $service->toApi())->values()->all(),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json($this->catalog->create($this->validated($request, false))->toApi(), 201);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(Service::query()->findOrFail($id)->toApi());
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $service = Service::query()->findOrFail($id);

        return response()->json($this->catalog->update($service, $this->validated($request, true, $service->id))->toApi());
    }

    public function destroy(string $id): Response
    {
        Service::query()->findOrFail($id)->delete();

        return response()->noContent();
    }

    public function preset(): JsonResponse
    {
        return response()->json($this->catalog->applyPreset());
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $partial, ?string $ignoreId = null): array
    {
        $rules = CatalogInput::serviceRules($partial, $ignoreId);

        /** @var array<string, mixed> $raw */
        $raw = $request->only(array_keys($rules));

        /** @var array<string, mixed> $data */
        $data = validator(CatalogInput::normalize($raw), $rules)->validate();

        return $data;
    }
}
