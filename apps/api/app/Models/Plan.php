<?php

namespace App\Models;

use App\Plans\YearlyPrice;
use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Plano de assinatura. Tabela de plataforma: sem tenant_id nem RLS.
 *
 * @property string $id
 * @property string $key
 * @property string $name
 * @property int $position
 * @property string $status
 * @property int $trial_days
 * @property string $currency
 * @property int|null $monthly_price_cents
 * @property int|null $yearly_discount_percent
 */
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory, HasUuids;

    public const STATUSES = ['draft', 'active', 'archived'];

    protected $fillable = [
        'key', 'name', 'position', 'status', 'trial_days', 'currency',
        'monthly_price_cents', 'yearly_discount_percent',
    ];

    /** @return HasMany<PlanFeature, $this> */
    public function featureRules(): HasMany
    {
        return $this->hasMany(PlanFeature::class);
    }

    public function yearlyPriceCents(): ?int
    {
        return YearlyPrice::cents($this->monthly_price_cents, $this->yearly_discount_percent);
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'name' => $this->name,
            'position' => $this->position,
            'status' => $this->status,
            'trial_days' => $this->trial_days,
            'currency' => $this->currency,
            'monthly_price_cents' => $this->monthly_price_cents,
            'yearly_discount_percent' => $this->yearly_discount_percent,
            'yearly_price_cents' => $this->yearlyPriceCents(),
        ];
    }
}
