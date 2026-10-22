<?php

namespace Database\Seeders;

use App\Models\Feature;
use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Catálogo inicial. Idempotente e sem sobrescrever o que a equipe já ajustou.
 * Os três planos nascem em rascunho, sem preço e sem regras: isso ainda não foi definido pelo produto.
 */
class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([['essencial', 'Essencial'], ['profissional', 'Profissional'], ['estudio', 'Estúdio']] as $position => [$key, $name]) {
            Plan::query()->firstOrCreate(['key' => $key], ['name' => $name, 'position' => $position + 1]);
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
