<?php

namespace App\TenantAuth;

use App\Mail\PasswordResetMail;
use App\Models\Tenant;
use App\Notifications\Notifier;
use App\Tenancy\TenantContext;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;
use stdClass;
use Throwable;

/** Recuperação e troca de senha. Trocar a senha derruba todos os acessos anteriores. */
class PasswordService
{
    public function __construct(
        private readonly TenantAuthenticator $authenticator,
        private readonly AccessTokens $tokens,
        private readonly TenantContext $context,
        private readonly Notifier $notifier,
    ) {}

    /** Envia o e-mail só se a pessoa tem vínculo com este negócio; quem chama sempre responde igual. */
    public function sendResetLink(string $email, string $scheme): void
    {
        $email = mb_strtolower($email);
        $db = DB::connection('pgsql_auth');
        $tenant = $this->context->current();
        $user = $db->table('users')->where('email', $email)->first();

        if (! $tenant instanceof Tenant || ! $user instanceof stdClass || $this->authenticator->roleOf($db, $tenant->id, $user) === null) {
            return;
        }

        $token = Str::random(64);

        // Um pedido novo substitui o anterior: só o último link vale.
        $db->table('password_reset_tokens')->updateOrInsert(['email' => $email], ['token_hash' => hash('sha256', $token), 'created_at' => now()]);

        $link = sprintf('%s://%s.%s/redefinir-senha?token=%s&email=%s', $scheme, $tenant->slug, Config::string('tenancy.web_host'), $token, urlencode($email));

        $mail = new PasswordResetMail($tenant->name, $link, $token);

        // Depois da resposta: o tempo do envio não distingue quem tem vínculo de quem não tem.
        defer(function () use ($email, $mail): void {
            try {
                Mail::to($email)->send($mail);
            } catch (Throwable $e) {
                // O pedido não revela falha de envio (a resposta é sempre a mesma); o erro vai para o log.
                // Só a classe: a mensagem do transporte pode trazer o endereço da pessoa.
                report(new \RuntimeException('Falha ao enviar o e-mail de recuperação ('.$e::class.').'));
            }
        });
    }

    /**
     * @throws ValidationException token inválido, expirado ou já usado (sempre a mesma mensagem)
     */
    public function reset(string $email, string $token, #[SensitiveParameter] string $password): void
    {
        $email = mb_strtolower($email);
        $db = DB::connection('pgsql_auth');
        $invalid = ValidationException::withMessages(['token' => 'O link de recuperação é inválido ou expirou.']);

        $row = $db->table('password_reset_tokens')->where('email', $email)->first();
        $user = $db->table('users')->where('email', $email)->first();
        $tenantId = $this->tokens->tenantId();

        if (
            ! $row instanceof stdClass || ! $user instanceof stdClass
            || ! is_string($row->token_hash) || ! hash_equals($row->token_hash, hash('sha256', $token))
            || ! is_string($row->created_at) || ! now()->lessThan(Carbon::parse($row->created_at)->addMinutes(Config::integer('auth_tenant.reset_ttl_minutes')))
            || $this->authenticator->roleOf($db, $tenantId, $user) === null
        ) {
            throw $invalid;
        }

        $db->transaction(function (Connection $tx) use ($email, $user, $password): void {
            $tx->table('users')->where('id', $user->id)->update([
                'password' => Hash::make($password), 'tokens_valid_after' => Instant::now(), 'failed_attempts' => 0, 'locked_until' => null, 'updated_at' => now(),
            ]);
            $tx->table('password_reset_tokens')->where('email', $email)->delete();
        });

        $this->notifier->toPerson($tenantId, $email, is_string($user->name) ? $user->name : '', 'password_changed', 'password_changed:'.(is_string($user->id) ? $user->id : '').':'.intdiv(time(), 300));
    }

    /**
     * Troca a senha e devolve um acesso novo (os demais caem).
     *
     * @return array<string, mixed>
     *
     * @throws AccountIsLocked
     * @throws ValidationException
     */
    public function change(AuthenticatedPerson $person, #[SensitiveParameter] string $current, #[SensitiveParameter] string $new): array
    {
        $db = DB::connection('pgsql_auth');
        $user = $this->authenticator->confirmPassword($db, $person, $current);

        if ($current === $new) {
            throw ValidationException::withMessages(['password' => 'A nova senha precisa ser diferente da atual.']);
        }

        $db->table('users')->where('id', $user->id)->update([
            'password' => Hash::make($new), 'tokens_valid_after' => Instant::now(), 'failed_attempts' => 0, 'updated_at' => now(),
        ]);

        $this->notifier->toPerson($this->tokens->tenantId(), $person->email, $person->name, 'password_changed', 'password_changed:'.$person->id.':'.intdiv(time(), 300));

        // O acesso novo nasce depois do corte: só ele vale.
        $fresh = $db->table('users')->where('id', $user->id)->first();

        return $this->authenticator->access($db, $this->tokens->tenantId(), $fresh instanceof stdClass ? $fresh : $user, $person->role);
    }
}
