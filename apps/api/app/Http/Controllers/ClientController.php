<?php

namespace App\Http\Controllers;

use App\Clients\Client;
use App\Clients\ClientInput;
use App\Clients\ClientService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Clientes do negócio (CLI-001). Só traduz o pedido; as regras estão em `ClientService`. */
class ClientController extends Controller
{
    public function __construct(private readonly ClientService $clients) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'min:2', 'max:100'],
            'limit' => ['sometimes', 'integer', 'between:1,50'],
            'cursor' => ['sometimes', 'string', 'max:500'],
        ]);

        $page = $this->clients->list($request->filled('q') ? trim($request->string('q')->toString()) : null, $request->integer('limit', 20));

        return response()->json([
            'data' => collect($page->items())->map(fn (Client $client): array => $client->toApi())->values()->all(),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, partial: false);

        return response()->json($this->clients->create($data)->toApi(), 201);
    }

    public function quick(Request $request): JsonResponse
    {
        $data = $this->validated($request, partial: false, only: ['name', 'phone'], unique: false);
        $name = $data['name'];
        $phone = $data['phone'];
        $result = $this->clients->quick(is_string($name) ? $name : '', is_string($phone) ? $phone : '');

        // Só o necessário para escolher o cliente: nada de e-mail, Instagram ou notas.
        $client = $result['client'];

        return response()->json(['client' => ['id' => $client->id, 'name' => $client->name, 'phone' => $client->phone], 'existing' => $result['existing']], $result['existing'] ? 200 : 201);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(Client::query()->withStats()->findOrFail($id)->toApi());
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $client = Client::query()->findOrFail($id);
        $data = $this->validated($request, partial: true, ignoreId: $client->id);

        return response()->json($this->clients->update($client, $data)->toApi());
    }

    public function destroy(string $id): Response
    {
        $this->clients->delete(Client::query()->findOrFail($id));

        return response()->noContent();
    }

    /**
     * @param  list<string>|null  $only
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $partial, ?string $ignoreId = null, ?array $only = null, bool $unique = true): array
    {
        $rules = ClientInput::rules($partial, $ignoreId, $unique);

        if ($only !== null) {
            $rules = array_intersect_key($rules, array_flip($only));
        }

        /** @var array<string, mixed> $raw */
        $raw = $request->only(array_keys($rules));
        $input = ClientInput::normalize($raw);

        /** @var array<string, mixed> $data */
        $data = validator($input, $rules)->validate();

        return $data;
    }
}
