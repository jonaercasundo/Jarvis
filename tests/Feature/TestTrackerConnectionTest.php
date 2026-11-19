<?php

namespace Tests\Feature;

use App\Models\ApiConnection;
use App\Services\WorkflowHttpService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TestTrackerConnectionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_command_uses_saved_authentication_but_prints_only_diagnostics(): void
    {
        $connection = $this->connection();
        $this->fakeDns();
        Http::preventStrayRequests();
        Http::fake([
            'https://tracker.metro-mobilia.com/api/jarvis/test' => Http::response(['message' => 'hidden-response-record']),
            'https://tracker.metro-mobilia.com/api/jarvis/projects' => Http::response(['data' => []]),
        ]);

        $exitCode = Artisan::call('jarvis:test-connection', ['connection' => $connection->id]);
        $output = Artisan::output();
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"status":200', $output);
        $this->assertStringContainsString('/api/jarvis/projects', $output);
        $this->assertStringNotContainsString('command-secret', $output);
        $this->assertStringNotContainsString('hidden-response-record', $output);
        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && $request->hasHeader('Authorization', 'Bearer command-secret'));
        Http::assertSentCount(2);
    }

    public function test_command_reports_the_selected_timeout_and_continues_to_project_check(): void
    {
        $connection = $this->connection();
        $this->fakeDns();
        Http::preventStrayRequests();
        Http::fake([
            'https://tracker.metro-mobilia.com/api/jarvis/test' => function (): never {
                throw new ConnectionException('cURL error 28: Operation timed out');
            },
            'https://tracker.metro-mobilia.com/api/jarvis/projects' => Http::response(['data' => []]),
        ]);

        $exitCode = Artisan::call('jarvis:test-connection', ['connection' => $connection->id, '--timeout' => 30]);
        $output = Artisan::output();
        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('"error_type":"timeout"', $output);
        $this->assertStringContainsString('"total_timeout":30', $output);
        $this->assertStringContainsString('/api/jarvis/projects', $output);
        $this->assertStringNotContainsString('command-secret', $output);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://tracker.metro-mobilia.com/api/jarvis/projects');
    }

    public function test_command_rejects_other_services_before_sending_credentials(): void
    {
        $connection = ApiConnection::factory()->create();
        Http::preventStrayRequests();

        $this->artisan('jarvis:test-connection', ['connection' => $connection->id])->assertFailed();

        Http::assertNothingSent();
    }

    private function connection(): ApiConnection
    {
        return ApiConnection::factory()->create(['name' => 'MMC Tracker', 'base_url' => 'https://tracker.metro-mobilia.com', 'token' => 'command-secret']);
    }

    private function fakeDns(): void
    {
        $dns = \Mockery::mock(WorkflowHttpService::class);
        foreach (['test', 'projects'] as $path) {
            $url = 'https://tracker.metro-mobilia.com/api/jarvis/'.$path;
            $dns->shouldReceive('validateUrl')->once()->with($url);
            $dns->shouldReceive('requestOptions')->once()->with($url)->andReturn(['allow_redirects' => false]);
        }
        $this->app->instance(WorkflowHttpService::class, $dns);
    }
}
