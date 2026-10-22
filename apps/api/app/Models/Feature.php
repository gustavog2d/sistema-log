<?php

namespace App\Models;

use Database\Factories\FeatureFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Funcionalidade do catálogo. Tabela de plataforma: sem tenant_id nem RLS.
 *
 * @property string $id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property string $kind
 * @property bool $default_enabled
 * @property int|null $default_limit
 * @property bool $default_visible
 */
class Feature extends Model
{
    /** @use HasFactory<FeatureFactory> */
    use HasFactory, HasUuids;

    public const KINDS = ['toggle', 'limit'];

    protected $fillable = ['key', 'name', 'description', 'kind', 'default_enabled', 'default_limit', 'default_visible'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['default_enabled' => 'boolean', 'default_visible' => 'boolean'];
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            'kind' => $this->kind,
            'default_enabled' => $this->default_enabled,
            'default_limit' => $this->default_limit,
            'default_visible' => $this->default_visible,
        ];
    }
}
