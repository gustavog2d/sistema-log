<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Feature;
use App\Platform\Auth\AuthenticatedPlatformUser;
use App\Platform\Auth\PlatformAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FeatureController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Feature::query()->orderBy('key')->get()->map->toApi()->all()]);
    }

    public function store(Request $request, PlatformAudit $audit): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate($this->rules(null));
        $this->assertCoherent($data + ['kind' => 'toggle']);

        $feature = Feature::create($data);
        $audit->record('feature.created', AuthenticatedPlatformUser::from($request), ['feature' => $feature->key, 'after' => $feature->toApi()]);

        return response()->json($feature->refresh()->toApi(), 201);
    }

    public function update(Request $request, Feature $feature, PlatformAudit $audit): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate($this->rules($feature));
        $this->assertCoherent($data + $feature->only(['kind', 'default_limit']));

        $before = $feature->only(array_keys($data));
        $feature->fill($data)->save();
        $audit->record('feature.updated', AuthenticatedPlatformUser::from($request), [
            'feature' => $feature->key, 'before' => $before, 'after' => $feature->only(array_keys($data)),
        ]);

        return response()->json($feature->toApi());
    }

    /** @return array<string, list<mixed>> */
    private function rules(?Feature $feature): array
    {
        $required = $feature === null ? 'required' : 'sometimes';
        $key = ['string', 'max:60', 'regex:/^[a-z0-9]+([._-][a-z0-9]+)*$/'];

        return [
            'key' => $feature === null
                ? ['required', ...$key, 'unique:features,key']
                : ['sometimes', ...$key, Rule::unique('features', 'key')->ignore($feature->id)],
            'name' => [$required, 'string', 'min:1', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'kind' => [$required, Rule::in(Feature::KINDS)],
            'default_enabled' => [$required, 'boolean'],
            'default_limit' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'default_visible' => [$required, 'boolean'],
        ];
    }

    /** @param  array<string, mixed>  $state */
    private function assertCoherent(array $state): void
    {
        if (($state['kind'] ?? 'toggle') === 'toggle' && ($state['default_limit'] ?? null) !== null) {
            throw ValidationException::withMessages(['default_limit' => 'Funcionalidade de liberar/bloquear não aceita limite.']);
        }
    }
}
