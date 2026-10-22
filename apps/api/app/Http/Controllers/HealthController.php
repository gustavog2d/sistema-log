<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn () => DB::select('select 1')),
            'redis' => $this->check(fn () => Redis::ping()),
        ];

        if (in_array('fail', $checks, true)) {
            return response()->json([
                'type' => 'about:blank',
                'title' => 'Service Unavailable',
                'status' => 503,
                'detail' => 'One or more dependencies are unavailable.',
                'checks' => $checks,
            ], 503, ['Content-Type' => 'application/problem+json']);
        }

        return response()->json(['status' => 'ok', 'checks' => $checks]);
    }

    private function check(callable $probe): string
    {
        try {
            $probe();

            return 'ok';
        } catch (Throwable) {
            return 'fail';
        }
    }
}
