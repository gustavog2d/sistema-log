<?php

namespace Tests\Support;

use App\Audit\Audited;
use App\Audit\AuditedEntity;
use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Entidade de teste da auditoria: `name` e `price_cents` são auditados; `note` não. */
class AuditProbe extends Model implements AuditedEntity
{
    use Audited;
    use BelongsToTenant;
    use HasUuids;

    public $timestamps = false;

    protected $table = 'audit_probes';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['price_cents' => 'integer', 'meta' => 'array'];
    }

    public function auditedFields(): array
    {
        return ['name', 'price_cents'];
    }
}

/** Entidade de teste com um campo sensível mal declarado. */
class AuditProbeWithSecret extends AuditProbe
{
    public function auditedFields(): array
    {
        return ['name', 'password'];
    }
}

/** Entidade de teste que audita um campo JSON com uma chave sensível dentro. */
class AuditProbeWithJson extends AuditProbe
{
    public function auditedFields(): array
    {
        return ['name', 'meta'];
    }
}

/** Entidade de teste sem campos auditados. */
class AuditProbeEmpty extends AuditProbe
{
    public function auditedFields(): array
    {
        return [];
    }
}
