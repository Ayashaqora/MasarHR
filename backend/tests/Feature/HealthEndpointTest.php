<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    public function test_health_endpoint_reports_ok_under_the_v1_prefix(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response
            ->assertOk()
            ->assertExactJsonStructure(['status', 'service', 'version', 'timestamp'])
            ->assertJson([
                'status' => 'ok',
                'service' => 'masar-hr-api',
                'version' => 'v1',
            ]);
    }

    public function test_health_endpoint_does_not_leak_configuration_or_secrets(): void
    {
        $body = strtolower($this->getJson('/api/v1/health')->getContent());

        foreach (['password', 'secret', 'key', 'token', 'database', 'redis', 'host'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body);
        }
    }

    public function test_unprefixed_health_path_is_not_exposed(): void
    {
        $this->getJson('/health')->assertNotFound();
        $this->getJson('/api/health')->assertNotFound();
    }

    public function test_unknown_api_routes_return_json_errors(): void
    {
        $this->get('/api/v1/does-not-exist', ['Accept' => 'text/html'])
            ->assertNotFound()
            ->assertJsonStructure(['message']);
    }
}
