<?php

namespace Tests\Support;

use Database\Seeders\CatalogSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

trait SignupTestHelpers
{
    protected function seedCatalog(): void
    {
        $this->seed(CatalogSeeder::class);
    }

    /** @return array<string, mixed> */
    protected function signupPayload(array $overrides = []): array
    {
        return array_merge([
            'business_name' => 'Estúdio Tinta Viva',
            'slug' => 'tintaviva',
            'niche' => 'tatuagem',
            'owner_name' => 'Ana Souza',
            'owner_email' => 'ana@exemplo.com',
            'password' => 'uma-senha-forte-123',
            'plan' => 'profissional',
            'cycle' => 'monthly',
            'payment_method_token' => 'tok_ok',
            'accept_terms' => true,
            'captcha_token' => 'captcha_ok',
        ], $overrides);
    }

    protected function signup(array $overrides = [], ?string $key = 'chave-'.__CLASS__): TestResponse
    {
        $headers = $key === null ? [] : ['Idempotency-Key' => $key];

        return $this->postJson('http://app.test/api/v1/signup', $this->signupPayload($overrides), $headers);
    }

    /** Conta linhas ignorando o RLS (papel dono). */
    protected function countAll(string $table): int
    {
        return DB::connection('pgsql_admin')->table($table)->count();
    }

    protected function nothingWasCreated(): void
    {
        foreach (['tenants', 'users', 'memberships', 'subscriptions', 'payment_methods'] as $table) {
            expect($this->countAll($table))->toBe(0, "{$table} deveria estar vazia");
        }
    }

    /** Cria o negócio "tintaviva" para os testes de endereço ocupado. */
    protected function signupSeedAndTake(): void
    {
        $this->seedCatalog();
        $this->signup()->assertCreated();
    }
}
