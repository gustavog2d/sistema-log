<?php

return [
    // Validade dos tokens do assinante.
    'access_ttl_hours' => (int) env('TENANT_ACCESS_TTL_HOURS', 12),
    'challenge_ttl_minutes' => 5,

    // Recuperação de senha.
    'reset_ttl_minutes' => 60,

    // Bloqueio da conta (mesmas regras da equipe da plataforma).
    'max_failed_attempts' => 5,
    'lock_minutes' => 15,

    'recovery_codes' => 8,

    // Decisão de produto pendente: 2FA obrigatório para donas. Desligado por enquanto.
    'owner_two_factor_required' => (bool) env('OWNER_TWO_FACTOR_REQUIRED', false),
];
