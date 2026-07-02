<?php

namespace Tests\Feature;

use App\Models\SyncJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_endpoint_returns_ok_json(): void
    {
        $response = $this->getJson('/health');

        $response
            ->assertOk()
            ->assertJson([
                'status' => 'ok',
                'app' => 'Marketplace Hub',
                'database' => 'ok',
            ])
            ->assertJsonStructure([
                'status',
                'app',
                'time',
                'database',
            ]);
    }
}
