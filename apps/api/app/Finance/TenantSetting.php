<?php

namespace App\Finance;

use App\Audit\Audited;
use App\Audit\AuditedEntity;
use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Configuração simples do negócio (chave e valor em texto), única por chave.
 *
 * @property string $id
 * @property string $key
 * @property string $value
 */
class TenantSetting extends Model implements AuditedEntity
{
    use Audited;
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'tenant_settings';

    protected $fillable = ['key', 'value'];

    public function auditedFields(): array
    {
        return ['key', 'value'];
    }

    /** O histórico mostra "Configuração" para qualquer configuração do negócio. */
    public function auditType(): string
    {
        return 'setting';
    }
}
