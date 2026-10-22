<?php

namespace App\Tenancy;

use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;

class SubdomainTenantResolver implements TenantResolver
{
    public function resolve(Request $request): ?Tenant
    {
        $slug = $this->slugFrom($request->getHost());

        if ($slug === null || ! Slug::isValid($slug)) {
            return null;
        }

        return Tenant::query()->where('slug', $slug)->first();
    }

    /** Devolve o slug quando o host é {slug}.{base_domain} e não é host da plataforma. */
    public function slugFrom(string $host): ?string
    {
        $host = strtolower($host);

        if (in_array($host, self::platformHosts(), true)) {
            return null;
        }

        $suffix = '.'.Config::string('tenancy.base_domain');

        if (! str_ends_with($host, $suffix)) {
            return null;
        }

        $slug = substr($host, 0, -strlen($suffix));

        return $slug !== '' && ! str_contains($slug, '.') ? $slug : null;
    }

    /** @return list<string> */
    public static function platformHosts(): array
    {
        $base = Config::string('tenancy.base_domain');
        /** @var list<string> $configured */
        $configured = config('tenancy.platform_hosts', []);

        return array_values(array_unique([$base, 'admin.'.$base, ...$configured]));
    }
}
