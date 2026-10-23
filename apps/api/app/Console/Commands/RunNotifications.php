<?php

namespace App\Console\Commands;

use App\Notifications\Notifier;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class RunNotifications extends Command
{
    protected $signature = 'notifications:run {--as-of= : Data e hora a simular (padrão: agora)}';

    protected $description = 'Envia os avisos agendados (fim do teste chegando)';

    /** Dias antes do fim do teste em que a dona é avisada. */
    public const TRIAL_WARNING_DAYS = 2;

    public function handle(Notifier $notifier): int
    {
        $option = $this->option('as-of');

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

        // Teste que acaba em até 2 dias, sem cancelamento pedido e com algo a cobrar (cortesia não recebe).
        $rows = DB::connection('pgsql_platform')->table('subscriptions')
            ->where('status', 'trialing')->whereNull('canceled_at')->where('price_cents', '>', 0)
            ->where('trial_ends_at', '>', $asOf)->where('trial_ends_at', '<=', $asOf->copy()->addDays(self::TRIAL_WARNING_DAYS))
            ->get(['id', 'tenant_id', 'trial_ends_at']);

        foreach ($rows as $row) {
            $notifier->toOwner(
                is_string($row->tenant_id) ? $row->tenant_id : '', 'trial_ending', 'trial_ending:'.(is_string($row->id) ? $row->id : ''),
                ['until' => Carbon::parse(is_string($row->trial_ends_at) ? $row->trial_ends_at : 'now', 'UTC')->setTimezone('America/Sao_Paulo')->format('d/m/Y H:i')],
            );
        }

        $this->info('Verificados: '.$rows->count());

        return self::SUCCESS;
    }
}
