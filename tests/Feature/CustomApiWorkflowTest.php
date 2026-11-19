<?php

namespace Tests\Feature;

use App\Exceptions\WorkflowHttpException;
use App\Models\ApiConnection;
use App\Models\JarvisWorkflow;
use App\Models\User;
use App\Services\Workflow\CustomApiWorkflowExecutor;
use App\Services\WorkflowExecutor;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustomApiWorkflowTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_saved_connection_is_encrypted_and_never_serialized(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/jarvis/settings/connections', [
            'name' => 'MMC Tracker', 'base_url' => 'https://93.184.215.14',
            'authentication' => 'bearer', 'token' => 'test-only-secret',
        ]);

        $response->assertCreated()->assertJsonPath('connection.name', 'MMC Tracker')->assertJsonMissingPath('connection.token');
        $connection = ApiConnection::query()->findOrFail($response->json('connection.id'));
        $this->assertSame('test-only-secret', $connection->token);
        $this->assertNotSame('test-only-secret', DB::table('api_connections')->value('token'));
        $this->getJson('/jarvis/workflows/connections')->assertJsonPath('api_connections.0.id', $connection->id)->assertDontSee('test-only-secret');
        $this->get('/jarvis/settings/connections')->assertSeeText('MMC Tracker')->assertDontSee('test-only-secret');
    }

    public function test_test_step_injects_authentication_resolves_relative_url_and_redacts_echoed_secrets(): void
    {
        $user = User::factory()->create();
        $connection = ApiConnection::factory()->create(['owner_key' => 'user:'.$user->id, 'token' => 'test-only-secret']);
        Http::preventStrayRequests();
        Http::fake(['https://93.184.215.14/api/jarvis/test*' => Http::response([
            'success' => true, 'message' => 'JARVIS connected to MMC Tracker',
            'headers' => ['Authorization' => 'Bearer test-only-secret'], 'echo' => 'test-only-secret',
        ])]);

        $response = $this->actingAs($user)->postJson('/jarvis/workflows/test-step', ['node' => $this->node($connection->id)]);

        $response->assertOk()->assertJsonPath('status', 200)->assertJsonPath('response.message', 'JARVIS connected to MMC Tracker')
            ->assertJsonPath('response.headers.Authorization', '[REDACTED]')->assertDontSee('test-only-secret');
        Http::assertSent(fn ($request): bool => $request->url() === 'https://93.184.215.14/api/jarvis/test'
            && $request->hasHeader('Authorization', 'Bearer test-only-secret') && $request->hasHeader('Accept', 'application/json'));
    }

    public function test_dynamic_values_work_in_url_query_headers_and_json_with_quotes_and_types(): void
    {
        $user = User::factory()->create();
        $connection = ApiConnection::factory()->create(['owner_key' => 'user:'.$user->id]);
        $node = $this->node($connection->id, [
            'method' => 'POST', 'url' => '/projects/{{operations_agent.project_id}}',
            'query' => 'status={{operations_agent.status}}', 'headers' => 'X-Project: {{operations_agent.project_id}}',
            'body' => '{"name":"{{operations_agent.name}}","quantity":"{{operations_agent.quantity}}"}',
        ]);
        Http::preventStrayRequests();
        Http::fake(['https://93.184.215.14/projects/7*' => Http::response(['data' => ['id' => 7]])]);

        $this->actingAs($user)->postJson('/jarvis/workflows/test-step', [
            'node' => $node, 'context' => ['operations_agent' => ['project_id' => 7, 'status' => 'open', 'name' => 'A "quoted" project', 'quantity' => 3]],
        ])->assertOk();

        Http::assertSent(fn ($request): bool => $request->url() === 'https://93.184.215.14/projects/7?status=open'
            && $request->hasHeader('X-Project', '7') && $request['name'] === 'A "quoted" project' && $request['quantity'] === 3);
    }

    public function test_multiple_response_paths_preserve_nested_shape_and_drop_other_fields(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://93.184.215.14/api/jarvis/test' => Http::response(['data' => ['projects' => [1], 'deliveries' => [2]], 'meta' => ['page' => 1], 'unused' => true])]);

        $result = app(CustomApiWorkflowExecutor::class)->execute($this->node(null, [
            'url' => 'https://93.184.215.14/api/jarvis/test', 'response_paths' => "data.projects\nmeta",
        ]));

        $this->assertSame(['data' => ['projects' => [1]], 'meta' => ['page' => 1]], $result['response']);
        Http::assertSentCount(1);
    }

    public function test_url_query_is_preserved_and_safe_diagnostics_match_the_actual_request(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://93.184.215.14/test*' => Http::response(['data' => []])]);

        $result = app(CustomApiWorkflowExecutor::class)->execute($this->node(null, [
            'url' => 'https://93.184.215.14/test?search=SME%202&password=hidden-secret',
        ]));

        Http::assertSent(fn ($request): bool => $request->url() === 'https://93.184.215.14/test?search=SME%202&password=hidden-secret');
        $this->assertSame(['search' => 'SME 2'], $result['diagnostic']['query']);
        $this->assertSame('https://93.184.215.14/test', $result['diagnostic']['url']);
        $this->assertStringNotContainsString('hidden-secret', json_encode($result['diagnostic']));
        $this->assertTrue($result['diagnostic']['request_options']['verify']);
    }

    public function test_cross_owner_connection_cannot_be_read_deleted_or_executed(): void
    {
        $user = User::factory()->create();
        $connection = ApiConnection::factory()->create(['owner_key' => 'session:another-owner']);
        Http::preventStrayRequests();

        $this->actingAs($user)->getJson('/jarvis/settings/connections')->assertJsonPath('connections', []);
        $this->deleteJson('/jarvis/settings/connections/'.$connection->id)->assertNotFound();
        $this->putJson('/jarvis/settings/connections/'.$connection->id, [])->assertNotFound();
        $this->postJson('/jarvis/workflows/test-step', ['node' => $this->node($connection->id)])
            ->assertUnprocessable()->assertJsonPath('error', 'The saved API connection is unavailable.');

        $this->assertModelExists($connection);
        Http::assertNothingSent();
    }

    #[DataProvider('unsafeRequests')]
    public function test_unsafe_urls_headers_and_json_are_rejected_before_requests(array $values): void
    {
        Http::preventStrayRequests();

        $this->postJson('/jarvis/workflows/test-step', ['node' => $this->node(null, $values)])->assertUnprocessable();

        Http::assertNothingSent();
    }

    public static function unsafeRequests(): array
    {
        return [
            'private' => [['url' => 'https://127.0.0.1/test']],
            'metadata' => [['url' => 'https://169.254.169.254/test']],
            'multicast' => [['url' => 'https://224.0.0.1/test']],
            'http' => [['url' => 'http://93.184.215.14/test']],
            'userinfo' => [['url' => 'https://user:pass@93.184.215.14/test']],
            'protocol relative' => [['url' => '//93.184.215.14/test']],
            'authorization' => [['url' => 'https://93.184.215.14/test', 'headers' => 'authorization: Bearer unsafe']],
            'host' => [['url' => 'https://93.184.215.14/test', 'headers' => 'Host: localhost']],
            'invalid json' => [['url' => 'https://93.184.215.14/test', 'body' => '{broken']],
        ];
    }

    public function test_authenticated_connection_cannot_send_token_to_another_origin(): void
    {
        $user = User::factory()->create();
        $connection = ApiConnection::factory()->create(['owner_key' => 'user:'.$user->id]);
        Http::preventStrayRequests();

        $this->actingAs($user)->postJson('/jarvis/workflows/test-step', ['node' => $this->node($connection->id, ['url' => 'https://8.8.8.8/test'])])
            ->assertUnprocessable()->assertJsonPath('error', 'The API endpoint must use the saved connection base URL origin.');

        Http::assertNothingSent();
    }

    #[DataProvider('remoteErrors')]
    public function test_remote_errors_are_clear_and_do_not_expose_response_secrets(int $status, string $message): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://93.184.215.14/test' => Http::response(['token' => 'remote-secret'], $status)]);

        $this->postJson('/jarvis/workflows/test-step', ['node' => $this->node(null, ['url' => 'https://93.184.215.14/test'])])
            ->assertUnprocessable()->assertJsonPath('error', $status.' - '.$message)->assertDontSee('remote-secret');

        Http::assertSentCount(1);
    }

    public static function remoteErrors(): array
    {
        return [[401, 'Authentication failed'], [403, 'Permission denied'], [404, 'Endpoint not found'],
            [422, 'Validation failed'], [429, 'Rate limited'], [500, 'Remote API error'], [503, 'Remote API error'], [302, 'API request failed']];
    }

    public function test_connection_failure_is_safe_and_clear(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://93.184.215.14/test' => Http::failedConnection()]);

        $this->postJson('/jarvis/workflows/test-step', ['node' => $this->node(null, ['url' => 'https://93.184.215.14/test'])])
            ->assertUnprocessable()->assertJsonPath('error', 'API host could not be resolved.');
    }

    public function test_timeout_diagnostics_are_specific_and_never_log_tokens_or_queries(): void
    {
        $connection = ApiConnection::factory()->create(['owner_key' => 'session:test', 'token' => 'test-only-secret']);
        Http::preventStrayRequests();
        Http::fake(['https://93.184.215.14/test*' => function (): never {
            throw new ConnectionException('cURL error 28: Operation timed out; Bearer test-only-secret for https://93.184.215.14/test?password=query-secret');
        }]);
        Log::spy();

        try {
            app(CustomApiWorkflowExecutor::class)->execute([
                ...$this->node($connection->id, ['url' => '/test', 'query' => ['password' => 'query-secret']]),
                '_owner_key' => 'session:test',
            ]);
            $this->fail('Expected a transport exception.');
        } catch (WorkflowHttpException $exception) {
            $this->assertSame('timeout', $exception->details['error_type']);
            $this->assertSame(28, $exception->details['curl_errno']);
            $this->assertSame('/test', $exception->details['endpoint']);
            $this->assertSame('API request timed out after 12 seconds.', $exception->getMessage());
            $this->assertStringNotContainsString('test-only-secret', json_encode($exception->getTrace()));
        }

        Log::shouldHaveReceived('warning')->once()->with('Workflow API transport failure', \Mockery::on(function (array $context): bool {
            $encoded = json_encode($context);

            return $context['exception_class'] === ConnectionException::class
                && $context['connect_timeout'] === 5 && $context['total_timeout'] === 12
                && isset($context['elapsed_seconds'], $context['exception_file'], $context['exception_line'])
                && ! str_contains($encoded, 'test-only-secret') && ! str_contains($encoded, 'query-secret');
        }));
    }

    #[DataProvider('transportFailures')]
    public function test_transport_failures_preserve_the_observed_error_category(string $message, string $expectedType, ?int $expectedErrno): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://93.184.215.14/test' => function () use ($message): never {
            throw new ConnectionException($message);
        }]);

        try {
            app(CustomApiWorkflowExecutor::class)->execute($this->node(null, ['url' => 'https://93.184.215.14/test']));
            $this->fail('Expected a transport exception.');
        } catch (WorkflowHttpException $exception) {
            $this->assertSame($expectedType, $exception->details['error_type']);
            $this->assertSame($expectedErrno, $exception->details['curl_errno']);
        }
    }

    public static function transportFailures(): array
    {
        return [
            'dns' => ['cURL error 6: Could not resolve host', 'dns', 6],
            'connection' => ['cURL error 7: Could not connect to server', 'connection', 7],
            'certificate' => ['cURL error 60: SSL certificate problem', 'tls', 60],
            'unknown connection' => ['Connection interrupted', 'connection', null],
        ];
    }

    public function test_workflow_stores_only_connection_reference_and_live_execution_uses_it(): void
    {
        $user = User::factory()->create();
        $connection = ApiConnection::factory()->create(['owner_key' => 'user:'.$user->id, 'token' => 'test-only-secret']);
        $node = $this->node($connection->id);
        $node['position'] = ['x' => 200, 'y' => 0];
        $node['id'] = 'api';
        $node['type'] = 'app_action';
        $graph = ['nodes' => [['id' => 'trigger', 'type' => 'schedule_trigger', 'position' => ['x' => 0, 'y' => 0], 'config' => []], $node],
            'edges' => [['source' => 'trigger', 'target' => 'api']]];
        $saved = $this->actingAs($user)->postJson('/jarvis/workflows', ['name' => 'Generic API', 'workflow_data' => $graph]);
        $saved->assertCreated()->assertDontSee('test-only-secret');
        $workflow = JarvisWorkflow::query()->findOrFail($saved->json('workflow.id'));
        Http::preventStrayRequests();
        Http::fake(['https://93.184.215.14/api/jarvis/test' => Http::response(['success' => true])]);

        $result = app(WorkflowExecutor::class)->run($workflow, [], 'schedule');

        $this->assertTrue($result['success']);
        $this->assertSame($connection->id, $workflow->workflow_data['nodes'][1]['config']['values']['connection_id']);
        $this->assertStringNotContainsString('test-only-secret', $workflow->executions()->first()->toJson());
        Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer test-only-secret'));
    }

    public function test_dry_run_does_not_send_real_requests(): void
    {
        Http::preventStrayRequests();

        $result = app(CustomApiWorkflowExecutor::class)->execute($this->node(null, ['url' => 'https://93.184.215.14/test']), true);

        $this->assertTrue($result['simulated']);
        Http::assertNothingSent();
    }

    public function test_updating_connection_keeps_reference_and_rotates_token_securely(): void
    {
        $user = User::factory()->create();
        $connection = ApiConnection::factory()->create(['owner_key' => 'user:'.$user->id, 'token' => 'original-secret']);
        $attributes = ['name' => 'Renamed API', 'base_url' => $connection->base_url, 'authentication' => 'bearer'];

        $this->actingAs($user)->putJson('/jarvis/settings/connections/'.$connection->id, $attributes)
            ->assertOk()->assertJsonPath('connection.id', $connection->id)->assertJsonMissingPath('connection.token');
        $this->assertSame('original-secret', $connection->fresh()->token);

        $this->putJson('/jarvis/settings/connections/'.$connection->id, [...$attributes, 'token' => 'rotated-secret'])
            ->assertOk()->assertDontSee('rotated-secret');
        $this->assertSame('rotated-secret', $connection->fresh()->token);
        $this->assertNotSame('rotated-secret', DB::table('api_connections')->where('id', $connection->id)->value('token'));
    }

    public function test_failed_native_form_never_flashes_token_back_to_browser(): void
    {
        $this->from('/jarvis/settings/connections')->post('/jarvis/settings/connections', [
            'name' => '', 'base_url' => 'https://93.184.215.14', 'authentication' => 'bearer', 'token' => 'never-flash-secret',
        ])->assertRedirect('/jarvis/settings/connections')->assertSessionHasErrors('name');

        $this->assertNull(session('_old_input.token'));
    }

    public function test_workflow_save_rejects_credentials_inside_header_and_json_strings(): void
    {
        $node = $this->node(null, ['url' => 'https://93.184.215.14/test', 'headers' => 'Authorization: Bearer do-not-save']);
        $node += ['id' => 'api', 'type' => 'app_action', 'position' => ['x' => 0, 'y' => 0]];

        $this->postJson('/jarvis/workflows', ['name' => 'Unsafe API', 'workflow_data' => ['nodes' => [$node], 'edges' => []]])
            ->assertUnprocessable()->assertJsonValidationErrors('workflow');

        $this->assertDatabaseMissing('jarvis_workflows', ['name' => 'Unsafe API']);
    }

    private function node(?int $connectionId, array $values = []): array
    {
        return ['config' => ['app' => 'custom_api', 'action' => 'request', 'values' => array_replace([
            'connection_id' => $connectionId, 'method' => 'GET', 'url' => '/api/jarvis/test',
        ], $values)]];
    }
}
