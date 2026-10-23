<?php

namespace App\Notifications;

use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use stdClass;
use Throwable;

/**
 * Envia os avisos da plataforma (PLT-010): uma vez por acontecimento (chave de deduplicação única), pela fila,
 * e sem nunca derrubar a ação que o originou. O registro de envio não guarda o endereço de e-mail.
 */
class Notifier
{
    /** Dias antes do fim do teste em que a dona é avisada. */
    public const TRIAL_WARNING_DAYS = 2;

    /** Evento de assinatura → tipo de aviso. */
    private const BY_EVENT = [
        'billing.charged' => 'payment_succeeded',
        'subscription.suspended' => 'subscription_suspended',
        'subscription.regularized' => 'subscription_regularized',
        'subscription.cancel_requested' => 'cancel_requested',
        'subscription.canceled' => 'subscription_canceled',
    ];

    /**
     * Avisos que nascem dos eventos de cobrança e de gestão da assinatura. O contexto é o da auditoria; o aviso
     * leva à parte o que só ele precisa (`mark` identifica o acontecimento; `until` e `period_end` vêm em ISO 8601 UTC
     * e são mostrados no fuso do negócio).
     *
     * @param  array<string, scalar>  $context  `tenant`, `subscription` e `amount_cents`
     * @param  array<string, string>  $notice  `mark` e dados de texto (`amount`, `reason`, `until`, `period_end`)
     */
    public function forSubscriptionEvent(string $event, array $context, array $notice = []): void
    {
        $kind = self::BY_EVENT[$event] ?? null;
        $tenant = $context['tenant'] ?? null;

        if ($kind === null || ! is_string($tenant)) {
            return;
        }

        // Cortesia não tem cobrança para avisar.
        if ($kind === 'payment_succeeded' && (int) ($context['amount_cents'] ?? 0) === 0) {
            return;
        }

        $data = array_diff_key($notice, ['mark' => true]);
        $this->toOwner($tenant, $kind, $kind.':'.($context['subscription'] ?? '').':'.($notice['mark'] ?? ''), $data);
    }

    /**
     * Teste que acaba em até {@see self::TRIAL_WARNING_DAYS} dias, sem cancelamento pedido e com algo a cobrar (cortesia não recebe).
     *
     * @return int quantos testes foram verificados
     */
    public function trialEnding(Carbon $asOf): int
    {
        $rows = DB::connection('pgsql_platform')->table('subscriptions')
            ->where('status', 'trialing')->whereNull('canceled_at')->where('price_cents', '>', 0)
            ->where('trial_ends_at', '>', $asOf)->where('trial_ends_at', '<=', $asOf->copy()->addDays(self::TRIAL_WARNING_DAYS))
            ->get(['id', 'tenant_id', 'trial_ends_at']);

        foreach ($rows as $row) {
            $this->toOwner(
                is_string($row->tenant_id) ? $row->tenant_id : '', 'trial_ending', 'trial_ending:'.(is_string($row->id) ? $row->id : ''),
                ['until' => Carbon::parse(is_string($row->trial_ends_at) ? $row->trial_ends_at : 'now', 'UTC')->toIso8601String()],
            );
        }

        return $rows->count();
    }

    /**
     * @param  array<string, string>  $data
     */
    public function toOwner(string $tenantId, string $kind, string $dedupeKey, array $data = []): void
    {
        try {
            $db = DB::connection('pgsql_platform');
            $owner = $db->transaction(function (Connection $tx) use ($tenantId): ?stdClass {
                $tx->statement("select set_config('app.tenant_id', ?, true)", [$tenantId]);
                $row = $tx->table('memberships')->join('users', 'users.id', '=', 'memberships.user_id')
                    ->where('memberships.tenant_id', $tenantId)->where('memberships.role', 'owner')->where('memberships.status', 'active')
                    ->orderBy('memberships.created_at')->select('users.email', 'users.name')->first();

                return $row instanceof stdClass ? $row : null;
            });

            if ($owner === null) {
                // Sem dados pessoais no log: só o negócio e o tipo.
                Log::warning('Aviso não enviado: o negócio não tem dona ativa.', ['tenant' => $tenantId, 'kind' => $kind]);

                return;
            }

            $this->deliver($tenantId, is_string($owner->email) ? $owner->email : '', is_string($owner->name) ? $owner->name : '', $kind, $dedupeKey, $data);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Aviso para uma pessoa específica (ex.: troca de senha).
     *
     * @param  array<string, string>  $data
     */
    public function toPerson(string $tenantId, string $email, string $name, string $kind, string $dedupeKey, array $data = []): void
    {
        try {
            $this->deliver($tenantId, $email, $name, $kind, $dedupeKey, $data);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** @param  array<string, string>  $data */
    private function deliver(string $tenantId, string $email, string $name, string $kind, string $dedupeKey, array $data): void
    {
        $db = DB::connection('pgsql_platform');
        $business = $db->table('tenants')->where('id', $tenantId)->first(['name', 'slug', 'timezone']);

        if (! $business instanceof stdClass || $email === '') {
            return;
        }

        $id = (string) Str::uuid();
        $inserted = $db->table('notification_log')->insertOrIgnore([
            'id' => $id, 'tenant_id' => $tenantId, 'kind' => $kind, 'dedupe_key' => $dedupeKey, 'created_at' => now(),
        ]);

        if ($inserted === 0) {
            return;
        }

        $data = $this->localDates($data, is_string($business->timezone) ? $business->timezone : 'UTC');
        $data += ['url' => sprintf('%s://%s.%s', Config::string('tenancy.scheme'), is_string($business->slug) ? $business->slug : '', Config::string('tenancy.web_host'))];

        try {
            Mail::to($email)->queue(new TransactionalMail($kind, $name, is_string($business->name) ? $business->name : '', $data));
        } catch (Throwable $e) {
            // Não saiu: libera a chave para uma nova tentativa e deixa o erro (sem o endereço) no log.
            $db->table('notification_log')->where('id', $id)->delete();

            report(new \RuntimeException('Falha ao enfileirar o aviso '.$kind.' ('.$e::class.').'));
        }
    }

    /** Datas em ISO 8601 (UTC) viram texto no fuso do negócio: `until` com hora só no fim do teste. */
    /**
     * @param  array<string, string>  $data
     * @return array<string, string>
     */
    private function localDates(array $data, string $timezone): array
    {
        foreach (['until' => 'd/m/Y', 'period_end' => 'd/m/Y'] as $key => $format) {
            if (isset($data[$key]) && preg_match('/^\d{4}-\d{2}-\d{2}T/', $data[$key]) === 1) {
                $data[$key] = Carbon::parse($data[$key])->setTimezone($timezone)->format($format);
            }
        }

        return $data;
    }
}
