<?php

namespace App\Console\Commands;

use App\Billing\SubscriptionBilling;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

class RunBilling extends Command
{
    protected $signature = 'billing:run {--as-of= : Data e hora a simular (padrão: agora)}';

    protected $description = 'Cobra o fim dos testes e as renovações; suspende quem não pagou';

    public function handle(SubscriptionBilling $billing): int
    {
        $option = $this->option('as-of');

        // Simular o tempo cobraria assinaturas antes da hora: só em desenvolvimento e testes.
        if ($option !== null && $option !== '' && ! app()->environment(['local', 'testing'])) {
            $this->error('--as-of só pode ser usado nos ambientes local e testing.');

            return self::FAILURE;
        }

        try {
            $asOf = is_string($option) && $option !== '' ? Carbon::parse($option, 'UTC') : now();
        } catch (Throwable) {
            $this->error('Data inválida em --as-of.');

            return self::FAILURE;
        }

        $summary = $billing->run($asOf);

        $this->info("Cobradas: {$summary->charged}");
        $this->info("Suspensas: {$summary->suspended}");
        $this->info("Erros: {$summary->errors}");

        // Falha técnica nunca passa em silêncio: o agendador e o monitoramento enxergam o código de saída.
        return $summary->errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
