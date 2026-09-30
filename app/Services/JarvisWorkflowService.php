<?php

namespace App\Services;

use App\Models\JarvisSetting;
use App\Models\JarvisWorkflow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class JarvisWorkflowService
{
    public function __construct(
        private WorkflowDefinition $definition,
        private GoDaddyEmailService $emailService,
        private WorkflowHttpService $http,
    ) {}

    public function list(string $ownerKey): array
    {
        return JarvisWorkflow::query()
            ->where('owner_key', $ownerKey)
            ->latest('updated_at')
            ->get()
            ->map(fn (JarvisWorkflow $workflow) => $this->publicData($workflow))
            ->all();
    }

    public function create(string $ownerKey, string $name, ?string $description, array $data): JarvisWorkflow
    {
        $this->definition->validate($data);

        return JarvisWorkflow::create([
            'owner_key' => $ownerKey,
            'name' => trim($name),
            'description' => $description,
            'workflow_data' => $data,
            'trigger_state' => [],
            'status' => 'draft',
        ]);
    }

    public function update(JarvisWorkflow $workflow, string $name, ?string $description, array $data): JarvisWorkflow
    {
        $this->definition->validate($data);

        $workflow->update([
            'name' => trim($name),
            'description' => $description,
            'workflow_data' => $data,
            'status' => 'draft',
            'webhook_secret' => null,
            'next_run_at' => null,
        ]);

        return $workflow->fresh();
    }

    public function activate(JarvisWorkflow $workflow): array
    {
        if ($workflow->status === 'active') {
            throw ValidationException::withMessages(['workflow' => 'This workflow is already active. Pause it before activating again.']);
        }
        if ($workflow->status === 'error') {
            throw ValidationException::withMessages(['workflow' => 'Save the workflow again before activating it.']);
        }

        $data = $workflow->workflow_data ?? [];
        $this->definition->validate($data, forActivation: true);

        $trigger = $this->triggerNode($workflow);
        $state = $workflow->trigger_state ?? [];
        $secret = null;

        foreach ($data['nodes'] as $node) {
            if (($node['type'] ?? null) === 'send_email' && ! $this->emailService->canSendNewEmails()) {
                throw ValidationException::withMessages(['workflow' => 'Enable “Send new emails” in JARVIS Settings → Scope before activating this workflow.']);
            }
            if (($node['type'] ?? null) === 'jarvis_ai' && ! in_array('analysis', JarvisSetting::current()->allowed_actions ?? [], true)) {
                throw ValidationException::withMessages(['workflow' => 'Enable AI analysis in JARVIS Settings → Scope before activating this workflow.']);
            }
            if (($node['type'] ?? null) === 'webhook_action') {
                try {
                    $this->http->validateUrl((string) ($node['config']['url'] ?? ''));
                } catch (\RuntimeException $exception) {
                    throw ValidationException::withMessages(['workflow' => $exception->getMessage()]);
                }
            }
        }

        if ($trigger['type'] === 'webhook_trigger') {
            $secret = Str::random(48);
            $workflow->webhook_secret = $secret;
        } elseif ($trigger['type'] === 'email_trigger') {
            if (! $this->emailService->isConfigured()) {
                throw ValidationException::withMessages(['workflow' => 'Connect a mailbox in Settings → Email before activating an email trigger.']);
            }

            $baseline = $this->emailService->latestEmails(50);
            if (! ($baseline['success'] ?? false)) {
                throw ValidationException::withMessages(['workflow' => $baseline['error'] ?? 'JARVIS could not read the mailbox. Check the email permissions in Settings.']);
            }

            $state['seen_uids'] = array_values(array_map(
                'strval',
                array_column($baseline['emails'] ?? [], 'uid'),
            ));
        }

        $workflow->update([
            'status' => 'active',
            'trigger_state' => $state,
            'next_run_at' => $trigger['type'] === 'schedule_trigger' ? $this->nextScheduleAt($trigger['config'] ?? []) : null,
            'webhook_secret' => $secret ?? $workflow->webhook_secret,
        ]);

        return [
            'workflow' => $workflow->fresh(),
            'webhook_url' => $secret ? $this->webhookUrl($workflow->fresh(), $secret) : null,
        ];
    }

    public function pause(JarvisWorkflow $workflow): JarvisWorkflow
    {
        $workflow->update([
            'status' => 'paused',
            'next_run_at' => null,
        ]);

        return $workflow->fresh();
    }

    public function rotateWebhookSecret(JarvisWorkflow $workflow): array
    {
        $trigger = $this->triggerNode($workflow);
        if (($trigger['type'] ?? null) !== 'webhook_trigger') {
            throw ValidationException::withMessages(['workflow' => 'Add a Webhook trigger to use a webhook URL.']);
        }

        if ($workflow->status !== 'active') {
            throw ValidationException::withMessages(['workflow' => 'Activate this workflow before creating its webhook URL.']);
        }

        $secret = Str::random(48);
        $workflow->update(['webhook_secret' => $secret]);

        return ['webhook_url' => $this->webhookUrl($workflow->fresh(), $secret)];
    }

    public function findOwned(string $ownerKey, int $id): JarvisWorkflow
    {
        return JarvisWorkflow::query()
            ->where('owner_key', $ownerKey)
            ->findOrFail($id);
    }

    public function findById(int $id): ?JarvisWorkflow
    {
        return JarvisWorkflow::query()->find($id);
    }

    public function delete(JarvisWorkflow $workflow): void
    {
        $workflow->delete();
    }

    public function executions(JarvisWorkflow $workflow, int $limit = 30): array
    {
        return $workflow->executions()
            ->latest('id')
            ->limit(min(max($limit, 1), 100))
            ->get()
            ->map(fn ($execution) => [
                'id' => $execution->id,
                'trigger_type' => $execution->trigger_type,
                'status' => $execution->status,
                'started_at' => $execution->started_at?->toIso8601String(),
                'finished_at' => $execution->finished_at?->toIso8601String(),
                'input_data' => $execution->input_data ?? [],
                'output_data' => $execution->output_data ?? [],
                'error_message' => $execution->error_message,
            ])
            ->all();
    }

    public function publicData(JarvisWorkflow $workflow): array
    {
        return [
            'id' => $workflow->id,
            'name' => $workflow->name,
            'description' => $workflow->description,
            'workflow_data' => $workflow->workflow_data ?? ['nodes' => [], 'edges' => []],
            'status' => $workflow->status,
            'last_run_at' => $workflow->last_run_at?->toIso8601String(),
            'next_run_at' => $workflow->next_run_at?->toIso8601String(),
            'has_webhook' => filled($workflow->webhook_secret),
            'updated_at' => $workflow->updated_at?->toIso8601String(),
        ];
    }

    public function triggerNode(JarvisWorkflow $workflow): array
    {
        foreach ($workflow->workflow_data['nodes'] ?? [] as $node) {
            if (app(WorkflowNodeCatalog::class)->isTrigger($node['type'] ?? '')) {
                return $node;
            }
        }

        throw ValidationException::withMessages(['workflow' => 'Add a trigger to this workflow.']);
    }

    public function nextScheduleAt(array $config): Carbon
    {
        $timezone = $config['timezone'] ?? config('app.timezone', 'UTC');
        $now = Carbon::now($timezone)->startOfMinute();
        $frequency = $config['frequency'] ?? 'daily';

        if ($frequency === 'hourly') {
            $minute = (int) ($config['minute'] ?? 0);
            $next = $now->copy()->minute($minute)->second(0);
            if ($next->lessThanOrEqualTo($now)) {
                $next->addHour();
            }

            return $next;
        }

        [$hour, $minute] = array_map('intval', explode(':', $config['time'] ?? '09:00'));
        $next = $now->copy()->setTime($hour, $minute);

        if ($frequency === 'weekly') {
            $targetDay = $config['day'] ?? 'monday';
            for ($i = 0; $i < 8; $i++) {
                if ($next->isSameDay($now) && $next->lessThanOrEqualTo($now)) {
                    $next->addWeek();
                }
                if (strtolower($next->englishDayOfWeek) === $targetDay && $next->greaterThan($now)) {
                    return $next;
                }
                $next->addDay()->setTime($hour, $minute);
            }

            return $next;
        }

        if ($next->lessThanOrEqualTo($now)) {
            $next->addDay();
        }

        return $next;
    }

    private function webhookUrl(JarvisWorkflow $workflow, string $secret): string
    {
        return rtrim(config('app.url'), '/').'/api/jarvis/workflows/'.$workflow->id.'/webhook/'.$secret;
    }
}
