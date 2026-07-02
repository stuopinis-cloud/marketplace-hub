<?php

namespace App\Http\Controllers;

use App\Services\Deployment\MarketplaceHealthChecker;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function __invoke(MarketplaceHealthChecker $healthChecker): JsonResponse
    {
        $payload = $healthChecker->publicStatus();
        $statusCode = $healthChecker->isHealthy() ? 200 : 503;

        return response()->json($payload, $statusCode);
    }
}
