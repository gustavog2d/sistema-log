<?php

namespace App\Notifications;

use Illuminate\Database\Connection;
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
    /** Evento de assinatura → tipo de aviso. */
    private const BY_EVENT = [
        'billing.charged' => 'payment_succeeded',
        'subscription.suspended' => 'subscription_suspended',
        'subscription.regularized' => 'subscription_regularized',
        'subscription.cancel_requested' => 'cancel_requested',
        'subscription.canceled' => 'subscription_canceled',
    ];

    /**
     * Avisos que nascem dos eventos de cobrança e de gestão da assinatura (mesmo contexto da auditoria).
     *
     * @param  array<string, scalar>  $context  `tenant`, `subscription`, `mark` (marco que identifica o acontecimento) e dados de texto
     */
    public function forSubscriptionEvent(string $event, array $context): void
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

        $data = [];

        foreach (['amount', 'reason', 'until', 'period_end'] as $key) {
            if (isset($context[$key])) {
                $data[$key] = (string) $context[$key];
            }
        }

        $this->toOwner($tenant, $kind, $kind.':'.($context['subscription'] ?? '').':'.($context['mark'] ?? ''), $data);
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
                    ->where('memberships.role', 'owner')->where('memberships.status', 'active')
                    ->select('users.email', 'users.name')->first();

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
        $business = $db->table('tenants')->where('id', $tenantId)->first(['name', 'slug']);

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

        $data += ['url' => sprintf('%s://%s.%s', parse_url(Config::string('app.url'), PHP_URL_SCHEME) ?: 'https', is_string($business->slug) ? $business->slug : '', Config::string('tenancy.base_domain'))];

        try {
            Mail::to($email)->queue(new TransactionalMail($kind, $name, is_string($business->name) ? $business->name : '', $data));
        } catch (Throwable $e) {
            // Não saiu: libera a chave para uma nova tentativa e deixa o erro (sem o endereço) no log.
            $db->table('notification_log')->where('id', $id)->delete();

            report(new \RuntimeException('Falha ao enfileirar o aviso '.$kind.' ('.$e::class.').'));
        }
    }
}
