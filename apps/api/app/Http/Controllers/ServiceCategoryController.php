<?php

namespace App\Http\Controllers;

use App\Services\CatalogInput;
use App\Services\ServiceCatalog;
use App\Services\ServiceCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Categorias de serviços (AGD-001). */
class ServiceCategoryController extends Controller
{
    public function __construct(private readonly ServiceCatalog $catalog) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->catalog->categories()->map(fn (ServiceCategory $category): array => $category->toApi())->values()->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json($this->catalog->createCategory($this->name($request))->toApi(), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $category = ServiceCategory::query()->findOrFail($id);

        return response()->json($this->catalog->renameCategory($category, $this->name($request, $category->id))->toApi());
    }

    public function destroy(string $id): Response
    {
        $this->catalog->deleteCategory(ServiceCategory::query()->findOrFail($id));

        return response()->noContent();
    }

    private function name(Request $request, ?string $ignoreId = null): string
    {
        $rules = CatalogInput::categoryRules($ignoreId);

        /** @var array<string, mixed> $raw */
        $raw = $request->only(['name']);
        $data = validator(CatalogInput::normalize($raw), $rules)->validate();

        return is_string($data['name'] ?? null) ? $data['name'] : '';
    }
}
