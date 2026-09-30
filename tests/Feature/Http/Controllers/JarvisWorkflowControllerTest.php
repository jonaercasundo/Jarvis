<?php

namespace Tests\Feature\Http\Controllers;

use App\Jobs\ExecuteJarvisWorkflow;
use App\Models\JarvisWorkflow;
use App\Models\User;
use App\Services\GoDaddyEmailService;
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
