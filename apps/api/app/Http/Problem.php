<?php

namespace App\Http;

use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;

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

    public static function tooManyRequests(ThrottleRequestsException $exception): JsonResponse
    {
        $retryAfter = $exception->getHeaders()['Retry-After'] ?? null;
        $headers = is_string($retryAfter) || is_int($retryAfter) ? ['Retry-After' => (string) $retryAfter] : [];

        return self::response(429, 'Too Many Requests', null, [], $headers);
    }
}
