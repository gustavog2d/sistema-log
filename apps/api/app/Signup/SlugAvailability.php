<?php

namespace App\Signup;

use App\Models\Tenant;
use App\Tenancy\Slug;

/** O endereço (subdomínio) escolhido para o negócio está livre? */
class SlugAvailability
{
    /** @return array{available: bool, reason?: string} */
    public function check(string $slug): array
    {
        if (in_array($slug, Slug::reserved(), true)) {
            return ['available' => false, 'reason' => 'reserved'];
        }

        if (! Slug::isValid($slug)) {
            return ['available' => false, 'reason' => 'invalid'];
        }

        if (Tenant::query()->where('slug', $slug)->exists()) {
            return ['available' => false, 'reason' => 'taken'];
        }

        return ['available' => true];
    }
}
