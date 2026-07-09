<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [];

        try {
            DB::select('SELECT 1');
            $checks['database'] = 'healthy';
        } catch (\Exception $e) {
            $checks['database'] = 'unhealthy';
        }

        try {
            Cache::store('redis')->set('health:ping', true, 10);
            $checks['redis'] = 'healthy';
        } catch (\Exception $e) {
            $checks['redis'] = 'unhealthy';
        }

        $key = config('services.kuaforia.api_key');
        $checks['kuaforia'] = $key ? 'configured' : 'missing_key';

        $healthy = $checks['database'] === 'healthy' && $checks['redis'] === 'healthy';

        return response()->json([
            'status' => $healthy ? 'healthy' : 'degraded',
            'checks' => $checks,
            'timestamp' => now()->toIso8601String(),
        ], $healthy ? 200 : 503);
    }
}
