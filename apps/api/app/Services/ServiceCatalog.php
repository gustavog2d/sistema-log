<?php

namespace App\Services;

use App\Tenancy\TenantContext;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Regras do catálogo de serviços (AGD-001). Os controllers só traduzem o pedido. */
class ServiceCatalog
{
    public function __construct(private readonly TenantContext $context) {}

    /** @return CursorPaginator<int, Service> */
    public function list(?string $search, string $status, ?string $categoryId, int $limit): CursorPaginator
    {
        return Service::query()
            ->when($status !== 'all', fn (Builder $query) => $query->where('status', $status))
            ->when($categoryId !== null, fn (Builder $query) => $query->where('category_id', $categoryId))
            ->when($search !== null && $search !== '', function (Builder $query) use ($search): void {
                // Os curingas do LIKE digitados valem como texto.
                $query->whereRaw("name_key like lower(f_unaccent(?)) escape '\\'", ['%'.addcslashes((string) $search, '\\%_').'%']);
            })
            ->orderBy('name_key')->orderBy('id')
            ->cursorPaginate($limit);
    }

    /** @param  array<string, mixed>  $data */
    public function create(array $data): Service
    {
        try {
            // `refresh` traz os padrões que o banco preencheu (intervalos, sinal, situação).
            return Service::create($data)->refresh();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'Já existe um registro com este nome.']);
        }
    }

    /** @param  array<string, mixed>  $data */
    public function update(Service $service, array $data): Service
    {
        try {
            $service->fill($data)->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'Já existe um registro com este nome.']);
        }

        return $service;
    }

    /** @return Collection<int, ServiceCategory> */
    public function categories(): Collection
    {
        return ServiceCategory::query()->withCount('services')->orderBy('name_key')->orderBy('id')->get();
    }

    public function createCategory(string $name): ServiceCategory
    {
        try {
            return ServiceCategory::create(['name' => $name])->loadCount('services');
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'Já existe um registro com este nome.']);
        }
    }

    public function renameCategory(ServiceCategory $category, string $name): ServiceCategory
    {
        try {
            $category->fill(['name' => $name])->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'Já existe um registro com este nome.']);
        }

        return $category->loadCount('services');
    }

    /** @throws CategoryInUse */
    public function deleteCategory(ServiceCategory $category): void
    {
        $count = $category->services()->count();

        if ($count > 0) {
            throw new CategoryInUse($count);
        }

        $category->delete();
    }

    /**
     * Cria os serviços e categorias iniciais do nicho do negócio, pulando o que já existe pelo nome.
     *
     * @return array{niche: string, created: array{categories: int, services: int}, skipped: array{categories: int, services: int}}
     *
     * @throws ValidationException
     */
    public function applyPreset(): array
    {
        $niche = $this->context->current()?->niche;
        $presets = config('niche_presets');
        $preset = is_string($niche) && is_array($presets) ? ($presets[$niche] ?? null) : null;

        if (! is_string($niche) || ! is_array($preset)) {
            throw ValidationException::withMessages(['niche' => 'O negócio não tem um nicho com serviços iniciais.']);
        }

        $created = ['categories' => 0, 'services' => 0];
        $skipped = ['categories' => 0, 'services' => 0];

        $attempt = function () use ($preset, &$created, &$skipped): void {
            $created = ['categories' => 0, 'services' => 0];
            $skipped = ['categories' => 0, 'services' => 0];

            DB::transaction(function () use ($preset, &$created, &$skipped): void {
                /** @var array<string, array<string, int>> $preset */
                foreach ($preset as $categoryName => $services) {
                    $category = ServiceCategory::query()->whereRaw('name_key = lower(f_unaccent(?))', [$categoryName])->first();

                    if ($category === null) {
                        $category = ServiceCategory::create(['name' => $categoryName]);
                        $created['categories']++;
                    } else {
                        $skipped['categories']++;
                    }

                    foreach ($services as $name => $minutes) {
                        if (Service::query()->whereRaw('name_key = lower(f_unaccent(?))', [$name])->exists()) {
                            $skipped['services']++;

                            continue;
                        }

                        Service::create(['name' => $name, 'duration_minutes' => $minutes, 'category_id' => $category->id]);
                        $created['services']++;
                    }
                }
            });
        };

        try {
            $attempt();
        } catch (UniqueConstraintViolationException) {
            // Duas chamadas ao mesmo tempo: o índice único recusou a segunda gravação e a transação toda foi desfeita.
            // Na repetição, o que a outra criou passa a contar como já existente.
            $attempt();
        }

        return ['niche' => $niche, 'created' => $created, 'skipped' => $skipped];
    }
}
