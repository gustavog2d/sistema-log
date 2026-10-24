<?php

/**
 * Serviços iniciais por nicho (AGD-001): categoria => [nome do serviço => duração em minutos]. O preço fica vazio
 * ("sob orçamento") para o negócio preencher; as durações são só sugestões (múltiplos de 5).
 */
return [
    'tatuagem' => [
        'Tatuagens' => ['Tatuagem pequena' => 60, 'Tatuagem média' => 180, 'Tatuagem grande (sessão)' => 300, 'Fechamento (sessão)' => 360, 'Cobertura (sessão)' => 300],
        'Outros' => ['Retoque' => 60, 'Consulta e orçamento' => 30],
    ],
    'manicure' => [
        'Mãos' => ['Manicure' => 60, 'Esmaltação em gel' => 90, 'Alongamento em gel' => 150, 'Manutenção de alongamento' => 120],
        'Pés' => ['Pedicure' => 60, 'Spa dos pés' => 90],
        'Outros' => ['Remoção de gel' => 30],
    ],
    'barbearia' => [
        'Cabelo' => ['Corte' => 30, 'Degradê' => 45, 'Pigmentação' => 60],
        'Barba' => ['Barba' => 30, 'Corte e barba' => 60, 'Acabamento' => 15],
    ],
    'estetica_automotiva' => [
        'Lavagem' => ['Lavagem completa' => 120, 'Lavagem técnica' => 180, 'Higienização interna' => 180],
        'Proteção da pintura' => ['Polimento comercial' => 360, 'Polimento técnico' => 480, 'Cristalização' => 240, 'Vitrificação' => 480],
    ],
];
