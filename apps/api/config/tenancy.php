<?php

use App\Tenancy\Slug;

return [
    // Domínio base: o tenant é o subdomínio, por exemplo {slug}.{base_domain}.
    'base_domain' => env('APP_BASE_DOMAIN', 'localhost'),

    // Hosts da plataforma (sem tenant): o domínio base e o painel do super admin.
    'platform_hosts' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('APP_PLATFORM_HOSTS', '')),
    ))),

    // Subdomínios que nenhum assinante pode usar (lista provisória, revisar no PLT-003).
    'reserved_slugs' => Slug::RESERVED,
];
