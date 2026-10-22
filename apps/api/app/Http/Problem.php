<?php

namespace App\Http;

use Illuminate\Http\JsonResponse;

/** Respostas de erro no formato Problem Details (RFC 9457). */
final class Problem
{
    public static function response(int $status, string $title, ?string $detail = null): JsonResponse
    {
        return response()->json(
            array_filter([
                'type' => 'about:blank',
                'title' => $title,
                'status' => $status,
                'detail' => $detail,
            ], fn ($value) => $value !== null),
            $status,
            ['Content-Type' => 'application/problem+json'],
        );
    }
}
