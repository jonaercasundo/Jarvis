<?php

namespace Tests\Feature;

use App\Ai\Agents\JcAgent;
use App\Ai\Tools\FindEmails;
use App\Ai\Tools\PolicyControlledTool;
use App\Models\ApiConnection;
use App\Models\JarvisSetting;
use App\Models\JarvisWorkflow;
use App\Models\User;
use App\Services\GoDaddyEmailService;
use App\Services\WorkflowHttpService;
use App\Services\WorkflowOperationsService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Tools\Request;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OperationsChatTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_main_chat_delegates_delivery_question_and_receives_executor_data(): void
    {
        $workflow = $this->workflow();
        $savedGraph = $workflow->workflow_data;
        $question = 'What is the delivery status of SME 2?';
        $this->fakeTracker('operation-masterlist', ['data' => [['project_name' => 'SME 2', 'delivery_status' => 'Pending']], 'token' => 'chat-tracker-secret']);
        AnonymousAgent::fake(['{"resource":"operation-masterlist","project_id":null}', '{"summary":"SME 2 delivery is pending."}'])->preventStrayPrompts();
        $result = null;
        Event::listen(AgentPrompted::class, function (AgentPrompted $event) use (&$result): void {
            if ($event->prompt->agent instanceof JcAgent) {
                $result = json_decode($event->response->toolResults->first()->result, true);
            }
        });
        JcAgent::fake([new ToolCall('operations', 'AskOperationsAgent', ['request' => $question]), 'SME 2 delivery is pending.'])->preventStrayPrompts();

        $response = $this->postJson('/jarvis/command', ['command' => $question]);

        $response->assertOk()->assertJsonPath('response', 'SME 2 delivery is pending.')->assertDontSee('chat-tracker-secret');
        $this->assertTrue($result['success']);
        $this->assertSame('MMC Tracker', $result['source']);
        $this->assertSame('Pending', $result['data']['data'][0]['delivery_status']);
        $this->assertSame('[REDACTED]', $result['data']['token']);
        $this->assertSame(200, $result['meta']['http_status']);
        $execution = $workflow->executions()->firstOrFail();
        $this->assertSame('chat', $execution->trigger_type);
        $this->assertSame('success', $execution->status);
        $this->assertSame(['router', 'ops', 'tracker'], $execution->steps()->orderBy('execution_order')->pluck('node_id')->all());
        $this->assertDatabaseHas('jarvis_workflow_agent_tasks', ['workflow_id' => $workflow->id, 'agent_node_id' => 'ops', 'status' => 'completed']);
        $this->assertSame($savedGraph, $workflow->fresh()->workflow_data);
        $this->assertSame('draft', $workflow->fresh()->status);
        $this->assertStringNotContainsString('chat-tracker-secret', $execution->toJson());
        AnonymousAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('connected_app_result') && $prompt->contains('Pending'));
        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && parse_url($request->url(), PHP_URL_PATH) === '/api/jarvis/operation-masterlist'
            && $request->hasHeader('Authorization', 'Bearer chat-tracker-secret'));
    }

    #[DataProvider('readResources')]
    public function test_operations_questions_select_only_allowlisted_get_endpoints(string $question, string $resource, ?int $projectId, string $path): void
    {
        $workflow = $this->workflow();
        $data = $resource === 'projects/delivery-progress' ? ['data' => ['projects' => [[
            'project_id' => 12, 'project_name' => 'Generic Project', 'project_status' => 'Implementation', 'pending_deliveries_count' => 1,
        ]]], 'meta' => ['filters' => [], 'definitions' => []]] : ['data' => [['id' => 12]]];
        $projects = $projectId === null ? [] : ['data' => [['project_id' => $projectId, 'project_name' => 'Generic Project']], 'meta' => ['pagination' => ['has_more' => false]]];
        $this->fakeTracker($path, $data, projects: $projects);
        AnonymousAgent::fake([json_encode(['resource' => $resource, 'project_id' => $projectId]), '{"summary":"Records retrieved."}'])->preventStrayPrompts();

        $result = app(WorkflowOperationsService::class)->ask($workflow->owner_key, $question);

        $this->assertTrue($result['success']);
        $this->assertSame($resource, $result['meta']['resource']);
        Http::assertSent(fn ($request): bool => $request->method() === 'GET' && parse_url($request->url(), PHP_URL_PATH) === '/api/jarvis/'.$path);
    }

    public static function readResources(): array
    {
        return [
            ['Show projects with pending deliveries.', 'projects/delivery-progress', null, 'projects/delivery-progress'],
            ['Show project ID 12.', 'projects/delivery-progress', 12, 'projects/delivery-progress'],
            ['Show current project dashboard.', 'dashboard', null, 'dashboard'],
            ['Show project lots.', 'lots', null, 'lots'],
            ["What's our current inventory?", 'inventory', null, 'inventory'],
            ['How many packages are still pending?', 'packages', null, 'packages'],
            ['Show deliveries for SME 2.', 'deliveries', null, 'deliveries'],
            ['Which warehouses have stock?', 'warehouses', null, 'warehouses'],
        ];
    }

    #[DataProvider('remoteFailures')]
    public function test_main_chat_receives_tracker_failure_without_fabricated_data(int $status): void
    {
        $workflow = $this->workflow();
        $question = 'What is the delivery status of SME 2?';
        $this->fakeTracker('operation-masterlist', ['token' => 'chat-tracker-secret'], $status);
        AnonymousAgent::fake(['{"resource":"operation-masterlist","project_id":null}'])->preventStrayPrompts();
        $result = null;
        Event::listen(AgentPrompted::class, function (AgentPrompted $event) use (&$result): void {
            if ($event->prompt->agent instanceof JcAgent) {
                $result = json_decode($event->response->toolResults->first()->result, true);
            }
        });
        JcAgent::fake([new ToolCall('operations', 'AskOperationsAgent', ['request' => $question]), 'The operations data could not be retrieved.'])->preventStrayPrompts();

        $response = $this->postJson('/jarvis/command', ['command' => $question]);

        $response->assertOk()->assertDontSee('chat-tracker-secret');
        $this->assertStringContainsString((string) $status, $response->json('response'));
        $this->assertStringNotContainsString('inbox', $response->json('response'));
        $this->assertFalse($result['success']);
        $this->assertNull($result['data']);
        $this->assertStringContainsString((string) $status, $result['error']);
        $this->assertSame('failed', $workflow->executions()->firstOrFail()->status);
        $this->assertSame('draft', $workflow->fresh()->status);
        AnonymousAgent::assertPromptedTimes(1);
        Http::assertSentCount(1);
    }

    public static function remoteFailures(): array
    {
        return [[401], [503]];
    }

    public function test_main_chat_receives_and_persists_delivery_timeout_details(): void
    {
        $workflow = $this->workflow();
        $question = 'What is the delivery status of SME 2?';
        $this->fakeTracker('deliveries', []);
        Http::fake([
            'https://tracker.metro-mobilia.com/api/jarvis/projects*' => Http::response(['data' => []]),
            'https://tracker.metro-mobilia.com/api/jarvis/deliveries*' => function (): never {
                throw new ConnectionException('cURL error 28: Operation timed out with 0 bytes received');
            },
        ]);
        AnonymousAgent::fake(['{"resource":"deliveries","project_id":null}'])->preventStrayPrompts();
        $result = null;
        Event::listen(AgentPrompted::class, function (AgentPrompted $event) use (&$result): void {
            if ($event->prompt->agent instanceof JcAgent) {
                $result = json_decode($event->response->toolResults->first()->result, true);
            }
        });
        $this->mock(GoDaddyEmailService::class)->shouldNotReceive('findEmails');
        JcAgent::fake([
            new ToolCall('operations', 'AskOperationsAgent', ['request' => $question]),
            new ToolCall('email-fallback', 'FindEmails', ['query' => 'SME 2']),
            'Tracker might be down. I can search your inbox instead.',
        ])->preventStrayPrompts();

        $this->postJson('/jarvis/command', ['command' => $question])->assertOk()
            ->assertJsonPath('response', "I couldn't retrieve the requested data from MMC Tracker because GET /api/jarvis/deliveries exceeded JARVIS's 12-second timeout.")
            ->assertDontSee('inbox')->assertDontSee('might be down');

        $this->assertFalse($result['success']);
        $this->assertNull($result['data']);
        $this->assertSame('timeout', $result['error_type']);
        $this->assertSame('GET', $result['method']);
        $this->assertSame('/api/jarvis/deliveries', $result['endpoint']);
        $this->assertSame(28, $result['curl_errno']);
        $this->assertSame('live_read', $result['meta']['execution_mode']);
        $this->assertSame($result['message'], $workflow->executions()->firstOrFail()->output_data['transport_error']['message']);
        $this->assertStringNotContainsString('chat-tracker-secret', json_encode($result));
        AnonymousAgent::assertPromptedTimes(1);
    }

    public function test_business_intent_resolver_allows_a_configured_unverified_route_to_try_retrieval(): void
    {
        $this->workflow();
        $question = 'Show the operation masterlist for SME 2.';
        $this->fakeTracker('operation-masterlist', ['data' => [['project_name' => 'SME 2']]]);
        AnonymousAgent::fake(['{"resource":"operation-masterlist","project_id":null}', '{"summary":"SME 2 found."}'])->preventStrayPrompts();
        JcAgent::fake([new ToolCall('operations', 'AskOperationsAgent', ['request' => $question]), 'SME 2 found.'])->preventStrayPrompts();

        $this->postJson('/jarvis/command', ['command' => $question])->assertOk()->assertJsonPath('type', 'ai_response');

        Http::assertSentCount(1);
    }

    public function test_explicit_email_fallback_remains_available(): void
    {
        $service = Mockery::mock(GoDaddyEmailService::class);
        $service->shouldReceive('findEmails')->once()->andReturn(['success' => true, 'emails' => []]);
        $tool = new PolicyControlledTool(
            new FindEmails($service),
            'If the delivery lookup fails, search email about SME 2 as a fallback.',
            'ask_first',
            ['read_emails', 'search_emails'],
        );

        $result = json_decode($tool->handle(new Request(['query' => 'SME 2'])), true);

        $this->assertTrue($result['success']);
    }

    public function test_capability_reporting_requires_a_successful_request_and_invalidates_on_token_rotation(): void
    {
        $workflow = $this->workflow();
        Http::preventStrayRequests();
        $before = $this->postJson('/jarvis/command', ['command' => 'What are your actual functions?']);
        $tool = collect($before->json('capabilities.tools'))->firstWhere('name', 'AskOperationsAgent');
        $this->assertTrue($tool['configured']);
        $this->assertFalse($tool['executable_now']);
        $this->assertTrue($before->json('capabilities.integrations.mmc_tracker.implemented'));
        $this->assertFalse($before->json('capabilities.saved_connections.0.reachability_verified'));
        Http::assertNothingSent();
        $this->fakeTracker('inventory', ['data' => []]);
        AnonymousAgent::fake(['{"resource":"inventory","project_id":null}', '{"summary":"No inventory records returned."}'])->preventStrayPrompts();
        app(WorkflowOperationsService::class)->ask($workflow->owner_key, 'Show our current inventory.');

        $after = $this->postJson('/jarvis/command', ['command' => 'What are your actual functions?']);

        $tool = collect($after->json('capabilities.tools'))->firstWhere('name', 'AskOperationsAgent');
        $this->assertTrue($tool['executable_now']);
        $this->assertTrue($after->json('capabilities.saved_connections.0.reachability_verified'));
        ApiConnection::query()->firstOrFail()->update(['token' => 'rotated-private-token']);
        $this->assertFalse(app(WorkflowOperationsService::class)->capability($workflow->owner_key)['connection_verified']);
        Http::assertSentCount(1);
    }

    public function test_other_owners_workflows_and_connections_are_never_used(): void
    {
        $this->workflow();
        Http::preventStrayRequests();
        AnonymousAgent::fake()->preventStrayPrompts();

        $result = app(WorkflowOperationsService::class)->ask('user:another-owner', 'Show our inventory.');

        $this->assertFalse($result['success']);
        $this->assertNull($result['data']);
        $this->assertDatabaseCount('jarvis_workflow_executions', 0);
        AnonymousAgent::assertNeverPrompted();
        Http::assertNothingSent();
    }

    public function test_foreign_connection_and_paused_workflow_do_not_provide_a_chat_route(): void
    {
        $workflow = $this->workflow();
        $connection = ApiConnection::query()->firstOrFail();
        $connection->update(['owner_key' => 'user:another-owner']);
        Http::preventStrayRequests();
        AnonymousAgent::fake()->preventStrayPrompts();

        $this->assertFalse(app(WorkflowOperationsService::class)->capability($workflow->owner_key)['configured']);
        $connection->update(['owner_key' => $workflow->owner_key]);
        $workflow->update(['status' => 'paused']);
        $this->assertFalse(app(WorkflowOperationsService::class)->ask($workflow->owner_key, 'Show inventory.')['success']);

        AnonymousAgent::assertNeverPrompted();
        Http::assertNothingSent();
    }

    #[DataProvider('unsafeSelections')]
    public function test_untrusted_resource_selection_never_sends_a_request(string $resource, ?int $projectId): void
    {
        $workflow = $this->workflow();
        Http::preventStrayRequests();
        AnonymousAgent::fake([json_encode(['resource' => $resource, 'project_id' => $projectId])])->preventStrayPrompts();

        $result = app(WorkflowOperationsService::class)->ask($workflow->owner_key, 'What is the delivery status of SME 2?');

        $this->assertFalse($result['success']);
        Http::assertNothingSent();
    }

    public static function unsafeSelections(): array
    {
        return [['https://evil.example/steal', null], ['../admin/delete', null], ['project', 2]];
    }

    public function test_small_talk_cannot_invoke_operations_even_if_the_model_requests_it(): void
    {
        $this->workflow();
        Http::preventStrayRequests();
        AnonymousAgent::fake()->preventStrayPrompts();
        JcAgent::fake([new ToolCall('unexpected', 'AskOperationsAgent', ['request' => 'Show inventory.']), 'I am doing well.'])->preventStrayPrompts();

        $this->postJson('/jarvis/command', ['command' => 'How are you?'])->assertOk();

        AnonymousAgent::assertNeverPrompted();
        Http::assertNothingSent();
        $this->assertDatabaseCount('jarvis_workflow_executions', 0);
    }

    public function test_request_to_change_records_cannot_be_rewritten_by_the_model_into_a_read(): void
    {
        $this->workflow();
        Http::preventStrayRequests();
        AnonymousAgent::fake()->preventStrayPrompts();
        JcAgent::fake([new ToolCall('unexpected', 'AskOperationsAgent', ['request' => 'Show delivery status.']), 'This tool cannot change records.'])->preventStrayPrompts();

        $this->postJson('/jarvis/command', ['command' => 'Update the delivery status of SME 2.'])->assertOk();

        AnonymousAgent::assertNeverPrompted();
        Http::assertNothingSent();
        $this->assertDatabaseCount('jarvis_workflow_executions', 0);
    }

    public function test_api_failure_revokes_recent_connectivity_verification(): void
    {
        $workflow = $this->workflow();
        $this->fakeTracker('inventory', ['data' => []]);
        AnonymousAgent::fake(['{"resource":"inventory","project_id":null}', '{"summary":"No inventory returned."}'])->preventStrayPrompts();
        app(WorkflowOperationsService::class)->ask($workflow->owner_key, 'Show inventory.');
        $this->assertTrue(app(WorkflowOperationsService::class)->capability($workflow->owner_key)['connection_verified']);
        Http::assertSentCount(1);
        Http::swap(new Factory);
        $this->fakeTracker('inventory', ['token' => 'chat-tracker-secret'], 401);
        AnonymousAgent::fake(['{"resource":"inventory","project_id":null}'])->preventStrayPrompts();

        $result = app(WorkflowOperationsService::class)->ask($workflow->owner_key, 'Show inventory.');

        $this->assertFalse($result['success']);
        $this->assertFalse(app(WorkflowOperationsService::class)->capability($workflow->owner_key)['connection_verified']);
        Http::assertSentCount(1);
    }

    public function test_latest_email_keeps_using_email_tools(): void
    {
        $this->workflow();
        Http::preventStrayRequests();
        AnonymousAgent::fake()->preventStrayPrompts();
        $email = Mockery::mock(GoDaddyEmailService::class);
        $email->shouldReceive('latestEmails')->once()->with(10)->andReturn(['success' => true, 'emails' => []]);
        $this->app->instance(GoDaddyEmailService::class, $email);
        JcAgent::fake()->preventStrayPrompts();

        $this->postJson('/jarvis/command', ['command' => 'Show my latest email.'])->assertOk();

        $this->assertDatabaseCount('jarvis_workflow_executions', 0);
        AnonymousAgent::assertNeverPrompted();
        JcAgent::assertNeverPrompted();
        Http::assertNothingSent();
    }

    public function test_disabled_analysis_cannot_read_operations(): void
    {
        $workflow = $this->workflow();
        JarvisSetting::current()->update(['allowed_actions' => []]);
        Http::preventStrayRequests();
        AnonymousAgent::fake()->preventStrayPrompts();
        JcAgent::fake([new ToolCall('operations', 'AskOperationsAgent', ['request' => 'Show inventory.']), 'Operations access is disabled.'])->preventStrayPrompts();

        $this->postJson('/jarvis/command', ['command' => "What's our current inventory?"])->assertOk();

        AnonymousAgent::assertNeverPrompted();
        Http::assertNothingSent();
    }

    public function test_main_chat_uses_live_reads_in_test_mode_and_correlates_returned_project_ids(): void
    {
        $workflow = $this->workflow();
        config(['jarvis.test_mode' => true]);
        $question = 'What is the delivery status of SME 2?';
        $this->fakeTracker('deliveries', ['data' => [['project_id' => 7199, 'status' => 'Pending']]], 200,
            ['data' => [['id' => 7199, 'name' => 'SME 2'], ['id' => 2, 'name' => 'Another project']]]);
        AnonymousAgent::fake(['{"resource":"deliveries","project_id":null}', '{"summary":"SME 2 delivery is pending."}'])->preventStrayPrompts();
        $result = null;
        Event::listen(AgentPrompted::class, function (AgentPrompted $event) use (&$result): void {
            if ($event->prompt->agent instanceof JcAgent) {
                $result = json_decode($event->response->toolResults->first()->result, true);
            }
        });
        JcAgent::fake([new ToolCall('operations', 'AskOperationsAgent', ['request' => $question]), 'SME 2 delivery is pending.'])->preventStrayPrompts();

        $this->postJson('/jarvis/command', ['command' => $question])->assertOk()->assertJsonPath('response', 'SME 2 delivery is pending.');

        $this->assertTrue($result['success']);
        $this->assertFalse($result['simulated']);
        $this->assertSame('live_read', $result['meta']['execution_mode']);
        $this->assertSame(7199, $result['related_data']['data'][0]['id']);
        $this->assertSame(7199, $result['data']['data'][0]['project_id']);
        $this->assertSame(['/api/jarvis/projects', '/api/jarvis/deliveries'], $result['meta']['endpoints']);
        $this->assertSame('live_read', $workflow->executions()->firstOrFail()->input_data['payload']['execution_mode']);
        AnonymousAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('connected_app_result')
            && $prompt->contains('SME 2') && $prompt->contains('7199') && $prompt->contains('Pending'));
        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && parse_url($request->url(), PHP_URL_PATH) === '/api/jarvis/deliveries'
            && $request->hasHeader('Authorization', 'Bearer chat-tracker-secret'));
        Http::assertSentCount(2);
    }

    public function test_explicit_main_chat_simulation_makes_no_tracker_or_workflow_ai_request(): void
    {
        $workflow = $this->workflow();
        config(['jarvis.test_mode' => true]);
        $question = 'Dry run: Show SME 2 delivery status.';
        $dns = Mockery::mock(WorkflowHttpService::class);
        $dns->shouldReceive('validateUrl')->once()->with('https://tracker.metro-mobilia.com/api/jarvis/operation-masterlist');
        $dns->shouldNotReceive('requestOptions');
        $this->app->instance(WorkflowHttpService::class, $dns);
        Http::preventStrayRequests();
        AnonymousAgent::fake()->preventStrayPrompts();
        $result = null;
        Event::listen(AgentPrompted::class, function (AgentPrompted $event) use (&$result): void {
            if ($event->prompt->agent instanceof JcAgent) {
                $result = json_decode($event->response->toolResults->first()->result, true);
            }
        });
        JcAgent::fake([new ToolCall('operations', 'AskOperationsAgent', ['request' => 'Show SME 2 delivery status.']), 'Dry run completed; no real Tracker data was retrieved.'])->preventStrayPrompts();

        $this->postJson('/jarvis/command', ['command' => $question])->assertOk();

        $this->assertTrue($result['success']);
        $this->assertTrue($result['simulated']);
        $this->assertNull($result['data']);
        $this->assertFalse($result['meta']['connection_verified']);
        $this->assertSame('simulation', $result['meta']['execution_mode']);
        $this->assertTrue($workflow->executions()->firstOrFail()->output_data['nodes'][2]['output']['simulated']);
        $this->assertFalse(app(WorkflowOperationsService::class)->capability($workflow->owner_key)['connection_verified']);
        AnonymousAgent::assertNeverPrompted();
        Http::assertNothingSent();
    }

    private function fakeTracker(string $resource, array $data, int $status = 200, array $projects = []): void
    {
        $url = 'https://tracker.metro-mobilia.com/api/jarvis/'.$resource;
        $dns = Mockery::mock(WorkflowHttpService::class);
        $dns->shouldReceive('validateUrl')->with($url)->once();
        $dns->shouldReceive('requestOptions')->with($url)->once()->andReturn(['allow_redirects' => false]);
        $responses = [$url.'*' => Http::response($data, $status)];
        if ($resource === 'deliveries' || $projects !== []) {
            $projectUrl = 'https://tracker.metro-mobilia.com/api/jarvis/projects';
            $dns->shouldReceive('validateUrl')->with($projectUrl)->once();
            $dns->shouldReceive('requestOptions')->with($projectUrl)->once()->andReturn(['allow_redirects' => false]);
            $responses[$projectUrl.'*'] = Http::response($projects);
        }
        $this->app->instance(WorkflowHttpService::class, $dns);
        Http::preventStrayRequests();
        Http::fake($responses);
    }

    private function workflow(): JarvisWorkflow
    {
        config(['jarvis.test_mode' => false]);
        $user = User::factory()->create();
        $this->actingAs($user);
        JarvisSetting::current()->update(['allowed_actions' => ['analysis', 'read_emails', 'search_emails']]);
        $connection = ApiConnection::factory()->create(['owner_key' => 'user:'.$user->id, 'name' => 'MMC Tracker', 'base_url' => 'https://tracker.metro-mobilia.com', 'token' => 'chat-tracker-secret']);

        return JarvisWorkflow::query()->create(['owner_key' => 'user:'.$user->id, 'name' => 'Operations', 'status' => 'draft', 'workflow_data' => [
            'nodes' => [
                ['id' => 'router', 'type' => 'orchestrator', 'position' => ['x' => 0, 'y' => 0], 'config' => []],
                ['id' => 'ops', 'type' => 'ai_agent', 'label' => 'Operations Agent', 'position' => ['x' => 100, 'y' => 0], 'config' => ['specialization' => 'operations', 'instruction' => 'Report accurate operational status.', 'execution_mode' => 'deterministic']],
                ['id' => 'tracker', 'type' => 'app_action', 'position' => ['x' => 200, 'y' => 0], 'config' => ['app' => 'custom_api', 'action' => 'request', 'values' => ['connection_id' => $connection->id, 'method' => 'GET', 'url' => '/api/jarvis/operation-masterlist']]],
            ], 'edges' => [['source' => 'router', 'target' => 'ops'], ['source' => 'ops', 'target' => 'tracker']],
        ]]);
    }
}
