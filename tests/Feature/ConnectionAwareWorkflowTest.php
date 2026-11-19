<?php

namespace Tests\Feature;

use App\Models\ApiConnection;
use App\Models\JarvisSetting;
use App\Models\JarvisWorkflow;
use App\Models\User;
use App\Services\WorkflowOperationsService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Prompts\AgentPrompt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ConnectionAwareWorkflowTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[DataProvider('metabaseAuthentication')]
    public function test_saved_metabase_authentication_is_used_for_real_requests_and_redacted(string $authentication, string $header): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/jarvis/settings/connections', [
            'name' => 'Metabase', 'base_url' => 'https://93.184.215.14',
            'authentication' => $authentication, 'token' => 'metabase-test-secret',
        ])->assertCreated()->assertJsonPath('connection.integration', 'metabase')->assertDontSee('metabase-test-secret');
        $connection = ApiConnection::query()->firstOrFail();
        Http::preventStrayRequests();
        Http::fake(['https://93.184.215.14/api/card/123/query' => Http::response([
            'data' => ['rows' => [[12]], 'cols' => [['name' => 'total']]],
            'echo' => 'metabase-test-secret', 'X-Metabase-Session' => 'remote-session',
        ])]);

        $response = $this->postJson('/jarvis/workflows/test-step', ['node' => $this->node($connection->id, [
            'method' => 'POST', 'url' => '/api/card/123/query', 'body' => '{"parameters":[]}',
        ])]);

        $response->assertOk()->assertJsonPath('response.echo', '[REDACTED]')
            ->assertJsonPath('response.X-Metabase-Session', '[REDACTED]')->assertJsonPath('diagnostic.connection', 'Metabase')
            ->assertJsonPath('diagnostic.endpoint', '/api/card/123/query')->assertDontSee('metabase-test-secret');
        Http::assertSent(fn ($request): bool => $request->method() === 'POST' && $request->hasHeader($header, 'metabase-test-secret')
            && ! $request->hasHeader('Authorization') && $request['parameters'] === []);
        Http::assertSentCount(1);
    }

    public static function metabaseAuthentication(): array
    {
        return ['api key' => ['metabase_api_key', 'X-API-Key'], 'session' => ['metabase_session', 'X-Metabase-Session']];
    }

    public function test_field_selection_reduces_an_oversized_response_before_the_limit_and_keeps_pagination(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://93.184.215.14/api/card/123' => Http::response([
            'data' => ['rows' => [[12]], 'cols' => [['name' => 'total']], 'unused' => str_repeat('x', 60000)],
            'pagination' => ['current_page' => 1, 'last_page' => 2],
        ])]);

        $response = $this->postJson('/jarvis/workflows/test-step', ['node' => $this->node(null, [
            'url' => 'https://93.184.215.14/api/card/123', 'response_paths' => "data.rows,\ndata.cols",
        ])]);

        $response->assertOk()->assertJsonPath('response.data.rows', [[12]])->assertJsonMissingPath('response.data.unused')
            ->assertJsonPath('pagination.last_page', 2);
        $this->assertGreaterThan(50000, $response->json('diagnostic.response_bytes'));
        Http::assertSentCount(1);
    }

    public function test_oversized_response_returns_structured_suggestions_and_does_not_retry(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://93.184.215.14/api/card/123' => Http::response(['data' => str_repeat('x', 60000)])]);

        $response = $this->postJson('/jarvis/workflows/test-step', ['node' => $this->node(null, ['url' => 'https://93.184.215.14/api/card/123'])]);

        $response->assertOk()->assertJsonPath('success', false)->assertJsonPath('error', 'response_too_large')
            ->assertJsonPath('status', 200)->assertJsonMissingPath('response')->assertJsonMissingPath('result');
        $this->assertStringContainsString('required response fields', implode(' ', $response->json('suggestions')));
        Http::assertSentCount(1);
    }

    public function test_metabase_connection_test_uses_saved_key_and_never_returns_it(): void
    {
        $user = User::factory()->create();
        $connection = ApiConnection::factory()->create(['owner_key' => 'user:'.$user->id, 'authentication' => 'metabase_api_key', 'token' => 'metabase-test-secret']);
        Http::preventStrayRequests();
        Http::fake(['https://93.184.215.14/api/dashboard' => Http::response([])]);

        $this->actingAs($user)->postJson('/jarvis/settings/connections/'.$connection->id.'/test', ['test_path' => '/api/dashboard'])
            ->assertOk()->assertJsonPath('success', true)->assertDontSee('metabase-test-secret');

        Http::assertSent(fn ($request): bool => $request->hasHeader('X-API-Key', 'metabase-test-secret'));
    }

    public function test_workflow_rejects_persisted_metabase_session_headers(): void
    {
        Http::preventStrayRequests();

        $this->postJson('/jarvis/workflows/test-step', ['node' => $this->node(null, [
            'url' => 'https://93.184.215.14/api/dashboard', 'headers' => 'X-Metabase-Session: unsafe-session',
        ])])->assertUnprocessable();

        Http::assertNothingSent();
    }

    public function test_analytics_uses_a_suitable_connected_metabase_question_without_reading_tracker(): void
    {
        $workflow = $this->workflow();
        $tracker = ApiConnection::factory()->create(['owner_key' => $workflow->owner_key, 'name' => 'MMC Tracker']);
        $graph = $workflow->workflow_data;
        $graph['nodes'][] = ['id' => 'tracker', 'type' => 'app_action', 'position' => ['x' => 200, 'y' => 100],
            ...$this->node($tracker->id, ['url' => '/api/jarvis/deliveries'])];
        $graph['edges'][] = ['source' => 'ops', 'target' => 'tracker'];
        $workflow->update(['workflow_data' => $graph]);
        Http::preventStrayRequests();
        Http::fake(['https://93.184.215.14/api/card/123/query' => Http::response(['data' => ['rows' => [['North', 12]], 'cols' => []]])]);
        AnonymousAgent::fake([json_encode(['candidate_id' => $workflow->id.':bi']), '{"summary":"North has 12 deliveries."}'])->preventStrayPrompts();

        $result = app(WorkflowOperationsService::class)->ask($workflow->owner_key, 'Show delivery performance by region.');

        $this->assertTrue($result['success']);
        $this->assertSame('Metabase', $result['source']);
        $this->assertSame([['North', 12]], $result['data']['data']['rows']);
        AnonymousAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('North') && ! $prompt->contains('metabase-test-secret'));
        $this->assertStringNotContainsString('metabase-test-secret', $workflow->executions()->firstOrFail()->toJson());
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->method() === 'POST' && $request->hasHeader('X-API-Key', 'metabase-test-secret'));
    }

    public function test_unmatched_metabase_question_makes_no_http_requests(): void
    {
        $workflow = $this->workflow();
        Http::preventStrayRequests();
        AnonymousAgent::fake(['{"candidate_id":null}'])->preventStrayPrompts();

        $result = app(WorkflowOperationsService::class)->ask($workflow->owner_key, 'Show the sales dashboard.');

        $this->assertFalse($result['success']);
        $this->assertSame('Metabase', $result['source']);
        Http::assertNothingSent();
    }

    public function test_analytics_selects_the_correct_connection_when_workflows_reuse_node_ids(): void
    {
        $workflow = $this->workflow();
        $connection = ApiConnection::factory()->create(['owner_key' => $workflow->owner_key, 'name' => 'Metabase Sales',
            'authentication' => 'metabase_api_key', 'token' => 'another-metabase-secret']);
        $otherWorkflow = $workflow->replicate();
        $graph = $workflow->workflow_data;
        $graph['nodes'][2]['label'] = 'Sales analytics';
        $graph['nodes'][2]['config']['values']['connection_id'] = $connection->id;
        $graph['nodes'][2]['config']['values']['url'] = '/api/card/456/query';
        $otherWorkflow->workflow_data = $graph;
        $otherWorkflow->save();
        Http::preventStrayRequests();
        Http::fake(['https://93.184.215.14/api/card/123/query' => Http::response(['data' => ['rows' => [['North', 12]], 'cols' => []]])]);
        AnonymousAgent::fake([json_encode(['candidate_id' => $workflow->id.':bi']), '{"summary":"North has 12 deliveries."}'])->preventStrayPrompts();

        $result = app(WorkflowOperationsService::class)->ask($workflow->owner_key, 'Show delivery performance by region.');

        $this->assertTrue($result['success']);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://93.184.215.14/api/card/123/query'
            && $request->hasHeader('X-API-Key', 'metabase-test-secret'));
        Http::assertSentCount(1);
        AnonymousAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('Sales analytics')
            && ! $prompt->contains('another-metabase-secret') && ! $prompt->contains('metabase-test-secret'));
    }

    public function test_metabase_record_mutation_is_not_exposed_as_an_analytics_read(): void
    {
        $workflow = $this->workflow();
        $graph = $workflow->workflow_data;
        $graph['nodes'][2]['config']['values']['url'] = '/api/card/123';
        $graph['nodes'][2]['config']['values']['method'] = 'DELETE';
        $workflow->update(['workflow_data' => $graph]);
        Http::preventStrayRequests();
        AnonymousAgent::fake()->preventStrayPrompts();

        $result = app(WorkflowOperationsService::class)->ask($workflow->owner_key, 'Show delivery performance by region.');

        $this->assertFalse($result['success']);
        Http::assertNothingSent();
        AnonymousAgent::assertNeverPrompted();
    }

    public function test_oversized_metabase_read_returns_suggestions_without_reasoning_or_repeat_requests(): void
    {
        $workflow = $this->workflow();
        Http::preventStrayRequests();
        Http::fake(['https://93.184.215.14/api/card/123/query' => Http::response(['data' => ['rows' => [[str_repeat('x', 60000)]], 'cols' => []]])]);
        AnonymousAgent::fake([json_encode(['candidate_id' => $workflow->id.':bi'])])->preventStrayPrompts();

        $result = app(WorkflowOperationsService::class)->ask($workflow->owner_key, 'Show delivery performance by region.');

        $this->assertFalse($result['success']);
        $this->assertSame('response_too_large', $result['error']);
        $this->assertSame('Metabase', $result['source']);
        $this->assertNotEmpty($result['suggestions']);
        $this->assertArrayNotHasKey('response', $result);
        AnonymousAgent::assertPromptedTimes(1);
        Http::assertSentCount(1);
    }

    public function test_failed_test_step_preserves_http_diagnostics_without_response_secrets(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://93.184.215.14/api/dashboard' => Http::response(['token' => 'never-display'], 403)]);

        $response = $this->postJson('/jarvis/workflows/test-step', ['node' => $this->node(null, ['url' => 'https://93.184.215.14/api/dashboard'])]);

        $response->assertUnprocessable()->assertJsonPath('status', 403)->assertJsonPath('diagnostic.method', 'GET')
            ->assertJsonPath('diagnostic.endpoint', '/api/dashboard')->assertDontSee('never-display');
        $this->assertIsInt($response->json('diagnostic.elapsed_ms'));
        $this->assertIsInt($response->json('diagnostic.response_bytes'));
        Http::assertSentCount(1);
    }

    public function test_unscoped_all_deliveries_request_asks_for_scope_without_reading_records(): void
    {
        $workflow = $this->workflow();
        Http::preventStrayRequests();
        AnonymousAgent::fake()->preventStrayPrompts();

        $result = app(WorkflowOperationsService::class)->ask($workflow->owner_key, 'Give me all deliveries.');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('specify a project', $result['error']);
        Http::assertNothingSent();
        AnonymousAgent::assertNeverPrompted();
    }

    public function test_project_delivery_status_uses_compact_progress_instead_of_delivery_records(): void
    {
        $workflow = $this->trackerWorkflow();
        Http::preventStrayRequests();
        Http::fake([
            'https://93.184.215.14/api/jarvis/projects?*' => Http::response(['data' => [['project_id' => 7, 'project_name' => 'Project X']], 'meta' => ['pagination' => ['has_more' => false]]]),
            'https://93.184.215.14/api/jarvis/projects/delivery-progress?*' => Http::response([
                'data' => ['projects' => [['project_id' => 7, 'project_name' => 'Project X', 'delivery_progress_percent' => 70]]],
                'meta' => ['filters' => ['project_id' => 7], 'definitions' => []],
            ]),
        ]);
        AnonymousAgent::fake(['{"resource":"deliveries","project_id":7}', '{"summary":"Delivery progress is 70%."}'])->preventStrayPrompts();

        $result = app(WorkflowOperationsService::class)->ask($workflow->owner_key, 'What is the delivery status of project ID 7?');

        $this->assertTrue($result['success'], $result['error'] ?? '');
        $this->assertSame('projects/delivery-progress', $result['meta']['resource']);
        Http::assertSent(fn ($request): bool => parse_url($request->url(), PHP_URL_PATH) === '/api/jarvis/projects/delivery-progress'
            && (int) $request['project_id'] === 7);
        Http::assertSentCount(2);
    }

    public function test_individual_delivery_records_use_project_filter_and_twenty_records_per_page(): void
    {
        $workflow = $this->trackerWorkflow();
        Http::preventStrayRequests();
        Http::fake([
            'https://93.184.215.14/api/jarvis/projects?*' => Http::response(['data' => [['project_id' => 7, 'project_name' => 'Project X']], 'meta' => ['pagination' => ['has_more' => false]]]),
            'https://93.184.215.14/api/jarvis/deliveries?*' => Http::response(['data' => [['project_id' => 7, 'status' => 'Pending']], 'meta' => ['pagination' => ['current_page' => 1, 'last_page' => 2]]]),
        ]);
        AnonymousAgent::fake(['{"resource":"deliveries","project_id":7}', '{"summary":"One pending receipt; further pages are available."}'])->preventStrayPrompts();

        $result = app(WorkflowOperationsService::class)->ask($workflow->owner_key, 'Show individual DR records for project ID 7.');

        $this->assertTrue($result['success'], $result['error'] ?? '');
        $this->assertSame('deliveries', $result['meta']['resource']);
        Http::assertSent(fn ($request): bool => parse_url($request->url(), PHP_URL_PATH) === '/api/jarvis/deliveries'
            && (int) $request['project_id'] === 7 && (int) $request['per_page'] === 20 && (int) $request['page'] === 1);
        Http::assertSentCount(2);
    }

    public function test_metabase_cannot_forward_credentials_to_a_different_origin(): void
    {
        $user = User::factory()->create();
        $connection = ApiConnection::factory()->create(['owner_key' => 'user:'.$user->id, 'authentication' => 'metabase_api_key', 'token' => 'metabase-test-secret']);
        Http::preventStrayRequests();

        $this->actingAs($user)->postJson('/jarvis/workflows/test-step', ['node' => $this->node($connection->id, ['url' => 'https://8.8.8.8/api/dashboard'])])
            ->assertUnprocessable()->assertDontSee('metabase-test-secret');

        Http::assertNothingSent();
    }

    private function node(?int $connectionId, array $values = []): array
    {
        return ['config' => ['app' => 'custom_api', 'action' => 'request', 'values' => [
            'connection_id' => $connectionId, 'method' => 'GET', 'url' => '/api/dashboard', ...$values,
        ]]];
    }

    private function workflow(): JarvisWorkflow
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        JarvisSetting::current()->update(['allowed_actions' => ['analysis']]);
        $connection = ApiConnection::factory()->create(['owner_key' => 'user:'.$user->id, 'name' => 'Metabase',
            'authentication' => 'metabase_api_key', 'token' => 'metabase-test-secret']);

        return JarvisWorkflow::query()->create(['owner_key' => 'user:'.$user->id, 'name' => 'Operations', 'status' => 'draft', 'workflow_data' => [
            'nodes' => [
                ['id' => 'router', 'type' => 'orchestrator', 'position' => ['x' => 0, 'y' => 0], 'config' => []],
                ['id' => 'ops', 'type' => 'ai_agent', 'position' => ['x' => 100, 'y' => 0], 'config' => ['specialization' => 'operations', 'instruction' => 'Report accurately.']],
                ['id' => 'bi', 'label' => 'Delivery performance by region', 'type' => 'app_action', 'position' => ['x' => 200, 'y' => 0],
                    ...$this->node($connection->id, ['method' => 'POST', 'url' => '/api/card/123/query', 'body' => '{"parameters":[]}', 'response_paths' => 'data.rows,data.cols'])],
            ], 'edges' => [['source' => 'router', 'target' => 'ops'], ['source' => 'ops', 'target' => 'bi']],
        ]]);
    }

    private function trackerWorkflow(): JarvisWorkflow
    {
        $workflow = $this->workflow();
        ApiConnection::query()->firstOrFail()->update(['name' => 'MMC Tracker', 'authentication' => 'bearer']);
        $graph = $workflow->workflow_data;
        $graph['nodes'][2] = ['id' => 'bi', 'type' => 'app_action', 'position' => ['x' => 200, 'y' => 0],
            ...$this->node($graph['nodes'][2]['config']['values']['connection_id'], ['url' => '/api/jarvis/operation-masterlist'])];
        $workflow->update(['workflow_data' => $graph]);

        return $workflow;
    }
}
