<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_health_endpoint_responds(): void
    {
        $response = $this->getJson('/api/health');
        $response->assertStatus(200)->assertJson(['status' => 'ok']);
    }
}
