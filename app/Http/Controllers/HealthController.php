<?php

namespace App\Http\Controllers;

use App\Services\Platform\HealthCheck;
use Illuminate\Http\JsonResponse;

/**
 * Uptime monitor endpoint: 200 when everything runs, 503 otherwise.
 */
class HealthController extends Controller
{
    public function __invoke(HealthCheck $health): JsonResponse
    {
        $result = $health->run();

        return response()->json($result, $result['ok'] ? 200 : 503)->header('Cache-Control', 'no-store');
    }
}
