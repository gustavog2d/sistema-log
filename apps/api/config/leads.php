<?php

return [
    // `fake` (só local e testing) ou `recaptcha`. A chave secreta nunca vai para o código nem para o repositório.
    'captcha_driver' => env('CAPTCHA_DRIVER', 'fake'),
    'recaptcha_secret' => env('RECAPTCHA_SECRET', ''),
    'recaptcha_min_score' => (float) env('RECAPTCHA_MIN_SCORE', 0.5),

    // Versão da política de privacidade aceita junto com o contato.
    'privacy_version' => env('PRIVACY_POLICY_VERSION', '2026-10'),

    // Meses para apagar leads descartados ou parados.
    'retention_months' => 12,
];
