<?php

namespace App\Providers;

use App\Tenancy\TenantContext;
use App\TenantAuth\AuthenticatedPerson;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class PlatformServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        RateLimiter::for('public-catalog', fn (Request $request) => Limit::perMinute(60)->by((string) $request->ip()));
        // Por IP e um teto global: um conjunto grande de IPs não cria negócios sem limite.
        RateLimiter::for('signup', fn (Request $request) => [
            Limit::perMinute(10)->by((string) $request->ip()),
            Limit::perHour(300)->by('signup-global'),
        ]);
        // Autenticação do assinante: por endereço e também por negócio + e-mail (cai mesmo trocando de endereço).
        RateLimiter::for('tenant-login', fn (Request $request) => Limit::perMinute(10)->by((string) $request->ip()));
        RateLimiter::for('tenant-login-email', fn (Request $request) => Limit::perMinute(10)->by(app(TenantContext::class)->id().'|'.self::emailKey($request)));
        RateLimiter::for('tenant-verify', fn (Request $request) => Limit::perMinute(20)->by((string) $request->ip()));
        RateLimiter::for('tenant-forgot', fn (Request $request) => [
            Limit::perHour(5)->by(app(TenantContext::class)->id().'|'.self::emailKey($request)),
            Limit::perHour(20)->by((string) $request->ip()),
        ]);
        RateLimiter::for('tenant-reset', fn (Request $request) => Limit::perHour(10)->by((string) $request->ip()));
        RateLimiter::for('subscription-status', fn (Request $request) => Limit::perMinute(60)->by((string) $request->ip()));
        RateLimiter::for('subscription-manage', fn (Request $request) => Limit::perMinute(30)->by((string) $request->ip()));
        RateLimiter::for('team-invite', fn (Request $request) => [
            Limit::perHour(30)->by(app(TenantContext::class)->id().'|'.self::personKey($request)),
            Limit::perHour(60)->by('invite-tenant:'.app(TenantContext::class)->id()),
            // Por destinatário, entre todos os negócios: o convite não serve para encher a caixa de ninguém.
            Limit::perDay(3)->by('invite-to:'.self::emailKey($request)),
        ]);
        RateLimiter::for('invitation-accept', fn (Request $request) => [
            Limit::perMinute(10)->by((string) $request->ip()),
            Limit::perHour(20)->by(app(TenantContext::class)->id().'|'.self::emailKey($request).'|'.$request->ip()),
        ]);
        RateLimiter::for('coupon-check', fn (Request $request) => [
            Limit::perMinute(20)->by((string) $request->ip()),
            Limit::perHour(100)->by((string) $request->ip()),
            Limit::perHour(600)->by('coupon-check-global'),
        ]);
        RateLimiter::for('tenant-sensitive', fn (Request $request) => Limit::perHour(10)->by(app(TenantContext::class)->id().'|'.self::personKey($request)));
        RateLimiter::for('slug-check', fn (Request $request) => Limit::perMinute(60)->by((string) $request->ip()));
        RateLimiter::for('platform-login', fn (Request $request) => Limit::perMinute(10)->by((string) $request->ip()));
        RateLimiter::for('platform-verify', fn (Request $request) => Limit::perMinute(20)->by((string) $request->ip()));
    }

    /** Pessoa autenticada da requisição, para chave de limite. */
    private static function personKey(Request $request): string
    {
        $person = $request->attributes->get('tenant_person');

        return $person instanceof AuthenticatedPerson ? $person->id : '';
    }

    /** E-mail normalizado para chave de limite; um valor que não é texto (ex.: lista) vira vazio, a validação o recusa depois. */
    private static function emailKey(Request $request): string
    {
        $email = $request->input('email');

        return is_string($email) ? mb_strtolower($email) : '';
    }
}
