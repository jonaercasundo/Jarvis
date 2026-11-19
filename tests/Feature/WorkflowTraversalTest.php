<?php

namespace Tests\Feature;

use App\Models\ApiConnection;
use App\Models\JarvisWorkflow;
use App\Models\User;
use App\Services\WorkflowAiService;
use App\Services\WorkflowExecutor;
use App\Services\WorkflowGraph;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Prompts\AgentPrompt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkflowTraversalTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_connected_api_executes_with_saved_authentication_before_agent_summary(): void
    {
        $workflow = $this->workflow();
        AnonymousAgent::fake([$this->toolDecision('api'), $this->finalDecision('Operation masterlist received.')])->preventStrayPrompts();
        Http::preventStrayRequests();
        Http::fake(['https://93.184.215.14/api/jarvis/operation-masterlist' => Http::response([
            'data' => ['operations' => [['id' => 7]]], 'token' => 'workflow-test-secret',
        ], 200)]);

        $result = app(WorkflowExecutor::class)->run($workflow, ['request' => 'Test MMC Tracker'], 'schedule');

        $this->assertTrue($result['success']);
        $this->assertSame(['trigger', 'router', 'agent', 'api'], array_column($result['results'], 'node_id'));
        $this->assertSame(['success', 'success', 'success', 'success'], array_column($result['results'], 'status'));
        $this->assertSame('Only one Agent is connected.', $result['results'][1]['output']['reason']);
        $this->assertSame(200, $result['results'][3]['output']['status']);
        $this->assertSame('Operation masterlist received.', $result['execution']->output_data['final']['result']['summary']);
        $this->assertStringNotContainsString('workflow-test-secret', $result['execution']->toJson());
        AnonymousAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('tool_results')
            && $prompt->contains('operations') && ! $prompt->contains('workflow-test-secret'));
        AnonymousAgent::assertPromptedTimes(2);
        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && $request->url() === 'https://93.184.215.14/api/jarvis/operation-masterlist'
            && $request->hasHeader('Authorization', 'Bearer workflow-test-secret'));
    }

    public function test_remote_api_failure_marks_both_action_and_agent_failed_without_secrets(): void
    {
        $workflow = $this->workflow();
        AnonymousAgent::fake([$this->toolDecision('api'), $this->finalDecision('503 - Remote API error')])->preventStrayPrompts();
        Http::preventStrayRequests();
        Http::fake(['https://93.184.215.14/api/jarvis/operation-masterlist' => Http::response(['token' => 'workflow-test-secret'], 503)]);

        $result = app(WorkflowExecutor::class)->run($workflow, ['request' => 'Test MMC Tracker'], 'schedule');

        $this->assertTrue($result['success']);
        $this->assertSame('success', $result['results'][2]['status']);
        $this->assertSame('success_with_errors', $result['execution']->status);
        $this->assertSame('failed', $result['results'][3]['status']);
        $this->assertSame('request_failed', $result['results'][2]['output']['trace']['decisions'][0]['status']);
        $this->assertStringNotContainsString('workflow-test-secret', $result['execution']->toJson());
        AnonymousAgent::assertPromptedTimes(2);
        Http::assertSentCount(1);
    }

    public function test_agent_can_answer_without_a_downstream_action(): void
    {
        $workflow = $this->workflow();
        $data = $workflow->workflow_data;
        $data['edges'] = array_slice($data['edges'], 0, 2);
        $workflow->workflow_data = $data;
        AnonymousAgent::fake([$this->finalDecision('No relevant tool is connected.')])->preventStrayPrompts();
        Http::preventStrayRequests();

        $result = app(WorkflowExecutor::class)->run($workflow, ['request' => 'Test MMC Tracker'], 'manual', true);

        $this->assertTrue($result['success']);
        $this->assertSame('No relevant tool is connected.', $result['results'][2]['output']['result']['summary']);
        $this->assertDatabaseHas('jarvis_workflow_execution_steps', ['execution_id' => $result['execution']->id, 'node_id' => 'agent', 'status' => 'success']);
        AnonymousAgent::assertPromptedTimes(1);
        Http::assertNothingSent();
    }

    public function test_deleted_nodes_are_ignored_by_existing_graph_traversal(): void
    {
        $graph = new WorkflowGraph(['nodes' => [
            ['id' => 'agent', 'type' => 'ai_agent'], ['id' => 'valid', 'type' => 'app_action'],
        ], 'edges' => [
            ['source' => 'agent', 'target' => 'deleted'], ['source' => 'agent', 'target' => 'valid'],
        ]]);

        $connected = $graph->connectedApps('agent');

        $this->assertSame(['valid'], array_column($connected, 'id'));
        $this->assertNull(app(WorkflowAiService::class)->selectConnectedNode('Test', $graph->connectedApps('missing'), 'connected app action'));
    }

    public function test_orchestrator_without_a_connected_agent_fails_cleanly(): void
    {
        $workflow = $this->workflow();
        $data = $workflow->workflow_data;
        $data['edges'] = array_slice($data['edges'], 0, 1);
        $workflow->workflow_data = $data;
        AnonymousAgent::fake()->preventStrayPrompts();
        Http::preventStrayRequests();

        $result = app(WorkflowExecutor::class)->run($workflow, ['request' => 'Test'], 'manual', true);

        $this->assertFalse($result['success']);
        $this->assertSame('The Orchestrator has no valid AI Agent connected.', $result['results'][1]['error_message']);
        $this->assertSame('orchestrator', $result['results'][1]['node_type']);
        AnonymousAgent::assertNeverPrompted();
        Http::assertNothingSent();
    }

    public function test_multiple_agents_route_to_the_selected_connected_agent(): void
    {
        $workflow = $this->workflow();
        $data = $workflow->workflow_data;
        $otherAgent = $data['nodes'][2];
        $otherAgent['id'] = 'other_agent';
        $otherAgent['label'] = 'Other agent';
        $data['nodes'][] = $otherAgent;
        $data['edges'][] = ['source' => 'router', 'target' => 'other_agent'];
        $data['edges'][] = ['source' => 'other_agent', 'target' => 'api'];
        $workflow->workflow_data = $data;
        AnonymousAgent::fake(['{"selected_node_id":"agent","reason":"Operations request."}', $this->toolDecision('api'), $this->finalDecision('Received.')])->preventStrayPrompts();
        Http::preventStrayRequests();
        Http::fake(['https://93.184.215.14/api/jarvis/operation-masterlist' => Http::response(['data' => [7]])]);

        $result = app(WorkflowExecutor::class)->run($workflow, ['request' => 'Test MMC Tracker'], 'schedule');

        $this->assertTrue($result['success']);
        $this->assertSame('agent', $result['results'][1]['output']['selected_agent_id']);
        $this->assertSame(['trigger', 'router', 'agent', 'api'], array_column($result['results'], 'node_id'));
        AnonymousAgent::assertPromptedTimes(3);
        Http::assertSentCount(1);
    }

    public function test_multiple_connected_actions_select_only_the_requested_action(): void
    {
        $workflow = $this->workflow(multipleApps: true);
        AnonymousAgent::fake([$this->toolDecision('api'), $this->finalDecision('Received.')])->preventStrayPrompts();
        Http::preventStrayRequests();
        Http::fake(['https://93.184.215.14/api/jarvis/operation-masterlist' => Http::response(['data' => [7]])]);

        $result = app(WorkflowExecutor::class)->run($workflow, ['request' => 'Test MMC Tracker'], 'schedule');

        $this->assertTrue($result['success']);
        $this->assertSame('api', $result['results'][2]['output']['tools'][0]['tool_id']);
        $this->assertSame(['trigger', 'router', 'agent', 'api'], array_column($result['results'], 'node_id'));
        AnonymousAgent::assertPromptedTimes(2);
        Http::assertSentCount(1);
    }

    #[DataProvider('invalidSelections')]
    public function test_invalid_selection_fails_without_a_null_offset_error(string $response): void
    {
        $workflow = $this->workflow(multipleApps: true);
        AnonymousAgent::fake([$response])->preventStrayPrompts();
        Http::preventStrayRequests();

        $result = app(WorkflowExecutor::class)->run($workflow, ['request' => 'Test'], 'schedule');

        $this->assertFalse($result['success']);
        $this->assertSame('The Agent returned an invalid tool decision.', $result['results'][2]['error_message']);
        $this->assertSame('ai_agent', $result['results'][2]['node_type']);
        Http::assertNothingSent();
    }

    public static function invalidSelections(): array
    {
        return [
            'missing ID' => ['{"reason":"Missing."}'],
            'null ID' => ['{"selected_node_id":null,"reason":"Missing."}'],
            'unconnected ID' => ['{"selected_node_id":"deleted","reason":"Unknown."}'],
        ];
    }

    private function toolDecision(string $id): string
    {
        return json_encode(['kind' => 'tool', 'tool_id' => $id, 'action' => 'configured', 'arguments' => [], 'reason' => 'Requested records.'], JSON_THROW_ON_ERROR);
    }

    private function finalDecision(string $summary): string
    {
        return json_encode(['kind' => 'final', 'result' => ['summary' => $summary], 'tool_considerations' => [['tool_id' => 'other', 'relevant' => false, 'reason' => 'Not needed for this task.']]], JSON_THROW_ON_ERROR);
    }

    private function workflow(bool $multipleApps = false): JarvisWorkflow
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $connection = ApiConnection::factory()->create(['owner_key' => 'user:'.$user->id, 'token' => 'workflow-test-secret']);
        $data = ['nodes' => [
            ['id' => 'trigger', 'type' => 'schedule_trigger', 'position' => ['x' => 0, 'y' => 0], 'config' => []],
            ['id' => 'router', 'type' => 'orchestrator', 'position' => ['x' => 100, 'y' => 0], 'config' => []],
            ['id' => 'agent', 'type' => 'ai_agent', 'label' => 'Operations agent', 'position' => ['x' => 200, 'y' => 0], 'config' => ['instruction' => 'Summarize operations.', 'fields' => ['summary']]],
            ['id' => 'api', 'type' => 'app_action', 'label' => 'Operation Masterlist', 'position' => ['x' => 300, 'y' => 0], 'config' => ['app' => 'custom_api', 'action' => 'request', 'values' => ['connection_id' => $connection->id, 'method' => 'GET', 'url' => '/api/jarvis/operation-masterlist']]],
        ], 'edges' => [
            ['source' => 'trigger', 'target' => 'router'], ['source' => 'router', 'target' => 'agent'], ['source' => 'agent', 'target' => 'api'],
        ]];
        if ($multipleApps) {
            $other = $data['nodes'][3];
            $other['id'] = 'other';
            $other['label'] = 'Other API';
            $other['config']['values']['url'] = '/other';
            $data['nodes'][] = $other;
            $data['edges'][] = ['source' => 'agent', 'target' => 'other'];
        }

        return JarvisWorkflow::query()->create([
            'owner_key' => 'user:'.$user->id, 'name' => 'MMC traversal', 'status' => 'draft', 'workflow_data' => $data,
        ]);
    }
}
