<?php

namespace Tests\Support;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ProbeItem extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $table = 'probe_items';

    protected $fillable = ['name'];
}
