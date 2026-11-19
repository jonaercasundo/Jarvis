<?php

namespace Tests\Feature;

use App\Ai\Agents\JcAgent;
use App\Ai\Tools\AskOperationsAgent;
use App\Models\ApiConnection;
use App\Models\JarvisSetting;
use App\Models\JarvisWorkflow;
use App\Models\User;
use App\Services\ConversationIntentResolver;
use App\Services\WorkflowHttpService;
use App\Services\WorkflowOperationsService;
use App\Services\WorkflowOwner;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Tools\Request as ToolRequest;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ResolvedOperationsContextTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_verified_identity_survives_in_the_same_conversation_without_copying_business_counts(): void
    {
        $this->actingAs(User::factory()->create());
        $request = $this->request('What is the status of SME 2?');
        $request->attributes->set('jarvis_resolved_operations_project', [...$this->project(), 'delivery_count' => 99, 'api_key' => 'private-key']);
        $resolver = app(ConversationIntentResolver::class);

        $resolver->rememberResult($request, ['success' => true, 'response' => 'Earlier count: 99.', 'conversation_id' => 'project-conversation']);
        $followup = $this->request('How many have been delivered?');
        $resolution = $resolver->resolve($followup, $followup->input('command'));

        $this->assertSame($this->project(), session('jarvis_smart_context.resolved_project'));
        $this->assertSame($this->project(), $followup->attributes->get('jarvis_operations_project'));
        $this->assertSame('Show completed deliveries for the current project.', $resolution['command']);
        $this->assertArrayNotHasKey('last_result', $followup->attributes->get('jarvis_task_context'));
        $this->assertStringNotContainsString('private-key', json_encode(session('jarvis_smart_context')));
    }

    #[DataProvider('scopeChanges')]
    public function test_resolved_identity_cannot_cross_the_owner_conversation_or_expiry(string $scopeChange): void
    {
        $this->actingAs(User::factory()->create());
        $this->rememberProject();
        if ($scopeChange === 'owner') {
            $this->actingAs(User::factory()->create());
        } elseif ($scopeChange === 'expired') {
            $this->travel(121)->minutes();
        }
        $followup = $this->request('How many have been delivered?', $scopeChange === 'conversation' ? 'another-conversation' : 'project-conversation');

        $resolution = app(ConversationIntentResolver::class)->resolve($followup, $followup->input('command'));

        $this->assertFalse($followup->attributes->has('jarvis_operations_project'));
        $this->assertSame('Which project should I check?', $resolution['response']['question']);
    }

    public static function scopeChanges(): array
    {
        return ['another owner' => ['owner'], 'another conversation' => ['conversation'], 'expired identity' => ['expired']];
    }

    #[DataProvider('explicitChanges')]
    public function test_new_project_or_domain_does_not_inherit_the_previous_identity(string $command): void
    {
        $this->actingAs(User::factory()->create());
        $this->rememberProject();
        $request = $this->request($command);

        app(ConversationIntentResolver::class)->resolve($request, $command);

        $this->assertFalse($request->attributes->has('jarvis_operations_project'));
        $this->assertArrayNotHasKey('resolved_project', session('jarvis_smart_context'));
    }

    public static function explicitChanges(): array
    {
        return [
            'another formatted alias' => ['What is the status of SME 1?'],
            'missing letter is a new explicit alias' => ['What is the status of sm2 project?'],
            'named project' => ['Show deliveries for Project ABC.'],
            'generic named status subject' => ['What is the status of North Campus?'],
            'generic named warehouse subject' => ['Show warehouse readiness for North Campus.'],
            'email domain' => ['Check important emails.'],
            'mail domain' => ['Check my mail.'],
        ];
    }

    #[DataProvider('relativeReferences')]
    public function test_relative_project_requests_keep_the_verified_identity(string $command): void
    {
        $this->actingAs(User::factory()->create());
        $this->rememberProject();
        $request = $this->request($command);

        app(ConversationIntentResolver::class)->resolve($request, $command);

        $this->assertSame($this->project(), $request->attributes->get('jarvis_operations_project'));
        $this->assertSame($this->project(), session('jarvis_smart_context.resolved_project'));
    }

    public static function relativeReferences(): array
    {
        return [
            'current project' => ['Show warehouse readiness for the current project.'],
            'this project' => ['What is the status of this project?'],
            'that project' => ['Show deliveries for that project.'],
        ];
    }

    public function test_frontend_supplied_identity_cannot_authorize_a_project_followup(): void
    {
        $this->actingAs(User::factory()->create());
        $request = $this->request('How many have been delivered?');
        $request->merge(['resolved_project' => $this->project(), 'jarvis_operations_project' => $this->project()]);

        $resolution = app(ConversationIntentResolver::class)->resolve($request, $request->input('command'));

        $this->assertFalse($request->attributes->has('jarvis_operations_project'));
        $this->assertSame('Which project should I check?', $resolution['response']['question']);
    }

    public function test_contextual_tool_read_uses_verified_server_identity_instead_of_model_supplied_project(): void
    {
        $this->actingAs(User::factory()->create());
        $request = $this->request('Show completed deliveries for the current project.');
        $request->attributes->set('jarvis_operations_project', $this->project());
        $this->app->instance('request', $request);
        Http::preventStrayRequests();
        $ownerKey = app(WorkflowOwner::class)->key($request);
        $operations = Mockery::mock(WorkflowOperationsService::class);
        $operations->shouldReceive('supportsRequest')->once()->with($request->input('command'))->andReturn(true);
        $operations->shouldReceive('isSimulationRequest')->once()->with($request->input('command'))->andReturn(false);
        $operations->shouldReceive('ask')->once()->with($ownerKey, $request->input('command'), false, $this->project())->andReturn([
            'success' => true, 'simulated' => false, 'data' => ['delivered' => 0],
            'meta' => ['connection_verified' => true, 'resolved_project' => $this->project()],
        ]);
        $tool = new AskOperationsAgent($operations, $ownerKey, $request->input('command'));

        $result = json_decode((string) $tool->handle(new ToolRequest(['request' => 'Show deliveries for Project INVENTED-99.'])), true);

        $this->assertTrue($result['success']);
        $this->assertSame($this->project(), $request->attributes->get('jarvis_resolved_operations_project'));
        Http::assertNothingSent();
    }

    public function test_tool_keeps_the_original_named_project_when_the_model_substitutes_another_project(): void
    {
        $this->actingAs(User::factory()->create());
        $request = $this->request('What is the status of SME 2?');
        $this->app->instance('request', $request);
        $ownerKey = app(WorkflowOwner::class)->key($request);
        $operations = Mockery::mock(WorkflowOperationsService::class);
        $operations->shouldReceive('supportsRequest')->once()->with('What is the status of SME 2?')->andReturn(true);
        $operations->shouldReceive('isSimulationRequest')->once()->with('What is the status of SME 2?')->andReturn(false);
        $operations->shouldReceive('ask')->once()->with($ownerKey, 'What is the status of SME 2?', false, null)->andReturn([
            'success' => true, 'simulated' => false, 'data' => ['project' => ['project_id' => 502698]],
            'meta' => ['connection_verified' => true, 'resolved_project' => $this->project()],
        ]);
        $tool = new AskOperationsAgent($operations, $ownerKey, $request->input('command'));

        $result = json_decode((string) $tool->handle(new ToolRequest(['request' => 'What is the status of SME 1?'])), true);

        $this->assertSame(502698, $result['data']['project']['project_id']);
        $this->assertSame($this->project(), $request->attributes->get('jarvis_resolved_operations_project'));
    }

    #[DataProvider('unverifiedReads')]
    public function test_simulated_or_unverified_reads_do_not_establish_project_identity(bool $simulated, bool $verified): void
    {
        $this->actingAs(User::factory()->create());
        $request = $this->request('Show project delivery progress.');
        $this->app->instance('request', $request);
        $ownerKey = app(WorkflowOwner::class)->key($request);
        $operations = Mockery::mock(WorkflowOperationsService::class);
        $operations->shouldReceive('supportsRequest')->once()->andReturn(true);
        $operations->shouldReceive('isSimulationRequest')->once()->andReturn($simulated);
        $operations->shouldReceive('ask')->once()->andReturn([
            'success' => true, 'simulated' => $simulated, 'data' => ['delivered' => 0],
            'meta' => ['connection_verified' => $verified, 'resolved_project' => $this->project()],
        ]);
        $tool = new AskOperationsAgent($operations, $ownerKey, $request->input('command'));

        $tool->handle(new ToolRequest(['request' => $request->input('command')]));

        $this->assertFalse($request->attributes->has('jarvis_resolved_operations_project'));
    }

    public static function unverifiedReads(): array
    {
        return ['simulation' => [true, true], 'unverified live data' => [false, false]];
    }

    public function test_verified_context_reads_fresh_progress_once_without_searching_for_the_project_again(): void
    {
        [$workflow, $connection] = $this->workflow();
        $this->fakeDns();
        Http::preventStrayRequests();
        Http::fake(['https://tracker.metro-mobilia.com/api/jarvis/projects/delivery-progress?*' => Http::response($this->progress(502698, '(SME2) Equipment'))]);
        AnonymousAgent::fake([
            '{"resource":"projects/delivery-progress","project_name":"INVENTED","project_id":99}',
            '{"summary":"Three deliveries are completed."}',
        ])->preventStrayPrompts();
        $project = [...$this->project(), 'connection_id' => $connection->id];

        $result = app(WorkflowOperationsService::class)->ask($workflow->owner_key, 'Show completed deliveries for the current project.', resolvedProject: $project);

        $this->assertTrue($result['success'], $result['error'] ?? '');
        $this->assertSame(502698, $result['data']['project']['project_id']);
        $this->assertSame(3, $result['data']['counts']['total_deliveries_count']);
        $this->assertSame($project, $result['meta']['resolved_project']);
        Http::assertSent(fn ($request): bool => parse_url($request->url(), PHP_URL_PATH) === '/api/jarvis/projects/delivery-progress' && (int) $request['project_id'] === 502698);
        Http::assertSentCount(1);
        AnonymousAgent::assertPromptedTimes(2);
    }

    public function test_context_from_a_different_connection_is_rejected_before_tracker_reads(): void
    {
        [$workflow, $connection] = $this->workflow();
        Http::preventStrayRequests();
        AnonymousAgent::fake(['{"resource":"projects/delivery-progress","project_name":null}'])->preventStrayPrompts();

        $result = app(WorkflowOperationsService::class)->ask($workflow->owner_key, 'Show completed deliveries for the current project.',
            resolvedProject: [...$this->project(), 'connection_id' => $connection->id + 1]);

        $this->assertFalse($result['success']);
        Http::assertNothingSent();
    }

    public function test_controller_retrieves_fresh_followup_data_even_when_the_chat_agent_does_not_call_the_tool(): void
    {
        [$workflow, $connection] = $this->workflow();
        $this->fakeDns();
        Http::preventStrayRequests();
        Http::fake(['https://tracker.metro-mobilia.com/api/jarvis/projects/delivery-progress?*' => Http::response($this->progress(502698, '(SME2) Equipment'))]);
        $project = [...$this->project(), 'connection_id' => $connection->id];
        $this->withSession(['jarvis_smart_context' => [
            'owner_key' => $workflow->owner_key, 'conversation_id' => 'project-conversation', 'expires_at' => now()->addHour()->timestamp,
            'domain' => 'business', 'resolved_project' => $project, 'project' => $project['project_name'],
            'last_result' => 'Earlier count: 99.', 'last_type' => 'ai_response',
        ]]);
        JcAgent::fake(['Earlier count: 99.'])->preventStrayPrompts();
        AnonymousAgent::fake([
            '{"resource":"projects/delivery-progress","project_name":null}',
            '{"summary":"Three deliveries are completed."}',
        ])->preventStrayPrompts();

        $response = $this->postJson('/jarvis/command', ['command' => 'How many have been delivered?', 'conversation_id' => 'project-conversation']);

        $response->assertOk()->assertJsonPath('response', 'Three deliveries are completed.')
            ->assertSessionHas('jarvis_smart_context.resolved_project', $project)->assertDontSee('Earlier count: 99.');
        Http::assertSent(fn ($request): bool => parse_url($request->url(), PHP_URL_PATH) === '/api/jarvis/projects/delivery-progress' && (int) $request['project_id'] === 502698);
        Http::assertSentCount(1);
        JcAgent::assertPromptedTimes(1);
        AnonymousAgent::assertPromptedTimes(2);
    }

    public function test_controller_followup_fallback_cannot_bypass_disabled_analysis_permission(): void
    {
        [$workflow, $connection] = $this->workflow();
        JarvisSetting::current()->update(['allowed_actions' => []]);
        $project = [...$this->project(), 'connection_id' => $connection->id];
        $this->withSession(['jarvis_smart_context' => [
            'owner_key' => $workflow->owner_key, 'conversation_id' => 'project-conversation', 'expires_at' => now()->addHour()->timestamp,
            'domain' => 'business', 'resolved_project' => $project, 'project' => $project['project_name'],
        ]]);
        Http::preventStrayRequests();
        JcAgent::fake(['No fresh records read.'])->preventStrayPrompts();
        AnonymousAgent::fake()->preventStrayPrompts();

        $response = $this->postJson('/jarvis/command', ['command' => 'How many have been delivered?', 'conversation_id' => 'project-conversation']);

        $response->assertOk()->assertJsonPath('type', 'data_unavailable')->assertJsonPath('artifact', null);
        Http::assertNothingSent();
        JcAgent::assertNeverPrompted();
        AnonymousAgent::assertNeverPrompted();
    }

    public function test_an_explicit_new_project_overrides_previously_verified_context(): void
    {
        [$workflow, $connection] = $this->workflow();
        $this->fakeDns();
        Http::preventStrayRequests();
        Http::fake([
            'https://tracker.metro-mobilia.com/api/jarvis/projects?*' => Http::response(['success' => true,
                'data' => [['project_id' => 502682, 'project_name' => '(SME1) Equipment', 'ref_no' => 'REF-1']],
                'meta' => ['pagination' => ['has_more' => false]]]),
            'https://tracker.metro-mobilia.com/api/jarvis/projects/delivery-progress?*' => Http::response($this->progress(502682, '(SME1) Equipment')),
        ]);
        AnonymousAgent::fake([
            '{"resource":"projects/delivery-progress","project_name":"SME 1"}',
            '{"summary":"SME 1 has three completed deliveries."}',
        ])->preventStrayPrompts();

        $result = app(WorkflowOperationsService::class)->ask($workflow->owner_key, 'What is the status of SME 1?',
            resolvedProject: [...$this->project(), 'connection_id' => $connection->id]);

        $this->assertTrue($result['success'], $result['error'] ?? '');
        $this->assertSame(502682, $result['data']['project']['project_id']);
        Http::assertSent(fn ($request): bool => parse_url($request->url(), PHP_URL_PATH) === '/api/jarvis/projects/delivery-progress' && (int) $request['project_id'] === 502682);
        Http::assertSentCount(2);
        AnonymousAgent::assertPromptedTimes(2);
    }

    private function rememberProject(): void
    {
        $request = $this->request('What is the status of SME 2?');
        $request->attributes->set('jarvis_resolved_operations_project', $this->project());
        app(ConversationIntentResolver::class)->rememberResult($request, ['success' => true, 'response' => 'Retrieved project status.', 'conversation_id' => 'project-conversation']);
    }

    /** @return array{project_id: int, project_name: string, connection_id: int} */
    private function project(): array
    {
        return ['project_id' => 502698, 'project_name' => '(SME2) Equipment', 'connection_id' => 1];
    }

    private function request(string $command, string $conversationId = 'project-conversation'): Request
    {
        $request = Request::create('/jarvis/command', 'POST', ['command' => $command, 'conversation_id' => $conversationId]);
        $request->setLaravelSession($this->app['session.store']);
        $request->setUserResolver(fn (): ?User => auth()->user());

        return $request;
    }

    /** @return array{JarvisWorkflow, ApiConnection} */
    private function workflow(): array
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        JarvisSetting::current()->update(['allowed_actions' => ['analysis']]);
        $connection = ApiConnection::factory()->create(['owner_key' => 'user:'.$user->id, 'base_url' => 'https://tracker.metro-mobilia.com', 'token' => 'context-test-secret']);
        $workflow = JarvisWorkflow::query()->create(['owner_key' => 'user:'.$user->id, 'name' => 'Operations', 'status' => 'draft', 'workflow_data' => [
            'nodes' => [
                ['id' => 'router', 'type' => 'orchestrator', 'position' => ['x' => 0, 'y' => 0], 'config' => []],
                ['id' => 'ops', 'type' => 'ai_agent', 'position' => ['x' => 100, 'y' => 0], 'config' => ['specialization' => 'operations', 'instruction' => 'Report actual Tracker data.', 'execution_mode' => 'deterministic']],
                ['id' => 'tracker', 'type' => 'app_action', 'position' => ['x' => 200, 'y' => 0], 'config' => ['app' => 'custom_api', 'action' => 'request', 'values' => ['connection_id' => $connection->id, 'url' => '/api/jarvis/operation-masterlist', 'method' => 'GET']]],
            ], 'edges' => [['source' => 'router', 'target' => 'ops'], ['source' => 'ops', 'target' => 'tracker']],
        ]]);

        return [$workflow, $connection];
    }

    private function fakeDns(): void
    {
        $this->mock(WorkflowHttpService::class, function ($mock): void {
            foreach (['projects', 'projects/delivery-progress'] as $path) {
                $url = 'https://tracker.metro-mobilia.com/api/jarvis/'.$path;
                $mock->shouldReceive('validateUrl')->with($url);
                $mock->shouldReceive('requestOptions')->with($url)->andReturn(['allow_redirects' => false]);
            }
        });
    }

    private function progress(int $projectId, string $projectName): array
    {
        return ['success' => true, 'data' => ['projects' => [[
            'project_id' => $projectId, 'project_name' => $projectName, 'total_deliveries_count' => 3,
            'warehouse_readiness' => ['unit' => 'mixed item units', 'percent' => null],
            'operational_pipeline' => ['delivered' => ['completed' => 3, 'total' => 20, 'percent' => 15]],
            'overall_progress' => null, 'delivery_progress_percent' => 15, 'billing_progress_percent' => null,
        ]]], 'meta' => ['filters' => ['project_id' => $projectId], 'definitions' => ['delivered' => 'Completed package allocations.']]];
    }
}
