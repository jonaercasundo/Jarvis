<?php

namespace Tests\Feature;

use App\Ai\Agents\JcAgent;
use App\Contracts\Workflows\WorkflowAppExecutor;
use App\Models\ApiConnection;
use App\Models\JarvisSetting;
use App\Models\JarvisWorkflow;
use App\Models\User;
use App\Services\WorkflowAgentDelegation;
use App\Services\WorkflowAgentRuntime;
use App\Services\WorkflowAppExecutorRegistry;
use App\Services\WorkflowExecutor;
use App\Services\WorkflowHttpService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\ToolCall;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AutonomousWorkflowAgentTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[DataProvider('modelChoices')]
    public function test_models_use_the_same_central_credential(?string $model, string $expected, bool $fallback): void
    {
        $agent = $this->agent(['model' => $model]);
        JarvisSetting::current()->update(['api_key_encrypted' => Crypt::encryptString('central-ai-credential')]);
        AnonymousAgent::fake([$this->final('Done.')])->preventStrayPrompts();

        $result = app(WorkflowAgentRuntime::class)->run($agent, [], 'Analyze supplied facts.', fn (): array => []);

        $this->assertSame($expected, $result['trace']['model']);
        $this->assertSame($fallback, $result['trace']['model_fallback'] !== null);
        $this->assertSame('central-ai-credential', config('ai.providers.anthropic.key'));
        AnonymousAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->model === $expected && $prompt->provider->providerCredentials()['key'] === 'central-ai-credential' && ! $prompt->contains('central-ai-credential'));
    }

    public static function modelChoices(): array
    {
        return ['default' => [null, 'claude-fable-5', false], 'override' => ['claude-sonnet-5', 'claude-sonnet-5', false],
            'other agent' => ['claude-haiku-4-5-20251001', 'claude-haiku-4-5-20251001', false], 'invalid model' => ['unavailable', 'claude-fable-5', true]];
    }

    public function test_three_agents_use_different_models_with_one_central_key(): void
    {
        $agent = $this->agent();
        JarvisSetting::current()->update(['api_key_encrypted' => Crypt::encryptString('shared-central-key')]);
        AnonymousAgent::fake([$this->final('A'), $this->final('B'), $this->final('C')])->preventStrayPrompts();
        foreach (['claude-fable-5', 'claude-sonnet-5', 'claude-haiku-4-5-20251001'] as $model) {
            $agent['config']['model'] = $model;
            $result = app(WorkflowAgentRuntime::class)->run($agent, [], 'Analyze supplied information.', fn (): array => []);
            $this->assertSame($model, $result['trace']['model']);
            AnonymousAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->model === $model && $prompt->provider->providerCredentials()['key'] === 'shared-central-key');
        }
        AnonymousAgent::assertPromptedTimes(3);
    }

    public function test_provider_model_unavailability_falls_back_and_is_recorded(): void
    {
        $agent = $this->agent(['model' => 'claude-sonnet-5']);
        $attempts = 0;
        AnonymousAgent::fake(function () use (&$attempts): string {
            if (++$attempts === 1) {
                throw new AiException('model is not available');
            }

            return $this->final('Answered with the default.');
        })->preventStrayPrompts();

        $result = app(WorkflowAgentRuntime::class)->run($agent, [], 'Explain the supplied facts.', fn (): array => []);

        $this->assertSame('claude-fable-5', $result['trace']['model']);
        $this->assertStringContainsString('provider rejected', $result['trace']['model_fallback']);
        $this->assertSame(2, $attempts);
    }

    public function test_relevant_uncalled_tool_is_retrieved_before_answering_no_data(): void
    {
        [$agent, $apps] = $this->connectedAgent();
        AnonymousAgent::fake([
            json_encode(['kind' => 'final', 'result' => ['summary' => 'No expense data.'], 'tool_considerations' => [['tool_id' => 'metabase', 'relevant' => true, 'reason' => 'Financial reports']]], JSON_THROW_ON_ERROR),
            $this->toolDecision('metabase', 'search', ['query' => ['q' => 'expenses']]), $this->final('The search returned no matching reports.'),
        ])->preventStrayPrompts();
        Http::preventStrayRequests();
        Http::fake(['https://93.184.215.14/api/search*' => Http::response(['data' => []])]);

        $result = $this->runAgent($agent, $apps, 'Give me expenses.');

        $this->assertSame('no_matching_records', $result['tools'][0]['status']);
        $this->assertStringNotContainsString('No expense data.', $result['result']['summary']);
        Http::assertSentCount(1);
    }

    public function test_delivery_request_uses_tracker_and_leaves_other_tools_unused(): void
    {
        [$agent, $apps] = $this->connectedAgent();
        AnonymousAgent::fake([$this->toolDecision('tracker', 'projects/delivery-progress'), $this->final('Delivery progress retrieved.')])->preventStrayPrompts();
        Http::preventStrayRequests();
        Http::fake(['https://93.184.215.14/api/jarvis/projects/delivery-progress*' => Http::response(['data' => ['projects' => [['project_id' => 1, 'delivery_progress_percent' => 65]]]])]);

        $result = $this->runAgent($agent, $apps, 'Give me delivery progress.');

        $this->assertSame(['tracker'], array_column($result['tools'], 'tool_id'));
        $this->assertSame('Not needed', $result['trace']['available_tools'][1]['status']);
        Http::assertSent(fn ($request): bool => $request['compact'] === 1 && $request['per_page'] === 20);
        Http::assertSentCount(1);
    }

    public function test_expenses_searches_metabase_then_queries_the_discovered_report(): void
    {
        [$agent, $apps] = $this->connectedAgent();
        AnonymousAgent::fake([$this->toolDecision('metabase', 'search', ['query' => ['q' => 'expenses']]), $this->toolDecision('metabase', 'query_card', ['card_id' => 42]), $this->final('Expenses total 120.')])->preventStrayPrompts();
        Http::preventStrayRequests();
        Http::fake(['https://93.184.215.14/api/search*' => Http::response(['data' => [['id' => 42, 'name' => 'Expenses']]]), 'https://93.184.215.14/api/card/42/query' => Http::response(['data' => ['rows' => [[120]], 'cols' => [['name' => 'Total']]]])]);

        $result = $this->runAgent($agent, $apps, 'Give me a breakdown of expenses this year.');

        $this->assertSame(['metabase', 'metabase'], array_column($result['tools'], 'tool_id'));
        AnonymousAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('Expenses') && $prompt->contains('tool_results') && $prompt->contains('120'));
        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => $request->method() === 'POST' && str_contains($request->url(), '/card/42/query'));
    }

    public function test_comparison_uses_two_sources_and_does_not_run_outlook(): void
    {
        [$agent, $apps] = $this->connectedAgent();
        AnonymousAgent::fake([$this->toolDecision('tracker', 'projects/delivery-progress'), $this->toolDecision('metabase', 'query_card', ['card_id' => 42]), $this->final('Comparison based on both sources.')])->preventStrayPrompts();
        Http::preventStrayRequests();
        Http::fake(['https://93.184.215.14/api/jarvis/projects/delivery-progress*' => Http::response(['data' => [1]]), 'https://93.184.215.14/api/card/42/query' => Http::response(['data' => ['rows' => [[120]]]])]);

        $result = $this->runAgent($agent, $apps, 'Compare expenses with project delivery performance.');

        $this->assertSame(['tracker', 'metabase'], array_column($result['tools'], 'tool_id'));
        $this->assertSame('Not needed', $result['trace']['available_tools'][2]['status']);
        Http::assertSentCount(2);
    }

    public function test_delayed_projects_can_prepare_a_draft_but_sending_needs_approval(): void
    {
        [$agent, $apps] = $this->connectedAgent(['permissions' => ['send' => true]]);
        $email = ['to' => 'management@example.com', 'subject' => 'Delayed projects', 'body' => 'Project 1 is delayed.'];
        AnonymousAgent::fake([$this->toolDecision('tracker', 'projects/delivery-progress'), $this->toolDecision('outlook', 'draft', $email), $this->toolDecision('outlook', 'send', $email)])->preventStrayPrompts();
        Http::preventStrayRequests();
        Http::fake(['https://93.184.215.14/api/jarvis/projects/delivery-progress*' => Http::response(['data' => ['projects' => [['project_id' => 1, 'project_status' => 'delayed']]]])]);

        $result = $this->runAgent($agent, $apps, 'Find delayed projects and prepare an email for management.');

        $this->assertSame(['tracker', 'outlook', 'outlook'], array_column($result['tools'], 'tool_id'));
        $this->assertFalse($result['tools'][1]['data']['sent']);
        $this->assertSame('confirmation_required', $result['tools'][2]['status']);
        $this->assertSame('send', $result['result']['pending_action']['action']);
        Http::assertSentCount(1);
    }

    #[DataProvider('blockedActions')]
    public function test_permissions_are_enforced_before_execution(string $appId, string $action, array $arguments, array $permissions): void
    {
        [$agent, $apps] = $this->connectedAgent(['permissions' => $permissions, 'require_approval' => false]);
        AnonymousAgent::fake([$this->toolDecision($appId, $action, $arguments), $this->final('Permission denied.')])->preventStrayPrompts();
        Http::preventStrayRequests();

        $result = $this->runAgent($agent, $apps, 'Perform the configured action.');

        $this->assertSame('permission_denied', $result['tools'][0]['status']);
        Http::assertNothingSent();
    }

    public static function blockedActions(): array
    {
        return ['read' => ['tracker', 'projects', [], ['read' => false]], 'draft' => ['outlook', 'draft', ['to' => 'user@example.com', 'subject' => 'Hi', 'body' => 'Hello'], ['draft' => false]],
            'send' => ['outlook', 'send', ['to' => 'user@example.com', 'subject' => 'Hi', 'body' => 'Hello'], ['send' => false]]];
    }

    public function test_tool_failure_is_returned_to_reasoning_with_authentication_status(): void
    {
        [$agent, $apps] = $this->connectedAgent();
        AnonymousAgent::fake([$this->toolDecision('metabase', 'search', ['query' => ['q' => 'expenses']]), $this->final('Metabase authentication failed.')])->preventStrayPrompts();
        Http::preventStrayRequests();
        Http::fake(['https://93.184.215.14/api/search*' => Http::response([], 401)]);

        $result = $this->runAgent($agent, $apps, 'Break down expenses.');

        $this->assertSame('authentication_failed', $result['tools'][0]['status']);
        AnonymousAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('authentication_failed'));
        Http::assertSentCount(1);
    }

    public function test_model_cannot_change_connection_or_http_method(): void
    {
        [$agent, $apps] = $this->connectedAgent();
        AnonymousAgent::fake([$this->toolDecision('tracker', 'projects', ['method' => 'DELETE', 'connection_id' => 999])])->preventStrayPrompts();
        Http::preventStrayRequests();

        $this->expectExceptionMessage('unsupported fields');
        try {
            $this->runAgent($agent, $apps, 'Read projects.');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_tool_results_and_trace_redact_echoed_credentials(): void
    {
        [$agent, $apps] = $this->connectedAgent();
        AnonymousAgent::fake([$this->toolDecision('tracker', 'projects'), $this->final('Retrieved.')])->preventStrayPrompts();

        $result = app(WorkflowAgentRuntime::class)->run($agent, $apps, 'Read projects.', fn (): array => ['success' => true, 'response' => ['password' => 'sensitive', 'text' => 'connection-test-secret']]);

        $encoded = json_encode($result, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('connection-test-secret', $encoded);
        $this->assertStringNotContainsString('sensitive', $encoded);
        AnonymousAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('[REDACTED]') && ! $prompt->contains('connection-test-secret'));
    }

    public function test_repeated_calls_are_blocked_and_loop_is_bounded(): void
    {
        [$agent, $apps] = $this->connectedAgent();
        config(['jarvis.ai.max_tool_iterations' => 2]);
        AnonymousAgent::fake([$this->toolDecision('tracker', 'projects'), $this->toolDecision('tracker', 'projects'), $this->toolDecision('tracker', 'projects')])->preventStrayPrompts();
        Http::preventStrayRequests();
        Http::fake(['https://93.184.215.14/api/jarvis/projects*' => Http::response(['data' => []])]);

        $result = $this->runAgent($agent, $apps, 'Read projects.');

        $this->assertSame('no_matching_records', $result['tools'][0]['status']);
        $this->assertSame('duplicate_request_blocked', $result['tools'][1]['status']);
        $this->assertStringContainsString('limit', $result['result']['summary']);
        Http::assertSentCount(1);
    }

    public function test_reordering_arguments_cannot_repeat_a_write(): void
    {
        [$agent, $apps] = $this->connectedAgent(['permissions' => ['write' => true], 'require_approval' => false]);
        $apps[0]['config']['values']['method'] = 'POST';
        AnonymousAgent::fake([$this->toolDecision('tracker', 'configured', ['body' => ['name' => 'Reviewed', 'year' => 2026]]),
            $this->toolDecision('tracker', 'configured', ['body' => ['year' => 2026, 'name' => 'Reviewed']]), $this->final('Saved once.')])->preventStrayPrompts();
        Http::preventStrayRequests();
        Http::fake(['https://93.184.215.14/configured' => Http::response(['success' => true])]);

        $result = $this->runAgent($agent, $apps, 'Create one reviewed record.');

        $this->assertSame('duplicate_request_blocked', $result['tools'][1]['status']);
        Http::assertSentCount(1);
    }

    public function test_owned_connection_metadata_is_public_but_credentials_are_not(): void
    {
        [$agent] = $this->connectedAgent();
        ApiConnection::factory()->create(['owner_key' => 'user:999', 'name' => 'Other tenant']);

        $response = $this->getJson('/jarvis/workflows/connections')->assertOk();

        $this->assertStringContainsString('Delivery progress', $response->getContent());
        $this->assertStringNotContainsString('connection-test-secret', $response->getContent());
        $this->assertStringNotContainsString('Other tenant', $response->getContent());
        $response->assertJsonPath('default_ai_model', 'claude-fable-5');
    }

    public function test_orchestrator_persists_agent_tool_trace_and_final_answer(): void
    {
        [$agent, $apps] = $this->connectedAgent();
        $data = ['nodes' => [['id' => 'router', 'type' => 'orchestrator', 'position' => ['x' => 0, 'y' => 0], 'config' => []], $agent, ...$apps],
            'edges' => [['source' => 'router', 'target' => 'agent'], ...array_map(fn (array $app): array => ['source' => 'agent', 'target' => $app['id']], $apps)]];
        $workflow = JarvisWorkflow::query()->create(['owner_key' => $agent['_owner_key'], 'name' => 'Agent tools', 'status' => 'draft', 'workflow_data' => $data]);
        AnonymousAgent::fake([$this->toolDecision('tracker', 'projects'), $this->final('Retrieved.')])->preventStrayPrompts();
        Http::preventStrayRequests();
        Http::fake(['https://93.184.215.14/api/jarvis/projects*' => Http::response(['data' => [['project_id' => 1]]])]);

        $run = app(WorkflowExecutor::class)->run($workflow, ['request' => 'Read projects.'], 'chat', chatRequest: true);

        $this->assertTrue($run['success'], $run['error'] ?? '');
        $this->assertSame('Retrieved.', $run['execution']->output_data['final']['result']['summary']);
        $this->assertSame('Not needed', $run['execution']->output_data['final']['trace']['available_tools'][1]['status']);
        Http::assertSentCount(1);
    }

    public function test_an_owner_can_approve_only_the_stored_action_once(): void
    {
        [$workflow, $run] = $this->pendingWrite();
        Http::preventStrayRequests();
        Http::fake(['https://93.184.215.14/configured' => Http::response(['saved' => true])]);
        $url = '/jarvis/workflows/'.$workflow->id.'/executions/'.$run['execution']->id.'/approve';

        $this->postJson($url, ['arguments' => ['url' => '/unreviewed']])->assertOk()->assertJsonPath('approval.status', 'success');
        $this->postJson($url)->assertUnprocessable();

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->method() === 'POST' && $request->url() === 'https://93.184.215.14/configured');
    }

    public function test_approval_is_scoped_to_the_workflow_owner(): void
    {
        [$workflow, $run] = $this->pendingWrite();
        $this->actingAs(User::factory()->create());
        Http::preventStrayRequests();

        $this->postJson('/jarvis/workflows/'.$workflow->id.'/executions/'.$run['execution']->id.'/approve')->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_changed_workflow_cannot_reuse_an_old_approval(): void
    {
        [$workflow, $run] = $this->pendingWrite();
        $data = $workflow->workflow_data;
        $data['nodes'][1]['config']['permissions']['write'] = false;
        $workflow->update(['workflow_data' => $data]);
        Http::preventStrayRequests();

        $this->postJson('/jarvis/workflows/'.$workflow->id.'/executions/'.$run['execution']->id.'/approve')->assertUnprocessable();

        Http::assertNothingSent();
    }

    public function test_expired_approval_cannot_execute(): void
    {
        [$workflow, $run] = $this->pendingWrite();
        $this->travel(31)->minutes();
        Http::preventStrayRequests();

        $this->postJson('/jarvis/workflows/'.$workflow->id.'/executions/'.$run['execution']->id.'/approve')->assertUnprocessable();

        Http::assertNothingSent();
    }

    public function test_dry_run_proposals_cannot_be_approved_for_real_execution(): void
    {
        [$workflow, $run] = $this->pendingWrite();
        $input = $run['execution']->input_data;
        $input['dry_run'] = true;
        $run['execution']->update(['input_data' => $input]);
        Http::preventStrayRequests();

        $this->postJson('/jarvis/workflows/'.$workflow->id.'/executions/'.$run['execution']->id.'/approve')->assertUnprocessable();

        Http::assertNothingSent();
    }

    public function test_registered_api_tools_can_activate_without_running_remote_actions(): void
    {
        [$agent, $apps] = $this->connectedAgent();
        $workflow = $this->workflowFor($agent, [$apps[0], $apps[1]]);
        $data = $workflow->workflow_data;
        array_unshift($data['nodes'], ['id' => 'schedule', 'type' => 'schedule_trigger', 'position' => ['x' => 0, 'y' => 0], 'config' => ['frequency' => 'daily', 'time' => '09:00']]);
        $data['edges'][] = ['source' => 'schedule', 'target' => 'router'];
        $workflow->update(['workflow_data' => $data]);
        Http::preventStrayRequests();

        $this->postJson('/jarvis/workflows/'.$workflow->id.'/activate')->assertOk();

        Http::assertNothingSent();
    }

    public function test_fixed_actions_also_require_permissions_before_writes(): void
    {
        [$agent, $apps] = $this->connectedAgent();
        $agent['config']['execution_mode'] = 'deterministic';
        $apps[0]['config']['values']['method'] = 'DELETE';
        $workflow = $this->workflowFor($agent, [$apps[0]]);
        AnonymousAgent::fake()->preventStrayPrompts();
        Http::preventStrayRequests();

        $run = app(WorkflowExecutor::class)->run($workflow, ['request' => 'Delete a record.'], 'chat', chatRequest: true);

        $this->assertFalse($run['success']);
        $this->assertStringContainsString('permission is disabled', $run['error']);
        Http::assertNothingSent();
        AnonymousAgent::assertNeverPrompted();
    }

    public function test_standalone_agent_does_not_automatically_execute_its_connected_apps(): void
    {
        [$agent, $apps] = $this->connectedAgent();
        $workflow = $this->workflowFor($agent, $apps);
        $data = $workflow->workflow_data;
        $data['nodes'] = array_slice($data['nodes'], 1);
        $data['edges'] = array_slice($data['edges'], 1);
        $workflow->update(['workflow_data' => $data]);
        AnonymousAgent::fake([$this->final('No external data needed for this explanation.')])->preventStrayPrompts();
        Http::preventStrayRequests();

        $run = app(WorkflowExecutor::class)->run($workflow, ['request' => 'Explain how to review delivery progress.'], 'chat', chatRequest: true);

        $this->assertTrue($run['success'], $run['error'] ?? '');
        $this->assertCount(1, $run['results']);
        Http::assertNothingSent();
    }

    public function test_selected_orchestrator_and_agent_models_share_one_key(): void
    {
        [$agent, $apps] = $this->connectedAgent(['model' => 'claude-haiku-4-5-20251001']);
        JarvisSetting::current()->update(['api_key_encrypted' => Crypt::encryptString('one-central-key')]);
        $workflow = $this->workflowFor($agent, $apps);
        $data = $workflow->workflow_data;
        $data['nodes'][0]['config']['model'] = 'claude-sonnet-5';
        $other = $agent;
        $other['id'] = 'secretary';
        $other['config']['model'] = 'claude-fable-5';
        $data['nodes'][] = $other;
        $data['edges'][] = ['source' => 'router', 'target' => 'secretary'];
        $workflow->update(['workflow_data' => $data]);
        AnonymousAgent::fake(['{"selected_node_id":"agent","reason":"Fits the task."}', $this->final('Done.')])->preventStrayPrompts();
        Http::preventStrayRequests();

        $run = app(WorkflowExecutor::class)->run($workflow, ['request' => 'Explain operations.'], 'chat', chatRequest: true);

        $this->assertTrue($run['success'], $run['error'] ?? '');
        AnonymousAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->model === 'claude-sonnet-5' && $prompt->provider->providerCredentials()['key'] === 'one-central-key');
        AnonymousAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->model === 'claude-haiku-4-5-20251001' && $prompt->provider->providerCredentials()['key'] === 'one-central-key');
        Http::assertNothingSent();
    }

    public function test_chat_delegation_uses_agent_reasoning_and_owned_tools(): void
    {
        [$agent, $apps] = $this->connectedAgent();
        $workflow = $this->workflowFor($agent, $apps);
        AnonymousAgent::fake([$this->toolDecision('metabase', 'search', ['query' => ['q' => 'expenses']]), $this->final('Report located.')])->preventStrayPrompts();
        Http::preventStrayRequests();
        Http::fake(['https://93.184.215.14/api/search*' => Http::response(['data' => [['id' => 42]]])]);

        $result = app(WorkflowAgentDelegation::class)->ask($agent['_owner_key'], $workflow->id, 'Break down expenses.');

        $this->assertTrue($result['success']);
        $this->assertSame('metabase', $result['trace']['decisions'][0]['tool_id']);
        Http::assertSentCount(1);
    }

    public function test_main_chat_delegates_an_expense_question_without_keyword_tool_routing(): void
    {
        config(['jarvis.test_mode' => false]);
        [$agent, $apps] = $this->connectedAgent();
        $workflow = $this->workflowFor($agent, $apps);
        JcAgent::fake([new ToolCall('delegate', 'AskWorkflowAgent', ['workflow_id' => $workflow->id]), 'Expense report located.'])->preventStrayPrompts();
        AnonymousAgent::fake([$this->toolDecision('metabase', 'search', ['query' => ['q' => 'expenses']]), $this->final('Expense report located.')])->preventStrayPrompts();
        Http::preventStrayRequests();
        Http::fake(['https://93.184.215.14/api/search*' => Http::response(['data' => [['id' => 42, 'name' => 'Expenses']]])]);

        $this->postJson('/jarvis/command', ['command' => 'Give me a breakdown of expenses this year.'])->assertOk();

        $trace = $workflow->executions()->firstOrFail()->output_data['final']['trace'];
        $this->assertSame('metabase', $trace['decisions'][0]['tool_id']);
        $this->assertSame('Not needed', $trace['available_tools'][0]['status']);
        Http::assertSentCount(1);
    }

    public function test_registered_future_tools_are_selected_from_their_metadata(): void
    {
        $agent = $this->agent();
        $executor = new class implements WorkflowAppExecutor
        {
            public function app(): string
            {
                return 'sap';
            }

            public function capabilities(): array
            {
                return ['actions' => ['query'], 'risk' => 'READ_ONLY', 'action_definitions' => ['query' => ['description' => 'Query SAP expenses by year', 'input_schema' => ['year' => 'positive integer']]]];
            }

            public function execute(array $node, bool $dryRun = false): array
            {
                return ['success' => true, 'year' => $node['config']['values']['year'], 'total' => 900];
            }
        };
        $registry = app(WorkflowAppExecutorRegistry::class);
        $registry->register($executor);
        $this->app->instance(WorkflowAppExecutorRegistry::class, $registry);
        $tool = ['id' => 'sap', 'type' => 'app_action', 'label' => 'SAP', 'config' => ['app' => 'sap', 'action' => 'query']];
        AnonymousAgent::fake([$this->toolDecision('sap', 'query', ['year' => 2026]), $this->final('SAP expenses total 900.')])->preventStrayPrompts();
        Http::preventStrayRequests();

        $result = $this->runAgent($agent, [$tool], 'Analyze expenses for 2026.');

        $this->assertSame('sap', $result['tools'][0]['tool_id']);
        $this->assertSame(2026, $result['tools'][0]['data']['year']);
        $this->assertSame(900, $result['tools'][0]['data']['total']);
        AnonymousAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('Query SAP expenses by year'));
        Http::assertNothingSent();
    }

    public function test_saving_an_outlook_draft_requires_review_and_does_not_send(): void
    {
        [$agent, $apps] = $this->connectedAgent();
        ApiConnection::query()->findOrFail($apps[2]['config']['values']['connection_id'])->update(['base_url' => 'https://graph.microsoft.com']);
        $workflow = $this->workflowFor($agent, [$apps[2]]);
        AnonymousAgent::fake([$this->toolDecision('outlook', 'save_draft', ['to' => 'management@example.com', 'subject' => 'Delays', 'body' => 'Please review.'])])->preventStrayPrompts();
        Http::preventStrayRequests();
        $http = $this->mock(WorkflowHttpService::class);
        $http->shouldReceive('validateUrl')->once()->with('https://graph.microsoft.com/v1.0/me/messages');
        $http->shouldReceive('requestOptions')->once()->with('https://graph.microsoft.com/v1.0/me/messages')->andReturn(['allow_redirects' => false]);
        Http::fake(['https://graph.microsoft.com/v1.0/me/messages' => Http::response(['id' => 'draft-123', 'isDraft' => true], 201)]);

        $run = app(WorkflowExecutor::class)->run($workflow, ['request' => 'Save this email as an Outlook draft.'], 'chat', chatRequest: true);
        $this->assertTrue($run['success'], $run['error'] ?? '');
        Http::assertNothingSent();
        $this->postJson('/jarvis/workflows/'.$workflow->id.'/executions/'.$run['execution']->id.'/approve')
            ->assertOk()->assertJsonPath('approval.result.remote_draft_created', true)->assertJsonPath('approval.result.sent', false);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->method() === 'POST' && $request['subject'] === 'Delays' && ! str_contains($request->url(), 'sendMail'));
    }

    public function test_connection_changes_invalidate_pending_approvals(): void
    {
        [$workflow, $run] = $this->pendingWrite();
        $connectionId = $workflow->workflow_data['nodes'][2]['config']['values']['connection_id'];
        ApiConnection::query()->findOrFail($connectionId)->update(['token' => 'replacement-credential']);
        Http::preventStrayRequests();

        $this->postJson('/jarvis/workflows/'.$workflow->id.'/executions/'.$run['execution']->id.'/approve')->assertUnprocessable();

        Http::assertNothingSent();
    }

    private function pendingWrite(): array
    {
        [$agent, $apps] = $this->connectedAgent(['permissions' => ['write' => true]]);
        $apps[0]['config']['values']['method'] = 'POST';
        $workflow = $this->workflowFor($agent, [$apps[0]]);
        AnonymousAgent::fake([$this->toolDecision('tracker', 'configured', ['body' => ['name' => 'Review me']])])->preventStrayPrompts();
        Http::preventStrayRequests();
        $run = app(WorkflowExecutor::class)->run($workflow, ['request' => 'Create the reviewed record.'], 'chat', chatRequest: true);
        $this->assertTrue($run['success'], $run['error'] ?? '');

        return [$workflow, $run];
    }

    private function workflowFor(array $agent, array $apps): JarvisWorkflow
    {
        return JarvisWorkflow::query()->create(['owner_key' => $agent['_owner_key'], 'name' => 'Agent tools', 'status' => 'draft', 'workflow_data' => [
            'nodes' => [['id' => 'router', 'type' => 'orchestrator', 'position' => ['x' => 0, 'y' => 0], 'config' => []], $agent, ...$apps],
            'edges' => [['source' => 'router', 'target' => 'agent'], ...array_map(fn (array $app): array => ['source' => 'agent', 'target' => $app['id']], $apps)],
        ]]);
    }

    private function agent(array $config = []): array
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return ['id' => 'agent', 'type' => 'ai_agent', 'label' => 'Operations Agent', '_owner_key' => 'user:'.$user->id, 'position' => ['x' => 100, 'y' => 0],
            'config' => [...['specialization' => 'operations', 'instruction' => 'Analyze company operations and use connected sources when needed.', 'execution_mode' => 'ai_assisted', 'fields' => ['summary']], ...$config]];
    }

    private function connectedAgent(array $config = []): array
    {
        $agent = $this->agent($config);
        $apps = [];
        foreach (['tracker' => 'MMC Tracker', 'metabase' => 'Metabase', 'outlook' => 'Outlook'] as $id => $name) {
            $connection = ApiConnection::factory()->create(['owner_key' => $agent['_owner_key'], 'name' => $name, 'token' => 'connection-test-secret']);
            $apps[] = ['id' => $id, 'type' => 'app_action', 'label' => $name, '_owner_key' => $agent['_owner_key'], 'position' => ['x' => 200, 'y' => 0],
                'config' => ['app' => $id === 'outlook' ? 'outlook' : 'custom_api', 'action' => $id === 'outlook' ? 'draft' : 'request', 'values' => ['connection_id' => $connection->id, 'method' => 'GET', 'url' => '/configured']]];
        }

        return [$agent, $apps];
    }

    private function runAgent(array $agent, array $apps, string $request): array
    {
        return app(WorkflowAgentRuntime::class)->run($agent, $apps, $request, fn (array $tool): array => app(WorkflowAppExecutorRegistry::class)->execute($tool));
    }

    private function toolDecision(string $id, string $action, array $arguments = []): string
    {
        return json_encode(['kind' => 'tool', 'tool_id' => $id, 'action' => $action, 'arguments' => $arguments, 'reason' => 'Relevant source for this task.'], JSON_THROW_ON_ERROR);
    }

    private function final(string $summary): string
    {
        return json_encode(['kind' => 'final', 'result' => ['summary' => $summary], 'tool_considerations' => array_map(fn (string $id): array => ['tool_id' => $id, 'relevant' => false, 'reason' => 'No additional call needed for this task.'], ['tracker', 'metabase', 'outlook'])], JSON_THROW_ON_ERROR);
    }
}
