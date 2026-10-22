<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Registro de auditoria da equipe da plataforma. Nunca guarda senha, segredo nem código. */
class PlatformAuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['platform_user_id', 'event', 'ip', 'user_agent', 'metadata'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }
}
