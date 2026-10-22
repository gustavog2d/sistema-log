<?php

namespace App\Models;

use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Tabela de plataforma: não tem tenant_id nem RLS.
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property string $status
 * @property string $timezone
 * @property string $currency
 */
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = ['name', 'slug', 'niche', 'status', 'timezone', 'currency'];
}
