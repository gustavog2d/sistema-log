<?php

return [
    // Meio de pagamento: `fake` (teste, simulado) até o gateway real (Asaas) entrar.
    // Sem valor padrão de propósito: cada ambiente declara o seu. `fake` só é aceito em local e testes.
    'gateway' => env('BILLING_GATEWAY'),

    // Versão provisória dos termos de uso e privacidade, registrada no aceite do cadastro.
    'terms_version' => env('TERMS_VERSION', '2026-10-provisoria'),
];
