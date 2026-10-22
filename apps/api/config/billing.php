<?php

return [
    // Meio de pagamento: `fake` (teste, simulado) até o gateway real (Asaas) entrar.
    'gateway' => env('BILLING_GATEWAY', 'fake'),

    // Versão provisória dos termos de uso e privacidade, registrada no aceite do cadastro.
    'terms_version' => env('TERMS_VERSION', '2026-10-provisoria'),
];
