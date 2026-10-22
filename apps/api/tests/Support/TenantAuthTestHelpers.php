<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;

trait TenantAuthTestHelpers
{
    public const PASSWORD = 'uma-senha-forte-123';

    /**
     * Cria um negócio com assinatura e uma pessoa vinculada, direto no banco (papel dono).
     *
     * @return object{tenant_id: string, user_id: string, slug: string, email: string}
     */
    protected function workspace(string $slug = 'neg', string $email = 'ana@exemplo.com', string $role = 'owner', string $status = 'active', ?string $userId = null): object
    {
        $this->seedBillingCatalog();
        $subscription = $this->subscription(['status' => $status], 'pm_fake_ok_x', $slug);
        $db = DB::connection('pgsql_admin');

        $userId ??= $db->table('users')->where('email', $email)->value('id');

        if ($userId === null) {
            $userId = (string) Str::uuid();
            $db->table('users')->insert([
                'id' => $userId, 'name' => 'Pessoa '.$role, 'email' => $email, 'password' => Hash::make(self::PASSWORD),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $db->table('memberships')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $subscription->tenant_id, 'user_id' => $userId, 'role' => $role,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (object) ['tenant_id' => $subscription->tenant_id, 'user_id' => $userId, 'slug' => $slug, 'email' => $email];
    }

    protected function authUrl(string $slug, string $path): string
    {
        return "http://{$slug}.app.test/api/v1/".ltrim($path, '/');
    }

    protected function loginAt(string $slug, string $email = 'ana@exemplo.com', string $password = self::PASSWORD): TestResponse
    {
        return $this->postJson($this->authUrl($slug, 'auth/login'), ['email' => $email, 'password' => $password]);
    }

    protected function tokenFor(string $slug = 'neg', string $email = 'ana@exemplo.com'): string
    {
        return (string) $this->loginAt($slug, $email)->assertOk()->json('access_token');
    }

    protected function asUser(string $token, string $method, string $slug, string $path, array $data = []): TestResponse
    {
        return $this->withToken($token)->json($method, $this->authUrl($slug, $path), $data);
    }

    protected function totp(string $secret): string
    {
        $google2fa = new Google2FA;

        // Usa o relógio do Laravel, o mesmo da verificação, para que `travel()` valha também aqui.
        return $google2fa->oathTotp($secret, intdiv(now()->getTimestamp(), 30));
    }

    /** Liga o 2FA da pessoa pela API e devolve o segredo e os códigos de recuperação. */
    protected function enableTwoFactor(string $slug = 'neg', string $email = 'ana@exemplo.com'): array
    {
        $token = $this->tokenFor($slug, $email);
        $secret = (string) $this->asUser($token, 'POST', $slug, 'auth/2fa/setup')->assertOk()->json('secret');
        $codes = $this->asUser($token, 'POST', $slug, 'auth/2fa/confirm', ['code' => $this->totp($secret)])->assertOk()->json('recovery_codes');

        return ['secret' => $secret, 'recovery_codes' => $codes, 'token' => $token];
    }

    protected function userRow(string $email = 'ana@exemplo.com'): object
    {
        return DB::connection('pgsql_admin')->table('users')->where('email', $email)->first();
    }

    /** Entra numa conta com 2FA já ligado, usando um código de recuperação novo para não esbarrar no replay do TOTP. */
    protected function loginTokenAfterTwoFactor(string $slug = 'neg', string $email = 'ana@exemplo.com'): string
    {
        $db = DB::connection('pgsql_admin');
        $codes = [];
        for ($i = 0; $i < 2; $i++) {
            $codes[] = 'rec-'.Str::lower(Str::random(12));
        }
        $db->table('users')->where('email', $email)->update([
            'recovery_codes' => Crypt::encryptString(json_encode(array_map(fn ($c) => Hash::make($c), $codes))),
        ]);

        $challenge = $this->loginAt($slug, $email)->assertOk()->json('challenge_token');

        return (string) $this->asUser($challenge, 'POST', $slug, 'auth/2fa/verify', ['code' => $codes[0]])->assertOk()->json('access_token');
    }
}
