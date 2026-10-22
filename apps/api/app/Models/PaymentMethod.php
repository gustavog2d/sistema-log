<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Meio de pagamento do negócio: referência do gateway, bandeira e final. Nunca o número do cartão.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $gateway_ref
 */
class PaymentMethod extends Model
{
    use HasUuids;

    protected $guarded = [];
}
