<?php

namespace Tests\Feature\Http\Controllers;

use App\Jobs\ExecuteJarvisWorkflow;
use App\Models\EmailAccount;
use App\Models\JarvisWorkflow;
use App\Models\User;
use App\Models\WorkflowAgentTask;
use App\Services\GoDaddyEmailService;
use App\Services\Workflow\WorkflowEmailReader;
use App\Services\WorkflowAiService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class JarvisWorkflowControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_editor_page_renders_the_workflow_builder(): void
    {
        $response = $this->get('/jarvis/workflows');

        $response->assertOk()
            ->assertSeeText('Automation studio')
            ->assertSeeText('Create draft with AI');
    }

    public function test_create_request_returns_201_and_persists_a_draft_under_the_session_owner(): void
    {
        $payload = [
            'name' => 'Customer follow-up',
            'description' => 'A draft workflow.',
            'workflow_data' => ['nodes' => [], 'edges' => []],
        ];

        $response = $this->postJson('/jarvis/workflows', $payload);

        $response->assertCreated()
            ->assertJsonPath('workflow.name', 'Customer follow-up')
            ->assertJsonPath('workflow.status', 'draft');
        $this->assertDatabaseHas('jarvis_workflows', [
            'id' => $response->json('workflow.id'),
            'name' => 'Customer follow-up',
            'status' => 'draft',
        ]);
        $workflow = JarvisWorkflow::query()->findOrFail($response->json('workflow.id'));
        $this->assertSame(true, str_starts_with($workflow->owner_key, 'session:'));
    }

    public function test_workflow_save_rejects_credentials_inside_node_configuration(): void
    {
        $response = $this->postJson('/jarvis/workflows', [
            'name' => 'Credential test',
            'workflow_data' => [
                'nodes' => [[
                    'id' => 'orchestrator',
                    'type' => 'orchestrator',
                    'position' => ['x' => 100, 'y' => 100],
                    'config' => ['api_key' => 'do-not-store'],
                ]],
                'edges' => [],
            ],
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['workflow']);
        $this->assertDatabaseMissing('jarvis_workflows', ['name' => 'Credential test']);
    }

    public function test_anonymous_workflows_receive_a_persistent_owner_cookie(): void
    {
        $response = $this->postJson('/jarvis/workflows', [
            'name' => 'Remembered workflow',
            'workflow_data' => ['nodes' => [], 'edges' => []],
        ]);

        $response->assertCreated()->assertCookieNotExpired('jarvis_workflow_owner');
        $ownerId = $response->getCookie('jarvis_workflow_owner')?->getValue();

        $this->assertIsString($ownerId);
        $this->assertSame('session:'.$ownerId, JarvisWorkflow::query()->findOrFail($response->json('workflow.id'))->owner_key);
    }

    public function test_saved_workflow_restores_its_complete_graph_and_positions(): void
    {
        $workflowData = [
            'nodes' => [
                [
                    'id' => 'orchestrator',
                    'type' => 'orchestrator',
                    'label' => 'Orchestrator',
                    'position' => ['x' => 140, 'y' => 210],
                    'config' => ['instruction' => 'Route to the right agent.', 'routing' => 'auto'],
                ],
                [
                    'id' => 'hr-agent',
                    'type' => 'ai_agent',
                    'label' => 'HR Agent',
                    'position' => ['x' => 520, 'y' => 365],
                    'config' => ['specialization' => 'hr', 'handles' => 'Hiring and onboarding', 'require_approval' => true],
                ],
                [
                    'id' => 'gmail-action',
                    'type' => 'app_action',
                    'label' => 'Gmail',
                    'position' => ['x' => 910, 'y' => 480],
                    'config' => ['app' => 'gmail', 'action' => 'draft', 'values' => ['subject' => 'Interview follow-up']],
                ],
            ],
            'edges' => [
                [
                    'id' => 'edge_orchestrator_hr',
                    'source' => 'orchestrator',
                    'target' => 'hr-agent',
                    'metadata' => ['created_by' => 'auto'],
                ],
                [
                    'id' => 'edge_hr_gmail',
                    'source' => 'hr-agent',
                    'target' => 'gmail-action',
                    'metadata' => ['created_by' => 'manual'],
                ],
            ],
        ];
        $payload = [
            'name' => 'HR follow-up',
            'description' => 'Draft a follow-up for candidates.',
            'workflow_data' => $workflowData,
        ];

        $created = $this->postJson('/jarvis/workflows', $payload)->assertCreated();
        $workflowId = $created->json('workflow.id');
        $ownerId = $created->getCookie('jarvis_workflow_owner')?->getValue();
        $this->assertIsString($ownerId);
        $this->withCookie('jarvis_workflow_owner', $ownerId)->withCredentials();
        $loaded = $this->getJson('/jarvis/workflows/'.$workflowId);

        $loaded->assertOk()
            ->assertJsonPath('workflow.workflow_data', $workflowData)
            ->assertJsonPath('workflow.name', 'HR follow-up');
        $this->assertSame($workflowData, JarvisWorkflow::query()->findOrFail($workflowId)->workflow_data);
    }

    public function test_app_data_flow_edges_support_fan_out_fan_in_and_connection_settings(): void
    {
        $nodes = [
            ['id' => 'sheets', 'type' => 'app_action', 'position' => ['x' => 100, 'y' => 100], 'config' => ['app' => 'sheets', 'action' => 'read']],
            ['id' => 'tracker', 'type' => 'app_action', 'label' => 'MMC Tracker', 'position' => ['x' => 450, 'y' => 20], 'config' => ['app' => 'custom_api', 'action' => 'request']],
            ['id' => 'sap', 'type' => 'app_action', 'position' => ['x' => 450, 'y' => 220], 'config' => ['app' => 'sap', 'variant' => 'b1', 'action' => 'upsert']],
        ];
        $edges = [
            [
                'id' => 'sheets_tracker', 'source' => 'sheets', 'target' => 'tracker', 'edge_type' => 'data_flow',
                'config' => [
                    'trigger' => 'row_created_or_updated', 'mode' => 'upsert', 'duplicate_key' => 'row_id', 'on_error' => 'stop_branch',
                    'mapping' => ['project_id' => 'project_code', 'qty' => 'quantity'],
                ],
            ],
            ['id' => 'sheets_sap', 'source' => 'sheets', 'target' => 'sap', 'edge_type' => 'data_flow', 'config' => ['mapping' => ['ProjectCode' => 'project_code']]],
            ['id' => 'tracker_sap', 'source' => 'tracker', 'target' => 'sap', 'edge_type' => 'data_flow'],
        ];

        $response = $this->postJson('/jarvis/workflows', [
            'name' => 'Masterlist sync graph',
            'workflow_data' => ['nodes' => $nodes, 'edges' => $edges],
        ])->assertCreated();

        $this->assertSame($edges, $response->json('workflow.workflow_data.edges'));
    }

    public function test_workflow_with_unimplemented_data_flow_cannot_be_activated(): void
    {
        $created = $this->postJson('/jarvis/workflows', [
            'name' => 'Pending data sync',
            'workflow_data' => [
                'nodes' => [
                    ['id' => 'trigger', 'type' => 'webhook_trigger', 'position' => ['x' => 100, 'y' => 100], 'config' => []],
                    ['id' => 'source', 'type' => 'app_action', 'position' => ['x' => 400, 'y' => 100], 'config' => ['app' => 'sheets', 'action' => 'read']],
                    ['id' => 'destination', 'type' => 'app_action', 'position' => ['x' => 700, 'y' => 100], 'config' => ['app' => 'custom_api', 'action' => 'request']],
                ],
                'edges' => [
                    ['id' => 'trigger_source', 'source' => 'trigger', 'target' => 'source', 'edge_type' => 'trigger_flow'],
                    ['id' => 'source_destination', 'source' => 'source', 'target' => 'destination', 'edge_type' => 'data_flow'],
                ],
            ],
        ])->assertCreated();
        $workflowId = $created->json('workflow.id');
        $ownerId = $created->getCookie('jarvis_workflow_owner')?->getValue();
        $this->assertIsString($ownerId);
        $this->withCookie('jarvis_workflow_owner', $ownerId)->withCredentials();

        $this->postJson('/jarvis/workflows/'.$workflowId.'/activate')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['workflow']);
    }

    public function test_agent_status_uses_scoped_task_data_and_reports_only_owned_tools(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $workflow = JarvisWorkflow::query()->create([
            'owner_key' => 'user:'.$user->id,
            'name' => 'Operations Masterlist',
            'workflow_data' => [
                'nodes' => [
                    ['id' => 'operations', 'type' => 'ai_agent', 'label' => 'Operations Agent', 'config' => ['specialization' => 'operations', 'handles' => 'Inventory and deliveries']],
                    ['id' => 'sheets', 'type' => 'app_action', 'label' => 'Masterlist', 'config' => ['app' => 'sheets']],
                    ['id' => 'tracker', 'type' => 'app_action', 'label' => 'MMC Tracker', 'config' => ['app' => 'custom_api']],
                    ['id' => 'hr', 'type' => 'ai_agent', 'label' => 'HR Agent', 'config' => ['specialization' => 'hr']],
                    ['id' => 'hris', 'type' => 'app_action', 'label' => 'HRIS', 'config' => ['app' => 'custom_api']],
                ],
                'edges' => [
                    ['source' => 'operations', 'target' => 'sheets', 'edge_type' => 'agent_capability'],
                    ['source' => 'sheets', 'target' => 'tracker', 'edge_type' => 'data_flow'],
                    ['source' => 'hr', 'target' => 'hris', 'edge_type' => 'agent_capability'],
                ],
            ],
            'trigger_state' => [],
            'status' => 'error',
        ]);
        $execution = $workflow->executions()->create([
            'trigger_type' => 'webhook',
            'status' => 'failed',
            'started_at' => now(),
            'finished_at' => now(),
            'input_data' => [],
        ]);
        WorkflowAgentTask::query()->create([
            'workflow_id' => $workflow->id,
            'execution_id' => $execution->id,
            'agent_node_id' => 'operations',
            'task_type' => 'masterlist_sync',
            'status' => 'failed',
            'input_data' => ['record_id' => 'row-52'],
            'error_message' => 'SAP rejected the record.',
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $this->getJson('/jarvis/agents/status')
            ->assertOk()
            ->assertJsonPath('agents.0.label', 'Operations Agent')
            ->assertJsonPath('agents.0.status', 'needs_attention')
            ->assertJsonPath('agents.0.today.processed', 1)
            ->assertJsonPath('agents.0.today.failed', 1)
            ->assertJsonPath('agents.0.last_error', 'SAP rejected the record.')
            ->assertJsonPath('agents.0.tools', ['Masterlist', 'MMC Tracker'])
            ->assertJsonCount(2, 'agents');
    }

    public function test_deterministic_agent_skips_llm_calls_and_records_a_completed_task(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $mailbox = EmailAccount::query()->create([
            'name' => 'Primary mailbox',
            'email' => 'owner@example.com',
            'imap_host' => 'imap.example.com',
            'imap_username' => 'owner@example.com',
            'imap_password' => 'mail-secret',
            'smtp_host' => 'smtp.example.com',
            'smtp_username' => 'owner@example.com',
            'smtp_password' => 'smtp-secret',
            'is_default' => true,
            'is_active' => true,
        ]);
        $workflow = $this->orchestratedWorkflow($user, $mailbox->id, executionMode: 'deterministic');
        $ai = Mockery::mock(WorkflowAiService::class)->makePartial();
        $ai->shouldNotReceive('selectConnectedNode');
        $ai->shouldNotReceive('analyze');
        $this->app->instance(WorkflowAiService::class, $ai);

        $reader = Mockery::mock(WorkflowEmailReader::class);
        $reader->shouldNotReceive('unreadEmails');
        $this->app->instance(WorkflowEmailReader::class, $reader);

        $this->postJson('/jarvis/workflows/'.$workflow->id.'/test', ['request' => 'Read configured inbox data.'])
            ->assertOk()
            ->assertJsonPath('execution.status', 'success')
            ->assertJsonPath('execution.results.1.output.result.execution_mode', 'deterministic')
            ->assertJsonPath('execution.results.1.output.result.successful', 1)
            ->assertJsonPath('execution.results.1.output.result.tools.0.result.simulated', true);

        $this->assertDatabaseHas('jarvis_workflow_agent_tasks', [
            'workflow_id' => $workflow->id,
            'agent_node_id' => 'secretary',
            'status' => 'completed',
        ]);
    }

    public function test_selecting_a_saved_workflow_persists_it_as_the_last_used_workflow(): void
    {
        $payload = ['name' => 'Workflow', 'workflow_data' => ['nodes' => [], 'edges' => []]];
        $this->travelTo('2026-10-01 10:00:00');
        $firstCreated = $this->postJson('/jarvis/workflows', $payload)->assertCreated();
        $firstWorkflowId = $firstCreated->json('workflow.id');
        $ownerId = $firstCreated->getCookie('jarvis_workflow_owner')?->getValue();
        $this->assertIsString($ownerId);
        $this->withCookie('jarvis_workflow_owner', $ownerId)->withCredentials();
        $this->travelTo('2026-10-01 11:00:00');
        $secondWorkflowId = $this->postJson('/jarvis/workflows', $payload)->assertCreated()->json('workflow.id');
        $this->travelTo('2026-10-01 12:00:00');

        $selected = $this->postJson('/jarvis/workflows/'.$firstWorkflowId.'/use', []);

        $selected->assertOk()->assertJsonPath('workflow.id', $firstWorkflowId);
        $this->assertDatabaseHas('jarvis_workflows', [
            'id' => $firstWorkflowId,
            'last_used_at' => '2026-10-01 12:00:00',
            'updated_at' => '2026-10-01 10:00:00',
        ]);
        $this->getJson('/jarvis/workflows/data')
            ->assertOk()
            ->assertJsonPath('workflows.0.id', $firstWorkflowId)
            ->assertJsonPath('workflows.1.id', $secondWorkflowId);
        $this->travelBack();
    }

    public function test_request_cannot_read_a_workflow_owned_by_another_session(): void
    {
        $workflow = JarvisWorkflow::query()->create([
            'owner_key' => 'user:99999',
            'name' => 'Private workflow',
            'workflow_data' => ['nodes' => [], 'edges' => []],
            'trigger_state' => [],
            'status' => 'draft',
        ]);

        $response = $this->getJson('/jarvis/workflows/'.$workflow->id);

        $response->assertNotFound();
        $this->postJson('/jarvis/workflows/'.$workflow->id.'/use', [])->assertNotFound();
    }

    public function test_test_run_simulates_email_and_webhook_actions_without_sending_them(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $ownerKey = 'user:'.$user->id;
        $workflow = JarvisWorkflow::query()->create([
            'owner_key' => $ownerKey,
            'name' => 'Dry-run workflow',
            'workflow_data' => [
                'nodes' => [
                    [
                        'id' => 'incoming',
                        'type' => 'webhook_trigger',
                        'position' => ['x' => 100, 'y' => 100],
                        'config' => [],
                    ],
                    [
                        'id' => 'email',
                        'type' => 'send_email',
                        'position' => ['x' => 400, 'y' => 100],
                        'config' => ['to' => 'owner@example.com', 'subject' => 'Test', 'body' => 'Test only'],
                    ],
                    [
                        'id' => 'webhook',
                        'type' => 'webhook_action',
                        'position' => ['x' => 700, 'y' => 100],
                        'config' => ['url' => 'https://example.com/automation', 'method' => 'POST', 'payload' => ''],
                    ],
                ],
                'edges' => [
                    ['source' => 'incoming', 'target' => 'email'],
                    ['source' => 'email', 'target' => 'webhook'],
                ],
            ],
            'trigger_state' => [],
            'status' => 'draft',
        ]);
        $emailService = Mockery::mock(GoDaddyEmailService::class);
        $emailService->shouldReceive('canSendNewEmails')->once()->andReturn(true);
        $emailService->shouldNotReceive('sendEmail');
        $this->app->instance(GoDaddyEmailService::class, $emailService);
        Http::preventStrayRequests();

        $response = $this->postJson('/jarvis/workflows/'.$workflow->id.'/test', [
            'test_body' => 'A sample customer event.',
        ]);

        $response->assertOk()
            ->assertJsonPath('execution.status', 'success')
            ->assertJsonPath('execution.results.1.status', 'simulated')
            ->assertJsonPath('execution.results.2.status', 'simulated');
        $this->assertDatabaseHas('jarvis_workflow_executions', [
            'workflow_id' => $workflow->id,
            'status' => 'success',
        ]);
    }

    public function test_operations_workflow_test_runs_deterministic_steps_and_preserves_the_saved_graph(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        Http::preventStrayRequests();
        $workflow = $this->operationsTestWorkflow($user, 'success');
        $savedGraph = $workflow->workflow_data;

        $response = $this->postJson('/jarvis/workflows/'.$workflow->id.'/test', [
            'request' => json_encode([
                'project_code' => 'PRJ-001', 'item_code' => 'ITEM-001', 'quantity' => 100,
                'school_id' => 'SCH-001', 'dr_number' => 'DR-001',
            ], JSON_THROW_ON_ERROR),
        ]);

        $response->assertOk()
            ->assertJsonPath('execution.status', 'success')
            ->assertJsonPath('execution.results.0.node_id', 'masterlist-trigger')
            ->assertJsonPath('execution.results.2.node_id', 'required-fields')
            ->assertJsonPath('execution.results.2.output.matched', true)
            ->assertJsonPath('execution.results.4.output.app', 'mmc_tracker')
            ->assertJsonPath('execution.results.4.status', 'simulated')
            ->assertJsonPath('execution.results.5.output.app', 'sap')
            ->assertJsonPath('execution.results.5.status', 'simulated')
            ->assertJsonPath('execution.results.6.output.status', 'completed');
        $this->assertSame($savedGraph, $response->json('execution.input_data.workflow_graph'));
        $this->assertSame($savedGraph, $workflow->fresh()->workflow_data);
        $this->assertDatabaseCount('jarvis_workflow_execution_steps', 7);
    }

    public function test_operations_test_run_records_a_mock_sap_failure_and_completes_the_other_branch(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $workflow = $this->operationsTestWorkflow($user, 'fail');

        $response = $this->postJson('/jarvis/workflows/'.$workflow->id.'/test', [
            'request' => json_encode(['project_code' => 'PRJ-001', 'item_code' => 'ITEM-001', 'quantity' => 100], JSON_THROW_ON_ERROR),
        ]);

        $response->assertOk()
            ->assertJsonPath('execution.status', 'success_with_errors')
            ->assertJsonPath('execution.results.4.status', 'simulated')
            ->assertJsonPath('execution.results.5.status', 'failed')
            ->assertJsonPath('execution.results.6.output.status', 'completed');
        $this->assertDatabaseHas('jarvis_workflow_execution_steps', [
            'execution_id' => $response->json('execution.id'), 'node_id' => 'sap', 'status' => 'failed',
        ]);
        $this->assertSame('draft', $workflow->fresh()->status);
    }

    public function test_manual_test_routes_only_through_connected_agent_and_email_app_and_persists_steps(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $mailbox = EmailAccount::query()->create([
            'name' => 'Primary mailbox',
            'email' => 'owner@example.com',
            'imap_host' => 'imap.example.com',
            'imap_username' => 'owner@example.com',
            'imap_password' => 'mail-secret',
            'smtp_host' => 'smtp.example.com',
            'smtp_username' => 'owner@example.com',
            'smtp_password' => 'smtp-secret',
            'is_default' => true,
            'is_active' => true,
        ]);
        $workflow = $this->orchestratedWorkflow($user, $mailbox->id);

        $ai = Mockery::mock(WorkflowAiService::class)->makePartial();
        $ai->shouldReceive('nextAgentAction')->twice()->andReturn(
            ['kind' => 'tool', 'tool_id' => 'gmail', 'action' => 'configured', 'arguments' => [], 'reason' => 'Read unread email.'],
            ['kind' => 'final', 'result' => ['summary' => 'One unread message from the customer.']],
        );
        $this->app->instance(WorkflowAiService::class, $ai);

        $reader = Mockery::mock(WorkflowEmailReader::class);
        $reader->shouldNotReceive('unreadEmails');
        $this->app->instance(WorkflowEmailReader::class, $reader);

        $response = $this->postJson('/jarvis/workflows/'.$workflow->id.'/test', [
            'request' => 'Check my unread emails.',
        ]);

        $response->assertOk()
            ->assertJsonPath('execution.status', 'success')
            ->assertJsonPath('execution.results.0.node_id', 'orchestrator')
            ->assertJsonPath('execution.results.1.node_id', 'secretary')
            ->assertJsonPath('execution.results.1.output.result.summary', 'One unread message from the customer.')
            ->assertJsonPath('execution.results.2.node_id', 'gmail')
            ->assertJsonPath('execution.results.2.output.count', 1)
            ->assertJsonPath('execution.results.2.output.simulated', true)
            ->assertJsonPath('execution.results.2.output.emails.0.subject', 'Sample workflow test email');
        $this->assertDatabaseCount('jarvis_workflow_execution_steps', 3);
        $this->assertDatabaseHas('jarvis_workflow_execution_steps', [
            'node_id' => 'gmail',
            'status' => 'success',
            'execution_order' => 3,
        ]);
        $this->assertDatabaseHas('jarvis_workflow_agent_tasks', [
            'workflow_id' => $workflow->id,
            'agent_node_id' => 'secretary',
            'status' => 'completed',
        ]);
        $this->assertSame($workflow->workflow_data, $response->json('execution.input_data.workflow_graph'));
    }

    public function test_disconnected_app_is_never_offered_to_the_selected_agent(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $workflow = $this->orchestratedWorkflow($user, null, connectedApp: false);

        $ai = Mockery::mock(WorkflowAiService::class)->makePartial();
        $ai->shouldNotReceive('selectConnectedNode');
        $ai->shouldNotReceive('analyze');
        $ai->shouldReceive('nextAgentAction')->once()->andReturn(['kind' => 'final', 'result' => ['summary' => 'No email tool is connected.']]);
        $this->app->instance(WorkflowAiService::class, $ai);

        $this->postJson('/jarvis/workflows/'.$workflow->id.'/test', ['request' => 'Check my unread emails.'])
            ->assertOk()
            ->assertJsonPath('execution.status', 'success')
            ->assertJsonPath('execution.results.1.output.result.summary', 'No email tool is connected.');
        $this->assertDatabaseMissing('jarvis_workflow_execution_steps', ['node_id' => 'gmail']);
    }

    public function test_test_run_uses_mock_email_data_without_a_configured_mailbox(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $workflow = $this->orchestratedWorkflow($user, null);

        $ai = Mockery::mock(WorkflowAiService::class)->makePartial();
        $ai->shouldReceive('nextAgentAction')->twice()->andReturn(
            ['kind' => 'tool', 'tool_id' => 'gmail', 'action' => 'configured', 'arguments' => [], 'reason' => 'Read unread email.'],
            ['kind' => 'final', 'result' => ['summary' => 'Mock email summarized.']],
        );
        $this->app->instance(WorkflowAiService::class, $ai);
        $reader = Mockery::mock(WorkflowEmailReader::class);
        $reader->shouldNotReceive('unreadEmails');
        $this->app->instance(WorkflowEmailReader::class, $reader);

        $response = $this->postJson('/jarvis/workflows/'.$workflow->id.'/test', ['request' => 'Check my unread emails.']);

        $response->assertOk()
            ->assertJsonPath('execution.status', 'success')
            ->assertJsonPath('execution.results.2.output.simulated', true)
            ->assertJsonPath('execution.results.2.output.emails.0.uid', 'TEST-EMAIL-001');
    }

    public function test_ai_cannot_select_an_app_node_that_is_not_connected_to_the_agent(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $workflow = $this->orchestratedWorkflow($user, null);

        $ai = Mockery::mock(WorkflowAiService::class)->makePartial();
        $ai->shouldReceive('nextAgentAction')->once()->andReturn(
            ['kind' => 'tool', 'tool_id' => 'unconnected-id', 'action' => 'configured', 'arguments' => [], 'reason' => 'Invalid choice.'],
        );
        $this->app->instance(WorkflowAiService::class, $ai);
        $reader = Mockery::mock(WorkflowEmailReader::class);
        $reader->shouldNotReceive('unreadEmails');
        $this->app->instance(WorkflowEmailReader::class, $reader);

        $this->postJson('/jarvis/workflows/'.$workflow->id.'/test', ['request' => 'Check my unread emails.'])
            ->assertUnprocessable()
            ->assertJsonPath('execution.status', 'failed');
        $this->assertDatabaseHas('jarvis_workflow_execution_steps', [
            'node_id' => 'secretary',
            'status' => 'failed',
        ]);
    }

    private function orchestratedWorkflow(User $user, ?int $mailboxId, bool $connectedApp = true, string $executionMode = 'ai_assisted'): JarvisWorkflow
    {
        $appConfig = ['app' => 'gmail', 'action' => 'read', 'values' => ['connection' => $mailboxId]];

        return JarvisWorkflow::query()->create([
            'owner_key' => 'user:'.$user->id,
            'name' => 'Unread email workflow',
            'workflow_data' => [
                'nodes' => [
                    ['id' => 'orchestrator', 'type' => 'orchestrator', 'label' => 'Orchestrator', 'position' => ['x' => 100, 'y' => 100], 'config' => []],
                    ['id' => 'secretary', 'type' => 'ai_agent', 'label' => 'Secretary agent', 'position' => ['x' => 400, 'y' => 100], 'config' => ['instruction' => 'Summarize unread emails.', 'handles' => 'Email, calendar', 'fields' => ['summary'], 'execution_mode' => $executionMode]],
                    ['id' => 'gmail', 'type' => 'app_action', 'label' => 'Gmail', 'position' => ['x' => 700, 'y' => 100], 'config' => $appConfig],
                ],
                'edges' => array_values(array_filter([
                    ['source' => 'orchestrator', 'target' => 'secretary'],
                    $connectedApp ? ['source' => 'secretary', 'target' => 'gmail'] : null,
                ])),
            ],
            'trigger_state' => [],
            'status' => 'draft',
        ]);
    }

    private function operationsTestWorkflow(User $user, string $sapBehavior): JarvisWorkflow
    {
        $nodes = [
            ['id' => 'masterlist-trigger', 'type' => 'manual_trigger', 'label' => 'Operation Masterlist', 'position' => ['x' => 50, 'y' => 260], 'config' => []],
            ['id' => 'operations-agent', 'type' => 'ai_agent', 'label' => 'Operations Agent', 'position' => ['x' => 350, 'y' => 260], 'config' => ['specialization' => 'operations', 'execution_mode' => 'deterministic', 'task_type' => 'workflow_execution']],
            ['id' => 'required-fields', 'type' => 'condition', 'label' => 'Required fields valid?', 'position' => ['x' => 660, 'y' => 260], 'config' => ['field' => 'result.quantity', 'operator' => 'greater_than', 'value' => '0']],
            ['id' => 'field-map', 'type' => 'transform', 'label' => 'Map masterlist fields', 'position' => ['x' => 960, 'y' => 170], 'config' => ['mappings' => ['project_code' => 'data.result.project_code', 'item_code' => 'data.result.item_code', 'quantity' => 'data.result.quantity', 'school_id' => 'data.result.school_id', 'dr_number' => 'data.result.dr_number']]],
            ['id' => 'tracker', 'type' => 'app_action', 'label' => 'MMC Tracker', 'position' => ['x' => 1280, 'y' => 120], 'config' => ['app' => 'mmc_tracker', 'action' => 'upsert', 'values' => [], 'test_behavior' => 'success']],
            ['id' => 'sap', 'type' => 'app_action', 'label' => 'SAP Business One', 'position' => ['x' => 1280, 'y' => 390], 'config' => ['app' => 'sap', 'action' => 'create', 'values' => ['resource' => 'Deliveries'], 'test_behavior' => $sapBehavior]],
            ['id' => 'record-result', 'type' => 'output', 'label' => 'Record result', 'position' => ['x' => 1600, 'y' => 260], 'config' => []],
        ];
        $edge = static fn (string $source, string $target, string $type, array $config = []): array => [
            'id' => $source.'_'.$target, 'source' => $source, 'target' => $target,
            'edge_type' => $type, 'config' => $config,
        ];

        return JarvisWorkflow::query()->create([
            'owner_key' => 'user:'.$user->id,
            'name' => 'Operations masterlist test',
            'workflow_data' => [
                'nodes' => $nodes,
                'edges' => [
                    $edge('masterlist-trigger', 'operations-agent', 'trigger_flow'),
                    $edge('operations-agent', 'required-fields', 'agent_capability'),
                    $edge('required-fields', 'field-map', 'conditional_flow', ['branch' => 'true']),
                    $edge('required-fields', 'record-result', 'conditional_flow', ['branch' => 'false']),
                    $edge('field-map', 'tracker', 'data_flow', ['on_error' => 'continue', 'mapping' => []]),
                    $edge('field-map', 'sap', 'data_flow', ['on_error' => 'continue', 'mapping' => []]),
                    $edge('tracker', 'record-result', 'data_flow', ['on_error' => 'continue', 'mapping' => []]),
                    $edge('sap', 'record-result', 'data_flow', ['on_error' => 'continue', 'mapping' => []]),
                ],
            ],
            'status' => 'draft',
        ]);
    }

    public function test_webhook_request_returns_202_and_queues_the_owner_workflow(): void
    {
        $token = str_repeat('a', 48);
        $workflow = $this->activeWebhookWorkflow($token);
        Queue::fake([ExecuteJarvisWorkflow::class]);

        $response = $this->postJson('/api/jarvis/workflows/'.$workflow->id.'/webhook/'.$token, [
            'event' => 'customer.created',
        ]);

        $response->assertAccepted()
            ->assertJsonPath('success', true);
        Queue::assertPushed(ExecuteJarvisWorkflow::class, function (ExecuteJarvisWorkflow $job) use ($workflow): bool {
            return $job->workflowId === $workflow->id
                && $job->ownerKey === $workflow->owner_key
                && $job->triggerType === 'webhook';
        });
    }

    public function test_webhook_request_returns_404_for_an_invalid_token(): void
    {
        $workflow = $this->activeWebhookWorkflow(str_repeat('a', 48));
        Queue::fake([ExecuteJarvisWorkflow::class]);

        $response = $this->postJson('/api/jarvis/workflows/'.$workflow->id.'/webhook/'.str_repeat('b', 48), [
            'event' => 'customer.created',
        ]);

        $response->assertNotFound();
        Queue::assertNothingPushed();
    }

    private function activeWebhookWorkflow(string $token): JarvisWorkflow
    {
        return JarvisWorkflow::query()->create([
            'owner_key' => 'user:99999',
            'name' => 'Webhook workflow',
            'workflow_data' => [
                'nodes' => [[
                    'id' => 'incoming',
                    'type' => 'webhook_trigger',
                    'position' => ['x' => 100, 'y' => 100],
                    'config' => [],
                ]],
                'edges' => [],
            ],
            'trigger_state' => [],
            'status' => 'active',
            'webhook_secret' => $token,
        ]);
    }
}
