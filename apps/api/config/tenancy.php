<?php

use App\Tenancy\Slug;

return [
    // Domínio base: o tenant é o subdomínio, por exemplo {slug}.{base_domain}.
    'base_domain' => env('APP_BASE_DOMAIN', 'localhost'),

    // Esquema dos links que a plataforma monta para os negócios (e-mails): https em produção, http no ambiente local.
    'scheme' => env('APP_TENANT_SCHEME', 'https'),

    // Hosts da plataforma (sem tenant): o domínio base e o painel do super admin.
    'platform_hosts' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('APP_PLATFORM_HOSTS', '')),
    ))),

    // Nichos de lançamento (o nicho só muda presets e vocabulário, nunca regra de negócio).
    'niches' => ['tatuagem', 'manicure', 'barbearia', 'estetica_automotiva'],

    // Subdomínios que nenhum assinante pode usar (lista provisória, a revisar com o produto).
    'reserved_slugs' => Slug::RESERVED,
];
