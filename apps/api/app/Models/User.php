<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Identidade global de quem usa a plataforma como assinante. O vínculo com cada negócio e o
 * papel ficam em `memberships` (com RLS). A escrita é feita pelo papel de plataforma.
 *
 * @property string $id
 * @property string $name
 * @property string $email
 */
class User extends Model
{
    use HasUuids;

    protected $fillable = ['name', 'email', 'password', 'terms_accepted_at', 'terms_version'];

    /** @var list<string> */
    protected $hidden = ['password'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['password' => 'hashed', 'terms_accepted_at' => 'datetime', 'email_verified_at' => 'datetime'];
    }
}
