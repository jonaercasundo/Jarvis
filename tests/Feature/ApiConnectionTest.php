<?php

namespace Tests\Feature;

use App\Models\ApiConnection;
use App\Models\User;
use GuzzleHttp\Handler\CurlFactory;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ApiConnectionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_unsaved_connection_sends_bearer_header_and_returns_only_safe_result(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://93.184.215.14/api/jarvis/test' => Http::response([
            'success' => true, 'message' => 'JARVIS connected to MMC Tracker', 'token' => 'test-secret',
        ])]);

        $this->postJson('/jarvis/settings/connections/test', $this->attributes())
            ->assertOk()->assertExactJson(['success' => true, 'status' => 200, 'message' => 'JARVIS connected to MMC Tracker']);

        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && $request->hasHeader('Authorization', 'Bearer test-secret')
            && $request->hasHeader('Accept', 'application/json'));
        $this->assertDatabaseCount('api_connections', 0);
    }

    public function test_saved_connection_uses_stored_token_without_accepting_target_overrides(): void
    {
        $user = User::factory()->create();
        $connection = ApiConnection::factory()->create(['owner_key' => 'user:'.$user->id, 'token' => 'stored-secret']);
        Http::preventStrayRequests();
        Http::fake(['https://93.184.215.14/health' => Http::response(['message' => 'Bearer stored-secret'])]);

        $this->actingAs($user)->postJson('/jarvis/settings/connections/'.$connection->id.'/test', [
            'test_path' => '/health', 'token' => '', 'base_url' => 'https://8.8.8.8',
        ])->assertOk()->assertExactJson(['success' => true, 'status' => 200, 'message' => 'Connection successful.']);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://93.184.215.14/health'
            && $request->hasHeader('Authorization', 'Bearer stored-secret'));
        $this->assertSame('stored-secret', $connection->fresh()->token);
        $this->get('/jarvis/settings/connections')->assertSeeText('Test connection')->assertDontSee('stored-secret');
    }

    public function test_other_owner_cannot_test_connection(): void
    {
        $connection = ApiConnection::factory()->create(['owner_key' => 'session:other-owner']);
        Http::preventStrayRequests();

        $this->actingAs(User::factory()->create())->postJson('/jarvis/settings/connections/'.$connection->id.'/test')
            ->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_public_unauthenticated_api_keeps_dns_pinning_redirect_block_and_timeouts(): void
    {
        Http::preventStrayRequests();
        Http::fake(function ($request, array $options) {
            $this->assertFalse($request->hasHeader('Authorization'));
            $this->assertFalse($options['allow_redirects']);
            $this->assertSame(5, $options['connect_timeout']);
            $this->assertSame(12, $options['timeout']);
            $this->assertSame('', $options['proxy']);
            $this->assertSame(['93.184.215.14:443:93.184.215.14'], $options['curl'][CURLOPT_RESOLVE]);
            $factory = new CurlFactory(0);
            $handle = $factory->create($request->toPsrRequest(), $options);
            $factory->release($handle);

            return Http::response('', 204);
        });

        $this->postJson('/jarvis/settings/connections/test', [...$this->attributes(), 'authentication' => 'none', 'token' => null])
            ->assertOk()->assertJsonPath('success', true)->assertJsonPath('status', 204);
    }

    #[DataProvider('remoteFailures')]
    public function test_remote_failures_return_controlled_messages(int $status, string $message): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['message' => 'Bearer test-secret'], $status, ['Location' => 'https://127.0.0.1'])]);

        $this->postJson('/jarvis/settings/connections/test', $this->attributes())
            ->assertStatus($status === 302 ? 502 : $status)
            ->assertExactJson(['success' => false, 'status' => $status, 'message' => $message]);

        Http::assertSentCount(1);
    }

    public static function remoteFailures(): array
    {
        return [
            'authentication' => [401, 'Authentication failed. Check the Bearer token.'],
            'permission' => [403, 'Connected but permission denied.'],
            'missing endpoint' => [404, 'Test endpoint not found.'],
            'configuration' => [422, 'Invalid connection configuration.'],
            'rate limit' => [429, 'Remote API rate limited. Try again later.'],
            'server error' => [500, 'Remote API error.'],
            'unavailable' => [503, 'Remote API error.'],
            'redirect' => [302, 'Remote API redirected the request. Redirects are blocked.'],
        ];
    }

    #[DataProvider('invalidConfigurations')]
    public function test_invalid_configuration_returns_422_without_outbound_request(array $overrides): void
    {
        Http::preventStrayRequests();

        $this->postJson('/jarvis/settings/connections/test', array_replace($this->attributes(), $overrides))
            ->assertUnprocessable()->assertJsonPath('success', false)->assertDontSee('test-secret');

        Http::assertNothingSent();
    }

    public static function invalidConfigurations(): array
    {
        return [
            'localhost' => [['base_url' => 'https://127.0.0.1']],
            'private' => [['base_url' => 'https://10.0.0.1']],
            'metadata' => [['base_url' => 'https://169.254.169.254']],
            'http' => [['base_url' => 'http://93.184.215.14']],
            'userinfo' => [['base_url' => 'https://user:pass@93.184.215.14']],
            'query' => [['base_url' => 'https://93.184.215.14?token=test-secret']],
            'missing bearer' => [['token' => '']],
            'header injection' => [['token' => "test-secret\r\nHost: localhost"]],
            'absolute path' => [['test_path' => 'https://8.8.8.8/test']],
            'protocol relative' => [['test_path' => '//8.8.8.8/test']],
            'backslash' => [['test_path' => '/\\8.8.8.8/test']],
        ];
    }

    public function test_connection_failure_does_not_expose_exception(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::failedConnection('Bearer test-secret')]);

        $this->postJson('/jarvis/settings/connections/test', $this->attributes())
            ->assertStatus(502)->assertExactJson(['success' => false, 'status' => null,
                'message' => 'Remote server did not respond (timeout or connection failure).']);
    }

    public function test_an_api_success_status_with_explicit_failure_is_not_successful(): void
    {
        Http::fake(['*' => Http::response(['success' => false, 'message' => 'test-secret'])]);

        $this->postJson('/jarvis/settings/connections/test', $this->attributes())
            ->assertStatus(502)->assertJsonPath('success', false)->assertDontSee('test-secret');
    }

    public function test_blank_token_update_preserves_existing_token(): void
    {
        $user = User::factory()->create();
        $connection = ApiConnection::factory()->create(['owner_key' => 'user:'.$user->id, 'token' => 'stored-secret']);

        $this->actingAs($user)->putJson('/jarvis/settings/connections/'.$connection->id, [...$this->attributes(), 'token' => ''])
            ->assertOk()->assertDontSee('stored-secret');

        $this->assertSame('stored-secret', $connection->fresh()->token);
    }

    /** @return array{name: string, base_url: string, authentication: string, token: string} */
    private function attributes(): array
    {
        return ['name' => 'MMC Tracker', 'base_url' => 'https://93.184.215.14', 'authentication' => 'bearer', 'token' => 'test-secret'];
    }
}
