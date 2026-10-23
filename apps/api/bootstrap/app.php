<?php

use App\Http\Middleware\AuthenticateTenantUser;
use App\Http\Middleware\EnsureOwner;
use App\Http\Middleware\EnsurePlatformRole;
use App\Http\Middleware\EnsurePlatformUserActive;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\EnsureSubscriptionAllowsWrites;
use App\Http\Middleware\IdempotentRequest;
use App\Http\Middleware\PlatformOnly;
use App\Http\Middleware\PublicRoute;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SubscriptionExempt;
use App\Http\Problem;
use App\Platform\Auth\AccountLocked;
use App\Platform\Auth\InvalidCredentials;
use App\TenantAuth\AccountIsLocked;
use App\TenantAuth\LoginFailed;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Exceptions\MissingAbilityException;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // API sem redirecionamentos: quem não está autenticado recebe 401, nunca um redirect para /login.
        $middleware->redirectGuestsTo(fn () => null);

        // O host da plataforma é checado antes da autenticação: em host de assinante a rota nem existe (404).
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: PlatformOnly::class);

        $middleware->alias([
            'tenant' => ResolveTenant::class,
            'platform' => PlatformOnly::class,
            'public' => PublicRoute::class,
            'abilities' => CheckAbilities::class,
            'platform.active' => EnsurePlatformUserActive::class,
            'platform.role' => EnsurePlatformRole::class,
            'idempotent' => IdempotentRequest::class,
            'subscription.access' => EnsureSubscriptionAllowsWrites::class,
            'subscription.exempt' => SubscriptionExempt::class,
            'auth.tenant' => AuthenticateTenantUser::class,
            'owner' => EnsureOwner::class,
            'role' => EnsureRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Falhas de login são esperadas e a pilha de chamadas carregaria senha e códigos: não vão para o log.
        $exceptions->dontReport([InvalidCredentials::class, AccountLocked::class, LoginFailed::class, AccountIsLocked::class]);

        // Erros da API em Problem Details (RFC 9457).
        $isApi = fn (Request $request): bool => $request->is('api/*');
        $exceptions->render(fn (InvalidCredentials $e, Request $r) => $isApi($r) ? Problem::response(401, 'Unauthorized', 'Credenciais inválidas.') : null);
        $exceptions->render(fn (LoginFailed $e, Request $r) => $isApi($r) ? Problem::response(401, 'Unauthorized', 'Credenciais inválidas.') : null);
        $exceptions->render(fn (AccountIsLocked $e, Request $r) => $isApi($r) ? Problem::response(423, 'Locked', 'Conta temporariamente bloqueada.') : null);
        $exceptions->render(fn (AccountLocked $e, Request $r) => $isApi($r) ? Problem::response(423, 'Locked', 'Conta temporariamente bloqueada.') : null);
        $exceptions->render(fn (AuthenticationException $e, Request $r) => $isApi($r) ? Problem::response(401, 'Unauthorized', 'Autenticação necessária.') : null);
        $exceptions->render(fn (MissingAbilityException $e, Request $r) => $isApi($r) ? Problem::response(403, 'Forbidden', 'Este token não permite a operação.') : null);
        $exceptions->render(fn (AuthorizationException $e, Request $r) => $isApi($r) ? Problem::response(403, 'Forbidden', 'Acesso negado.') : null);
        $exceptions->render(fn (ValidationException $e, Request $r) => $isApi($r) ? Problem::response(422, 'Unprocessable Entity', 'Dados inválidos.', ['errors' => $e->errors()]) : null);
        $exceptions->render(fn (ThrottleRequestsException $e, Request $r) => $isApi($r) ? Problem::tooManyRequests($e) : null);
        $exceptions->render(fn (HttpExceptionInterface $e, Request $r) => $isApi($r) ? Problem::fromHttpException($e) : null);

        // Erro inesperado: sem detalhes (SQL, caminhos, pilha) fora do modo de depuração.
        $exceptions->render(fn (Throwable $e, Request $r) => $isApi($r) && ! config('app.debug') && ! $e instanceof HttpExceptionInterface
            ? Problem::response(500, 'Internal Server Error')
            : null);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
