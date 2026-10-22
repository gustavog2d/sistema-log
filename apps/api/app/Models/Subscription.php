<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Assinatura de um negócio. Tabela de plataforma: o papel da API só lê, e o preço fica gravado
 * como era no cadastro.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $plan_id
 * @property string $cycle
 * @property string $status
 * @property int $price_cents
 * @property string $currency
 * @property Carbon|null $trial_starts_at
 * @property Carbon|null $trial_ends_at
 * @property Carbon|null $current_period_start
 * @property Carbon|null $current_period_end
 * @property Carbon|null $canceled_at
 * @property Carbon|null $suspended_at
 */
class Subscription extends Model
{
    use HasUuids;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'trial_starts_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'canceled_at' => 'datetime',
            'suspended_at' => 'datetime',
        ];
    }
}
