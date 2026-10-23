<?php

namespace App\Clients;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/** Regras de clientes (CLI-001). O controller só traduz o pedido. */
class ClientService
{
    /** @return CursorPaginator<int, Client> */
    public function list(?string $search, int $limit): CursorPaginator
    {
        return Client::query()
            ->when($search !== null && $search !== '', fn (Builder $query) => $this->search($query, (string) $search))
            // Ordem alfabética sem acento nem maiúsculas (coluna gerada pelo banco); o id desempata.
            ->orderBy('search_name')->orderBy('id')
            ->cursorPaginate($limit);
    }

    /** @param  array<string, mixed>  $data */
    public function create(array $data): Client
    {
        try {
            return Client::create($data);
        } catch (UniqueConstraintViolationException $e) {
            throw $this->duplicate($e);
        }
    }

    /** @param  array<string, mixed>  $data */
    public function update(Client $client, array $data): Client
    {
        try {
            $client->fill($data)->save();
        } catch (UniqueConstraintViolationException $e) {
            throw $this->duplicate($e);
        }

        return $client;
    }

    public function delete(Client $client): void
    {
        $client->delete();
    }

    /**
     * Criação rápida: o telefone repetido devolve o cliente que já existe. Quem decide é o banco (índice único),
     * então duas chamadas ao mesmo tempo resultam em um só cliente.
     *
     * @return array{client: Client, existing: bool}
     */
    public function quick(string $name, string $phone): array
    {
        try {
            return ['client' => Client::create(['name' => $name, 'phone' => $phone]), 'existing' => false];
        } catch (UniqueConstraintViolationException) {
            return ['client' => Client::query()->where('phone', $phone)->firstOrFail(), 'existing' => true];
        }
    }

    /** @param  Builder<Client>  $query */
    private function search(Builder $query, string $search): void
    {
        // Os curingas do LIKE digitados pela pessoa valem como texto.
        $like = '%'.addcslashes($search, '\\%_').'%';
        $digits = preg_replace('/\D/', '', $search) ?? '';
        $looksLikePhone = preg_match('/^[\d\s()+.\-]+$/', $search) === 1 && strlen($digits) >= 2;

        $query->where(function (Builder $where) use ($like, $digits, $looksLikePhone): void {
            $where->whereRaw("search_text like lower(f_unaccent(?)) escape '\\'", [$like]);

            if ($looksLikePhone) {
                $where->orWhere('phone_digits', 'like', '%'.$digits.'%');
            }
        });
    }

    private function duplicate(UniqueConstraintViolationException $e): ValidationException
    {
        $message = $e->getMessage();

        return ValidationException::withMessages(match (true) {
            str_contains($message, 'clients_tenant_email_unique') => ['email' => 'Já existe um cliente com este e-mail.'],
            str_contains($message, 'clients_tenant_instagram_unique') => ['instagram' => 'Já existe um cliente com este Instagram.'],
            default => ['phone' => 'Já existe um cliente com este telefone.'],
        });
    }
}
