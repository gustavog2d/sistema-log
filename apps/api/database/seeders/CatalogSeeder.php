<?php

namespace Database\Seeders;

use App\Models\Feature;
use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Catálogo inicial. Idempotente e sem sobrescrever o que a equipe já ajustou.
 * Planos com os preços iniciais definidos pelo produto (R$ 15, R$ 50 e R$ 250 por mês, 10% de desconto no
 * anual). As regras de cada plano (liberada, limitada ou oculta) ainda não existem: vale o padrão da funcionalidade.
 */
class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [['essencial', 'Essencial', 1500], ['profissional', 'Profissional', 5000], ['estudio', 'Estúdio', 25000]];

        foreach ($plans as $position => [$key, $name, $monthlyCents]) {
            Plan::query()->firstOrCreate(['key' => $key], [
                'name' => $name,
                'position' => $position + 1,
                'status' => 'active',
                'monthly_price_cents' => $monthlyCents,
                'yearly_discount_percent' => 10,
            ]);
        }

        $features = [
            ['agenda.publica', 'Agenda pública', 'toggle'],
            ['integracao.google_calendar', 'Integração com Google Calendar', 'toggle'],
            ['orcamento', 'Orçamentos', 'toggle'],
            ['relatorios.exportar', 'Relatórios e exportação', 'toggle'],
            ['usuarios.max', 'Máximo de usuários', 'limit'],
        ];

        foreach ($features as [$key, $name, $kind]) {
            Feature::query()->firstOrCreate(['key' => $key], ['name' => $name, 'kind' => $kind]);
        }
    }
}
