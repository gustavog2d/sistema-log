<?php

namespace App\Models;

use Database\Factories\PlatformUserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * Equipe da plataforma. Tabela de plataforma: sem tenant_id nem RLS.
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string $role
 * @property bool $active
 * @property string|null $totp_secret
 * @property Carbon|null $totp_confirmed_at
 * @property int|null $totp_last_timestamp
 * @property list<string>|null $recovery_codes
 * @property int $failed_attempts
 * @property Carbon|null $locked_until
 */
class PlatformUser extends Authenticatable
{
    /** @use HasFactory<PlatformUserFactory> */
    use HasApiTokens, HasFactory, HasUuids;

    protected $fillable = ['name', 'email', 'password', 'role', 'active'];

    /** @var list<string> */
    protected $hidden = ['password', 'totp_secret', 'recovery_codes', 'remember_token'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'active' => 'boolean',
            'totp_secret' => 'encrypted',
            'recovery_codes' => 'encrypted:array',
            'totp_confirmed_at' => 'datetime',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function hasTwoFactor(): bool
    {
        return $this->totp_confirmed_at !== null;
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    /** Desativa o usuário e revoga todos os tokens dele. */
    public function deactivate(): void
    {
        $this->forceFill(['active' => false])->save();
        $this->tokens()->delete();
    }
}
