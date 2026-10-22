<?php

return [
    // Validade dos tokens da equipe da plataforma.
    'challenge_ttl_minutes' => (int) env('PLATFORM_CHALLENGE_TTL_MINUTES', 5),
    'access_ttl_hours' => (int) env('PLATFORM_ACCESS_TTL_HOURS', 8),

    // Bloqueio progressivo da conta.
    'max_failed_attempts' => 5,
    'lock_minutes' => 15,

    'recovery_codes' => 8,
    'min_password_length' => 12,
];
