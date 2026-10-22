<?php

namespace App\TenantAuth;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use SensitiveParameter;
use stdClass;

/** Verificação em duas etapas (TOTP) das pessoas do negócio. Segredo cifrado; códigos de recuperação em hash. */
class TenantTwoFactor
{
    public function __construct(private readonly Google2FA $google2fa) {}

    /** @return array{secret: string, otpauth_uri: string} */
    public function setup(Connection $db, string $userId, string $email): array
    {
        $secret = $this->google2fa->generateSecretKey();
        $db->table('users')->where('id', $userId)->update([
            'totp_secret' => Crypt::encryptString($secret), 'totp_confirmed_at' => null, 'totp_last_timestamp' => null, 'recovery_codes' => null,
        ]);

        return ['secret' => $secret, 'otpauth_uri' => $this->google2fa->getQRCodeUrl(Config::string('app.name'), $email, $secret)];
    }

    /**
     * Confirma com um código válido e devolve os códigos de recuperação (uma única vez).
     *
     * @return list<string>|null null quando o código não confere
     */
    public function confirm(Connection $db, string $userId, #[SensitiveParameter] string $code): ?array
    {
        return $db->transaction(function (Connection $tx) use ($userId, $code): ?array {
            $user = $tx->table('users')->where('id', $userId)->lockForUpdate()->first();

            if (! $user instanceof stdClass || ! $this->acceptTotp($tx, $user, $code)) {
                return null;
            }

            $plain = [];
            for ($i = 0; $i < Config::integer('auth_tenant.recovery_codes'); $i++) {
                $plain[] = implode('-', str_split(bin2hex(random_bytes(6)), 4));
            }

            $tx->table('users')->where('id', $userId)->update([
                'totp_confirmed_at' => now(),
                'recovery_codes' => Crypt::encryptString((string) json_encode(array_map(fn (string $c) => Hash::make($c), $plain))),
            ]);

            return $plain;
        });
    }

    /** Aceita o código TOTP (uma vez por janela) ou um código de recuperação (uso único). */
    public function verify(Connection $db, string $userId, #[SensitiveParameter] string $code): bool
    {
        return $db->transaction(function (Connection $tx) use ($userId, $code): bool {
            $user = $tx->table('users')->where('id', $userId)->lockForUpdate()->first();

            if (! $user instanceof stdClass) {
                return false;
            }

            return $this->acceptTotp($tx, $user, $code) || $this->consumeRecoveryCode($tx, $user, $code);
        });
    }

    public function disable(Connection $db, string $userId): void
    {
        $db->table('users')->where('id', $userId)->update([
            'totp_secret' => null, 'totp_confirmed_at' => null, 'totp_last_timestamp' => null, 'recovery_codes' => null,
        ]);
    }

    private function acceptTotp(Connection $tx, stdClass $user, string $code): bool
    {
        if (! is_string($user->totp_secret) || preg_match('/^\d{6}$/', $code) !== 1) {
            return false;
        }

        $last = is_numeric($user->totp_last_timestamp) ? (int) $user->totp_last_timestamp : 0;
        $timestamp = $this->google2fa->verifyKeyNewer(Crypt::decryptString($user->totp_secret), $code, $last, 1, intdiv(now()->getTimestamp(), 30));

        if (! is_int($timestamp)) {
            return false;
        }

        $tx->table('users')->where('id', $user->id)->update(['totp_last_timestamp' => $timestamp]);

        return true;
    }

    private function consumeRecoveryCode(Connection $tx, stdClass $user, string $code): bool
    {
        if (! is_string($user->recovery_codes)) {
            return false;
        }

        /** @var list<string> $hashes */
        $hashes = json_decode(Crypt::decryptString($user->recovery_codes), true) ?: [];
        $given = mb_strtolower(trim($code));

        foreach ($hashes as $index => $hash) {
            if (Hash::check($given, $hash)) {
                unset($hashes[$index]);
                $tx->table('users')->where('id', $user->id)->update(['recovery_codes' => Crypt::encryptString((string) json_encode(array_values($hashes)))]);

                return true;
            }
        }

        return false;
    }
}
