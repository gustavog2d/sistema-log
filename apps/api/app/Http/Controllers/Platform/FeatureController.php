<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Feature;
use App\Plans\Actions\SaveFeature;
use App\Platform\Auth\AuthenticatedPlatformUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FeatureController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Feature::query()->orderBy('key')->get()->map->toApi()->all()]);
    }

    public function store(Request $request, SaveFeature $saveFeature): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate($this->rules(creating: true) + [
            'key' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9]+([._-][a-z0-9]+)*$/', 'unique:features,key'],
        ]);

        return response()->json($saveFeature->create(AuthenticatedPlatformUser::from($request), $data)->toApi(), 201);
    }

    public function update(Request $request, Feature $feature, SaveFeature $saveFeature): JsonResponse
    {
        // A chave é usada pelo código (`feature:<chave>`): não muda depois de criada.
        /** @var array<string, mixed> $data */
        $data = $request->validate($this->rules(creating: false) + ['key' => ['prohibited']]);

        return response()->json($saveFeature->update(AuthenticatedPlatformUser::from($request), $feature, $data)->toApi());
    }

    /** @return array<string, list<mixed>> */
    private function rules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'min:1', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'kind' => [$required, Rule::in(Feature::KINDS)],
            'default_enabled' => [$required, 'boolean'],
            'default_limit' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000'],
            'default_visible' => [$required, 'boolean'],
        ];
    }
}
