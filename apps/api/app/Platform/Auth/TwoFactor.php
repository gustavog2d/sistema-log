<?php

namespace App\Platform\Auth;

use App\Models\PlatformUser;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use SensitiveParameter;

class TwoFactor
{
    public function __construct(private readonly Google2FA $google2fa, private readonly PlatformAudit $audit) {}

    /** @return array{secret: string, otpauth_uri: string} */
    public function setup(PlatformUser $user): array
    {
        $secret = $this->google2fa->generateSecretKey();
        $user->forceFill(['totp_secret' => $secret, 'totp_confirmed_at' => null, 'totp_last_timestamp' => null])->save();

        return [
            'secret' => $secret,
            'otpauth_uri' => $this->google2fa->getQRCodeUrl(Config::string('app.name'), $user->email, $secret),
        ];
    }

    /**
     * Confirma o 2FA com um código válido e devolve os códigos de recuperação (uma única vez).
     *
     * @return list<string>|null null quando o código não confere
     */
    public function confirm(PlatformUser $user, #[SensitiveParameter] string $code): ?array
    {
        if (! $this->verifyTotp($user, $code)) {
            return null;
        }

        $plain = [];
        for ($i = 0; $i < Config::integer('platform.recovery_codes'); $i++) {
            $plain[] = implode('-', str_split(bin2hex(random_bytes(6)), 4));
        }

        $user->forceFill([
            'totp_confirmed_at' => now(),
            'recovery_codes' => array_map(fn (string $c) => Hash::make($c), $plain),
        ])->save();
        $this->audit->record('two_factor.enabled', $user);

        return $plain;
    }

    /** Aceita o código TOTP (uma vez por janela) ou um código de recuperação (uso único). */
    public function verify(PlatformUser $user, #[SensitiveParameter] string $code): bool
    {
        if ($this->verifyTotp($user, $code)) {
            return true;
        }

        return $this->consumeRecoveryCode($user, $code);
    }

    private function verifyTotp(PlatformUser $user, #[SensitiveParameter] string $code): bool
    {
        if ($user->totp_secret === null || preg_match('/^\d{6}$/', $code) !== 1) {
            return false;
        }

        // Linha travada: o mesmo código não é aceito duas vezes, nem em pedidos simultâneos.
        return DB::transaction(function () use ($user, $code): bool {
            $fresh = PlatformUser::query()->lockForUpdate()->findOrFail($user->id);

            if ($fresh->totp_secret === null) {
                return false;
            }

            $timestamp = $this->google2fa->verifyKeyNewer($fresh->totp_secret, $code, $fresh->totp_last_timestamp ?? 0, 1);

            if (! is_int($timestamp)) {
                return false;
            }

            $fresh->forceFill(['totp_last_timestamp' => $timestamp])->save();
            $user->setRawAttributes($fresh->getAttributes(), true);

            return true;
        });
    }

    private function consumeRecoveryCode(PlatformUser $user, #[SensitiveParameter] string $code): bool
    {
        $used = DB::transaction(function () use ($user, $code): bool {
            // Linha travada: dois pedidos simultâneos não usam o mesmo código de recuperação.
            $fresh = PlatformUser::query()->lockForUpdate()->findOrFail($user->id);
            $codes = $fresh->recovery_codes ?? [];

            foreach ($codes as $index => $hash) {
                if (Hash::check(mb_strtolower(trim($code)), $hash)) {
                    unset($codes[$index]);
                    $fresh->forceFill(['recovery_codes' => array_values($codes)])->save();
                    $user->setRawAttributes($fresh->getAttributes(), true);

                    return true;
                }
            }

            return false;
        });

        if ($used) {
            $this->audit->record('recovery_code.used', $user);
        }

        return $used;
    }
}
