<?php

namespace App\Http;

use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Respostas de erro no formato Problem Details (RFC 9457). */
final class Problem
{
    /**
     * @param  array<string, mixed>  $extensions  Campos extras permitidos pelo RFC (ex.: `errors`)
     * @param  array<string, string>  $headers
     */
    public static function response(
        int $status,
        string $title,
        ?string $detail = null,
        array $extensions = [],
        array $headers = [],
    ): JsonResponse {
        return response()->json(
            array_filter([
                'type' => 'about:blank',
                'title' => $title,
                'status' => $status,
                'detail' => $detail,
                ...$extensions,
            ], fn ($value) => $value !== null),
            $status,
            ['Content-Type' => 'application/problem+json', ...$headers],
        );
    }

    /** 403 e 404 de rotas da API, sem expor a mensagem interna da exceção. */
    public static function fromHttpException(HttpExceptionInterface $exception): ?JsonResponse
    {
        return match ($exception->getStatusCode()) {
            403 => self::response(403, 'Forbidden', 'Acesso negado.'),
            404 => self::response(404, 'Not Found'),
            default => null,
        };
    }

    public static function tooManyRequests(ThrottleRequestsException $exception): JsonResponse
    {
        $retryAfter = $exception->getHeaders()['Retry-After'] ?? null;
        $headers = is_string($retryAfter) || is_int($retryAfter) ? ['Retry-After' => (string) $retryAfter] : [];

        return self::response(429, 'Too Many Requests', null, [], $headers);
    }
}
