<?php

namespace Tests\Feature\Services;

use App\Jobs\ExecuteJarvisWorkflow;
use App\Models\JarvisWorkflow;
use App\Models\User;
use App\Services\GoDaddyEmailService;
use App\Services\WorkflowSchedulerService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class WorkflowSchedulerServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_due_schedule_dispatches_a_job_and_advances_the_next_run(): void
    {
        $user = User::factory()->create();
        $workflow = JarvisWorkflow::query()->create([
            'owner_key' => 'user:'.$user->id,
            'name' => 'Hourly schedule',
            'workflow_data' => [
                'nodes' => [[
                    'id' => 'timer',
                    'type' => 'schedule_trigger',
                    'position' => ['x' => 100, 'y' => 100],
                    'config' => [
                        'frequency' => 'hourly',
                        'minute' => 0,
                        'timezone' => 'UTC',
                    ],
                ]],
                'edges' => [],
            ],
            'trigger_state' => [],
            'status' => 'active',
            'next_run_at' => now()->subMinute(),
        ]);
        Queue::fake([ExecuteJarvisWorkflow::class]);

        app(WorkflowSchedulerService::class)->runDue();

        Queue::assertPushed(ExecuteJarvisWorkflow::class, function (ExecuteJarvisWorkflow $job) use ($workflow): bool {
            return $job->workflowId === $workflow->id
                && $job->ownerKey === $workflow->owner_key
                && $job->triggerType === 'schedule';
        });
        $this->assertTrue($workflow->fresh()->next_run_at->isFuture());
    }

    public function test_new_matching_email_dispatches_a_job_and_records_the_uid(): void
    {
        $user = User::factory()->create();
        $workflow = JarvisWorkflow::query()->create([
            'owner_key' => 'user:'.$user->id,
            'name' => 'Invoice email trigger',
            'workflow_data' => [
                'nodes' => [[
                    'id' => 'email-trigger',
                    'type' => 'email_trigger',
                    'position' => ['x' => 100, 'y' => 100],
                    'config' => ['subject_contains' => 'invoice'],
                ]],
                'edges' => [],
            ],
            'trigger_state' => ['seen_uids' => []],
            'status' => 'active',
        ]);
        $emailService = Mockery::mock(GoDaddyEmailService::class);
        $emailService->shouldReceive('isConfigured')->once()->andReturn(true);
        $emailService->shouldReceive('latestEmails')->once()->with(50)->andReturn([
            'success' => true,
            'emails' => [[
                'uid' => 42,
                'from' => 'billing@example.com',
                'from_name' => 'Billing',
                'subject' => 'Invoice 42',
                'date' => '2026-09-29T10:00:00Z',
            ]],
        ]);
        $emailService->shouldReceive('getEmail')->once()->with(42)->andReturn([
            'success' => true,
            'message' => [
                'uid' => 42,
                'from' => 'billing@example.com',
                'from_name' => 'Billing',
                'to' => 'owner@example.com',
                'subject' => 'Invoice 42',
                'date' => '2026-09-29T10:00:00Z',
                'body' => 'Please review invoice 42.',
            ],
        ]);
        $this->app->instance(GoDaddyEmailService::class, $emailService);
        Queue::fake([ExecuteJarvisWorkflow::class]);

        app(WorkflowSchedulerService::class)->runDue();

        Queue::assertPushed(ExecuteJarvisWorkflow::class, function (ExecuteJarvisWorkflow $job) use ($workflow): bool {
            return $job->workflowId === $workflow->id
                && $job->ownerKey === $workflow->owner_key
                && $job->triggerType === 'email'
                && $job->payload['uid'] === '42'
                && $job->payload['body'] === 'Please review invoice 42.';
        });
        $this->assertSame(['42'], $workflow->fresh()->trigger_state['seen_uids']);
    }
}
